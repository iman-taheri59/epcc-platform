<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** ADR-046 configuration/admin only. Custody/read checks live in PipingCustodyService; no QA/GRN/stock action here. */
final class PipingReceiptAccessService
{
    public const ACTIONS = ['custody_mrr', 'receiving_qc', 'acceptance_qa', 'post_grn'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $access,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
    ) {}

    private function authorized(Project $project, User $actor): bool
    {
        return $project->owner_company_id === $actor->company_id
            && $actor->is_active && $actor->company?->plan?->hasModule('proc')
            && $actor->company->plan->allowsPack($project->standards_pack)
            && $this->authz->allows($actor, 'project.configure', $project);
    }

    /** Always fresh; read permission is independent of archive writability. */
    private function context(Project $project, User $actor): array
    {
        $project = Project::whereKey($project->id)->firstOrFail();
        $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->firstOrFail();
        abort_unless($fresh->company_id === $actor->company_id, 403);
        $this->access->assertAccess($project, $fresh);
        abort_unless($this->authorized($project, $fresh), 403);

        return [$project, $fresh];
    }

    private function directory(Project $project): array
    {
        $companies = Company::with('plan')->whereIn('id', DB::table('project_company')
            ->where('project_id', $project->id)->select('company_id'))->orderBy('code')->get()
            ->filter(fn ($c) => $c->plan?->hasModule('proc') && $c->plan->allowsPack($project->standards_pack))->keyBy('id');
        $members = ProjectMembership::where('project_id', $project->id)->get()
            ->filter(fn ($m) => $m->discipline_scope === null || in_array('PIPING', $m->discipline_scope, true))->keyBy('user_id');
        $people = $this->tenant->forIdentityLookup(fn () => User::whereIn('id', $members->keys())
            ->whereIn('company_id', $companies->keys())->where('is_active', true)->orderBy('name')->get(['id', 'name', 'company_id']));
        $roles = Role::all()->keyBy('code');
        $options = [];
        foreach ($people as $person) {
            $code = $companies[$person->company_id]->code;
            $options['user:'.$person->id] = ['selector' => 'user:'.$person->id, 'company_id' => $person->company_id, 'label' => $person->name.' · '.$code];
            foreach ($members[$person->id]->role_codes ?? [] as $role) {
                if (isset($roles[$role])) {
                    $key = 'role:'.$person->company_id.':'.$role;
                    $options[$key] = ['selector' => $key, 'company_id' => $person->company_id, 'label' => $roles[$role]->name(app()->getLocale()).' · '.$code];
                }
            }
        }

        return [$companies, $options];
    }

    public function dashboard(Project $project, User $actor): array
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor): array {
            [$project, $fresh] = $this->context($project, $actor);
            $history = DB::table('piping_receipt_author_policies')->where('project_id', $project->id)
                ->orderByDesc('version')->limit(20)->get();
            $current = $history->first();
            $writable = $this->archives->isWritable($project);
            [$companies, $options] = $writable ? $this->directory($project) : [collect(), []];

            return compact('project', 'current', 'history', 'writable', 'companies', 'options');
        }, $project->id);
    }

    public function configure(Project $project, User $actor, array $input): void
    {
        try {
            $this->tenant->runAs($actor->company_id, fn () => DB::transaction(function () use ($project, $actor, $input): void {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$project->id]);
                $project = Project::whereKey($project->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                [$project, $fresh] = $this->context($project, $actor);
                $this->archives->assertWritable($project);
                Validator::make(['access' => $input], ['access.suspend' => ['required', 'boolean']], ['required' => __('receipt_access.invalid'), 'boolean' => __('receipt_access.invalid')])->validate();
                $suspended = (bool) $input['suspend'];
                $rules = [
                    'access' => ['required', 'array:version,custodian_company_id,actions,suspend,reason'],
                    'access.version' => ['required', 'integer', 'min:0', 'max:2147483646'],
                    'access.suspend' => ['required', 'boolean'],
                    'access.custodian_company_id' => ['nullable', Rule::requiredIf(! $suspended), 'uuid'],
                    'access.reason' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
                    'access.actions' => [Rule::requiredIf(! $suspended), 'array:'.implode(',', self::ACTIONS)],
                ];
                foreach (self::ACTIONS as $action) {
                    $rules['access.actions.'.$action] = [Rule::requiredIf(! $suspended), 'array', 'max:100'];
                    $rules['access.actions.'.$action.'.*'] = ['required', 'string', 'max:160', 'distinct'];
                }
                $data = Validator::make(['access' => $input], $rules, array_fill_keys(['required', 'array', 'integer', 'min', 'max', 'boolean', 'uuid', 'string', 'regex', 'distinct'], __('receipt_access.invalid')))->validate()['access'];
                if (str_contains($data['reason'], "\0")) {
                    $this->fail();
                }
                $current = DB::table('piping_receipt_author_policies')->where('project_id', $project->id)->orderByDesc('version')->first();
                if ((int) $data['version'] !== (int) ($current->version ?? 0)) {
                    $this->fail('stale');
                }
                [$companies, $options] = $this->directory($project);
                if (! $suspended && ! isset($companies[$data['custodian_company_id']])) {
                    $this->fail();
                }
                $actions = [];
                foreach (self::ACTIONS as $action) {
                    $actions[$action] = [];
                    // Even suspended submissions may not smuggle malformed or foreign selectors.
                    foreach ($data['actions'][$action] ?? [] as $selector) {
                        if (! isset($options[$selector])) {
                            $this->fail();
                        }
                        if (! $suspended) {
                            $actions[$action][] = $options[$selector];
                        }
                    }
                }
                DB::table('piping_receipt_author_policies')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $project->id, 'company_id' => $fresh->company_id,
                    'created_by' => $fresh->id, 'actor_name' => $fresh->name, 'created_at' => now(),
                    'version' => (int) $data['version'] + 1, 'suspended' => $suspended,
                    'custodian_company_id' => $suspended ? null : $data['custodian_company_id'],
                    'actions' => json_encode($actions, JSON_THROW_ON_ERROR), 'reason' => trim($data['reason']),
                ]);
            }), $project->id);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'PIPING_RECEIPT_POLICY_STALE')) {
                $this->fail('stale');
            }
            throw $e;
        }
    }

    private function fail(string $key = 'invalid'): never
    {
        throw ValidationException::withMessages(['receipt_access' => __('receipt_access.'.$key)]);
    }
}
