<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** ADR-036 stage1. A nomination is NOT financial approval, issuance or PO data delegation. */
final class PurchaseOrderExecutionAccessService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $scope,
        private readonly ProjectArchiveService $archives,
        private readonly PurchaseOrderAccessService $draftAccess,
    ) {}

    private function buyers(Project $project)
    {
        return Company::whereIn('id', DB::table('project_company')->where('project_id', $project->id)->select('company_id'))
            ->whereIn('tenant_type', ['OWN', 'EPC', 'SUB'])->orderBy('code')->get();
    }

    private function options(Project $project): array
    {
        $members = ProjectMembership::where('project_id', $project->id)->get()->keyBy('user_id');
        $companies = Company::whereIn('id', DB::table('project_company')->where('project_id', $project->id)->select('company_id'))->pluck('code', 'id');
        $people = $this->tenant->forIdentityLookup(fn () => User::whereIn('id', $members->keys())->whereIn('company_id', $companies->keys())
            ->where('is_active', true)->get(['id', 'name', 'company_id']));
        $roles = Role::all()->keyBy('code');
        $options = [];
        foreach ($people as $person) {
            $options['user:'.$person->id] = $person->name.' · '.$companies[$person->company_id];
            foreach ($members[$person->id]->role_codes ?? [] as $code) {
                if (isset($roles[$code])) {
                    $options['role:'.$person->company_id.':'.$code] = $roles[$code]->name(app()->getLocale()).' · '.$companies[$person->company_id];
                }
            }
        }

        return $options;
    }

    /** Read only within caller's existing tenant/project scope. No delegated PO reads. */
    public function dashboard(Project $project, User $actor): array
    {
        $canConfigure = $this->draftAccess->canConfigure($project, $actor);
        $rows = [];
        foreach ($this->buyers($project) as $buyer) {
            $history = DB::table('po_execution_policies')->where('project_id', $project->id)->where('buyer_company_id', $buyer->id)->orderByDesc('version')->limit(20)->get();
            $current = $history->first();
            $rows[] = ['buyer' => $buyer, 'current' => $current, 'history' => $history,
                'finance' => $current ? json_decode($current->finance_selectors, true, 512, JSON_THROW_ON_ERROR) : [],
                'issue' => $current ? json_decode($current->issue_selectors, true, 512, JSON_THROW_ON_ERROR) : [],
                'workspace' => $this->nominationId($project, $actor, $buyer->id, 'finance') !== null || $this->nominationId($project, $actor, $buyer->id, 'issue') !== null];
        }

        return ['canConfigure' => $canConfigure, 'options' => $canConfigure ? $this->options($project) : [], 'buyers' => $rows];
    }

    public function configure(Project $project, User $actor, array $input): void
    {
        $this->scope->assertAccess($project, $actor);
        $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $input): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['po-entry:'.$project->id]);
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->firstOrFail();
            abort_unless($this->draftAccess->canConfigure($project, $fresh), 403);
            $this->archives->assertWritable($project);
            Validator::make(['execution_access' => $input], [
                'execution_access' => ['array:buyer_company_id,version,finance_selectors,issue_selectors,suspend,reason'],
                'execution_access.buyer_company_id' => ['required', 'uuid'], 'execution_access.version' => ['required', 'integer', 'min:0'],
                'execution_access.finance_selectors' => ['sometimes', 'array', 'max:100'], 'execution_access.finance_selectors.*' => ['required', 'string', 'max:160', 'distinct'],
                'execution_access.issue_selectors' => ['sometimes', 'array', 'max:100'], 'execution_access.issue_selectors.*' => ['required', 'string', 'max:160', 'distinct'],
                'execution_access.suspend' => ['required', 'boolean'], 'execution_access.reason' => ['required', 'string', 'max:4000'],
            ])->validate();
            if (! $this->buyers($project)->contains('id', $input['buyer_company_id'])) {
                $this->fail('buyer_invalid');
            }
            $current = DB::table('po_execution_policies')->where('project_id', $project->id)->where('buyer_company_id', $input['buyer_company_id'])->orderByDesc('version')->first();
            if ((int) $input['version'] !== (int) ($current->version ?? 0)) {
                $this->fail('stale');
            }
            if (trim($input['reason']) === '') {
                $this->fail('reason_required');
            }
            $options = $this->options($project);
            $stored = [];
            foreach (['finance', 'issue'] as $purpose) {
                $stored[$purpose] = [];
                foreach ($input['suspend'] ? [] : ($input[$purpose.'_selectors'] ?? []) as $selector) {
                    if (! isset($options[$selector])) {
                        $this->fail('selector_invalid');
                    }
                    $stored[$purpose][] = ['selector' => $selector, 'label' => $options[$selector]];
                }
            }
            if (! $input['suspend'] && $stored['finance'] === [] && $stored['issue'] === []) {
                $this->fail('selection_required');
            }
            DB::table('po_execution_policies')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id, 'buyer_company_id' => $input['buyer_company_id'],
                'company_id' => $actor->company_id, 'created_by' => $actor->id, 'actor_name' => $fresh->name, 'created_at' => now(),
                'version' => (int) $input['version'] + 1, 'finance_selectors' => json_encode($stored['finance'], JSON_THROW_ON_ERROR),
                'issue_selectors' => json_encode($stored['issue'], JSON_THROW_ON_ERROR), 'reason' => trim($input['reason']),
            ]);
        }), $project->id);
    }

    /** Live nomination ONLY; future actions must additionally enforce order/version and 3-person separation. */
    public function nominationId(Project $project, User $actor, string $buyerId, string $purpose, ?string $discipline = null): ?string
    {
        if (! in_array($purpose, ['finance', 'issue'], true)) {
            return null;
        }

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $buyerId, $purpose, $discipline): ?string {
            $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->first();
            $buyer = Company::with('plan')->whereKey($buyerId)->first();
            foreach ([$fresh?->company?->plan, $buyer?->plan] as $plan) {
                if (! $plan?->hasModule('proc') || ! $plan->allowsPack($project->standards_pack)) {
                    return null;
                }
            }
            if (! $fresh || $fresh->company_id !== $actor->company_id) {
                return null;
            }

            return DB::selectOne('SELECT epcc_po_execution_nomination(?,?,?,?,?,?) AS id', [$project->id, $actor->id, $actor->company_id, $buyerId, $purpose, $discipline])->id;
        }, $project->id);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['execution_access' => __('po_execution_access.'.$key)]);
    }
}
