<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** ADR-033. Independent draft-entry nomination, never a purchasing approval. */
final class PurchaseOrderAccessService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $access,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
    ) {}

    /** Callers receive a fresh entitlement/membership/nomination result, not cached role authority. */
    public function allows(Project $project, User $actor, ?string $discipline = null): bool
    {
        return $this->policyId($project, $actor, $discipline) !== null;
    }

    public function policyId(Project $project, User $actor, ?string $discipline = null): ?string
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $discipline): ?string {
            $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->first();
            $plan = $fresh?->company?->plan;
            if (! $fresh || $fresh->company_id !== $actor->company_id || ! $plan?->hasModule('proc') || ! $plan->allowsPack($project->standards_pack)) {
                return null;
            }

            return DB::selectOne('SELECT epcc_po_author_policy(?, ?, ?, ?) AS policy_id',
                [$project->id, $actor->id, $actor->company_id, $discipline])->policy_id;
        }, $project->id);
    }

    private function latest(string $projectId): ?object
    {
        return DB::table('po_author_policies')->where('project_id', $projectId)->orderByDesc('version')->first();
    }

    /** Configuration authority mirrors existing owner project-configuration, not editing nomination. */
    public function canConfigure(Project $project, User $actor): bool
    {
        $plan = $actor->company?->plan;
        if (! $plan?->hasModule('proc') || ! $plan->allowsPack($project->standards_pack)
            || $project->owner_company_id !== $actor->company_id || ! $this->archives->isWritable($project)) {
            return false;
        }

        return $this->authz->allows($actor, 'project.configure', $project);
    }

    /** @return array<string, string> Only active members of participating buyer companies. */
    private function options(Project $project): array
    {
        $members = ProjectMembership::where('project_id', $project->id)->get()->keyBy('user_id');
        $companies = DB::table('companies')->whereIn('tenant_type', ['OWN', 'EPC', 'SUB'])->whereIn('id', DB::table('project_company')->where('project_id', $project->id)->select('company_id'))->pluck('code', 'id');
        $people = $this->tenant->forIdentityLookup(fn () => User::whereIn('id', $members->keys())
            ->whereIn('company_id', $companies->keys())->where('is_active', true)->get(['id', 'name', 'company_id']));
        $roles = Role::all()->keyBy('code');
        $options = [];
        foreach ($people as $person) {
            $membership = $members[$person->id];
            $options['user:'.$person->id] = $person->name.' · '.$companies[$person->company_id];
            foreach ($membership->role_codes ?? [] as $code) {
                if (isset($roles[$code])) {
                    $options['role:'.$person->company_id.':'.$code] = $roles[$code]->name(app()->getLocale()).' · '.$companies[$person->company_id];
                }
            }
        }

        return $options;
    }

    /** Call within project scope. Never expose a global user directory. */
    public function dashboard(Project $project, User $actor): array
    {
        $canConfigure = $this->canConfigure($project, $actor);
        $policies = DB::table('po_author_policies')->where('project_id', $project->id)->orderByDesc('version')->limit(20)->get();
        $current = $policies->first();

        return [
            'current' => $current,
            'selectors' => $current ? json_decode($current->selectors, true, 512, JSON_THROW_ON_ERROR) : [],
            'history' => $policies,
            'canConfigure' => $canConfigure,
            'options' => $canConfigure ? $this->options($project) : [],
        ];
    }

    public function configure(Project $project, User $actor, array $input): void
    {
        $this->access->assertAccess($project, $actor);
        $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $input): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['po-entry:'.$project->id]);
            $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $fresh = User::whereKey($actor->id)->where('is_active', true)->firstOrFail();
            abort_unless($this->canConfigure($project, $fresh), 403);
            $this->archives->assertWritable($project);
            $data = Validator::make($input, [
                'version' => ['required', 'integer', 'min:0'],
                'selectors' => ['sometimes', 'array', 'max:100'],
                'selectors.*' => ['required', 'string', 'max:160', 'distinct'],
                'suspend' => ['required', 'boolean'],
                'reason' => ['required', 'string', 'max:4000'],
            ])->validate();
            if ((int) $data['version'] !== (int) ($this->latest($project->id)->version ?? 0)) {
                $this->fail('access_stale');
            }
            if (trim($data['reason']) === '') {
                $this->fail('access_reason_required');
            }
            $selectors = $data['suspend'] ? [] : ($data['selectors'] ?? []);
            if (! $data['suspend'] && $selectors === []) {
                $this->fail('access_select_required');
            }
            $options = $this->options($project);
            $stored = [];
            foreach ($selectors as $selector) {
                if (! isset($options[$selector])) {
                    $this->fail('access_invalid_selector');
                }
                $stored[] = ['selector' => $selector, 'label' => $options[$selector]];
            }
            DB::table('po_author_policies')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id, 'company_id' => $actor->company_id,
                'created_by' => $actor->id, 'actor_name' => $fresh->name, 'created_at' => now(),
                'version' => (int) $data['version'] + 1, 'selectors' => json_encode($stored, JSON_THROW_ON_ERROR),
                'reason' => trim($data['reason']),
            ]);
        }), $project->id);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['access' => __('po_entry.'.$key)]);
    }
}
