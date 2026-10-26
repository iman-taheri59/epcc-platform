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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** DA-01 scoped configuration/readiness facade; no effective-selection or reservation writer. */
final class DemandReaderService
{
    public function __construct(private readonly TenantContext $tenant, private readonly AuthorizationPipeline $authz,
        private readonly ProjectArchiveService $archives) {}

    private function actor(Project $project, User $actor): User
    {
        $fresh = User::with('company.plan')->whereKey($actor->id)->where('company_id', $actor->company_id)->first();
        abort_unless($fresh && $fresh->is_active && $fresh->company?->plan?->hasModule('proc')
            && $fresh->company->plan->allowsPack($project->standards_pack), 403);
        $membership = ProjectMembership::where('project_id', $project->id)->where('user_id', $fresh->id)->first();
        abort_unless($membership && ($membership->discipline_scope === null || in_array('PIPING', $membership->discipline_scope, true))
            && DB::table('project_company')->where('project_id', $project->id)->where('company_id', $fresh->company_id)->exists(), 403);

        return $fresh;
    }

    private function key(Project $p, array $d): array
    {
        return ['project_id' => $p->id, 'target_company_id' => $d['target_company_id'], 'document_id' => $d['document_id'],
            'scope_id' => $d['scope_id'], 'hierarchy_revision_id' => $d['hierarchy_revision_id']];
    }

    private function source(Project $p, array $d, ?string $batch = null): bool
    {
        return DB::table('accepted_mto_rows as r')
            ->join('accepted_mto_batches as b', fn ($j) => $j->on('b.id', '=', 'r.accepted_mto_batch_id')->on('b.project_id', '=', 'r.project_id')->on('b.mto_document_id', '=', 'r.mto_document_id')->on('b.mto_revision_id', '=', 'r.mto_revision_id'))
            ->join('mto_documents as m', fn ($j) => $j->on('m.id', '=', 'r.mto_document_id')->on('m.project_id', '=', 'r.project_id'))
            ->where('m.discipline', 'PIPING')->where('b.state', 'ACCEPTED')->where('r.project_id', $p->id)
            ->where('r.mto_document_id', $d['document_id'])->where('r.scope_id', $d['scope_id'])
            ->where('r.hierarchy_revision_id', $d['hierarchy_revision_id'])->where('b.hierarchy_revision_id', $d['hierarchy_revision_id'])
            ->when($batch !== null, fn ($q) => $q->where('b.id', $batch))->exists();
    }

    public function configure(Project $project, User $actor, array $input): string
    {
        $d = Validator::make($input, [
            'target_company_id' => ['required', 'uuid'], 'document_id' => ['required', 'uuid'], 'scope_id' => ['required', 'uuid'],
            'hierarchy_revision_id' => ['required', 'string', 'max:96', 'regex:/\S/u'], 'version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'suspended' => ['required', 'boolean'], 'selectors' => ['present', 'array', 'max:100'], 'selectors.*' => ['required', 'string', 'distinct'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ])->validate();

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): string {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $fresh = $this->actor($p, $actor);
            abort_unless($p->owner_company_id === $fresh->company_id && $this->authz->allows($fresh, 'project.configure', $p), 403);
            $this->archives->assertWritable($p);
            abort_unless($this->source($p, $d), 403);
            $key = $this->key($p, $d);
            $version = (int) DB::table('demand_reader_policies')->where($key)->max('version');
            if ($version !== (int) $d['version']) {
                throw ValidationException::withMessages(['version' => __('demand_readers.stale')]);
            }
            if ($d['suspended'] ? $d['selectors'] !== [] : $d['selectors'] === []) {
                throw ValidationException::withMessages(['selectors' => __('demand_readers.invalid')]);
            }
            $valid = array_keys($this->directory($p, $d['target_company_id']));
            if (! DB::table('project_company')->where('project_id', $p->id)->where('company_id', $d['target_company_id'])->exists()
                || array_diff($d['selectors'], $valid) !== []) {
                throw ValidationException::withMessages(['selectors' => __('demand_readers.invalid')]);
            }
            $id = (string) Str::uuid();
            DB::table('demand_reader_policies')->insert($key + ['id' => $id, 'company_id' => $fresh->company_id, 'created_by' => $fresh->id,
                'version' => $version + 1, 'suspended' => $d['suspended'], 'selectors' => json_encode($d['selectors'], JSON_THROW_ON_ERROR), 'reason' => $d['reason']]);

            return $id;
        }, $project->id);
    }

    /** Metadata-only, owner-owned recorded policy heads. No source identities/content resolved. */
    public function suspensionRegister(Project $project, User $actor): array
    {
        $this->assertConfigurator($project, $actor);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor): array {
            $p = Project::whereKey($project->id)->firstOrFail();
            $policies = DB::table('demand_reader_policies as h')->where('h.project_id', $p->id)->where('h.company_id', $actor->company_id)
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('demand_reader_policies as n');
                    foreach (['project_id', 'target_company_id', 'document_id', 'scope_id', 'hierarchy_revision_id'] as $column) {
                        $q->whereColumn('n.'.$column, 'h.'.$column);
                    }
                    $q->whereColumn('n.version', '>', 'h.version');
                })->select('h.id', 'h.version', 'h.target_company_id', 'h.suspended', 'h.created_at')
                ->orderByDesc('h.created_at')->orderBy('h.id')->paginate(25)->withQueryString();
            $this->assertConfigurator($p, $actor);

            return ['project' => $p, 'policies' => $policies, 'writable' => $this->archives->isWritable($p)];
        }, $project->id);
    }

    /** Can ONLY append empty suspended successor of the exact current, owner-owned recorded policy. */
    public function suspendRecorded(Project $project, User $actor, array $input): string
    {
        $this->assertConfigurator($project, $actor);
        $d = Validator::make($input, ['policy_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): string {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->assertConfigurator($p, $actor);
            $this->archives->assertWritable($p);
            $record = DB::table('demand_reader_policies')->where('id', $d['policy_id'])->where('project_id', $p->id)->where('company_id', $actor->company_id)->first();
            abort_unless($record, 404);
            $key = $this->key($p, (array) $record);
            $current = DB::table('demand_reader_policies')->where($key)->orderByDesc('version')->first();
            if (! $current || $current->id !== $record->id || (int) $current->version !== (int) $d['version'] || $current->suspended) {
                throw ValidationException::withMessages(['version' => __('demand_readers.stale')]);
            }
            $id = (string) Str::uuid();
            DB::table('demand_reader_policies')->insert($key + ['id' => $id, 'company_id' => $actor->company_id, 'created_by' => $actor->id,
                'version' => (int) $current->version + 1, 'suspended' => true, 'selectors' => '[]', 'reason' => $d['reason']]);

            return $id;
        }, $project->id);
    }

    private function directory(Project $p, string $companyId): array
    {
        $members = ProjectMembership::where('project_id', $p->id)->get();
        $people = $this->tenant->forIdentityLookup(fn () => User::with('company.plan')->where('company_id', $companyId)->where('is_active', true)->get());
        $options = [];
        $roles = Role::pluck('code')->all();
        foreach ($people as $person) {
            $m = $members->firstWhere('user_id', $person->id);
            if (! $m || ($m->discipline_scope !== null && ! in_array('PIPING', $m->discipline_scope, true))
                || ! $person->company?->plan?->hasModule('proc') || ! $person->company->plan->allowsPack($p->standards_pack)) {
                continue;
            }
            $options['user:'.$person->id] = $person->name;
            foreach ($m->role_codes ?? [] as $role) {
                if (in_array($role, $roles, true)) {
                    $options['role:'.$companyId.':'.$role] = $role.' · '.$person->company->code;
                }
            }
        }

        return $options;
    }

    public function assertConfigurator(Project $project, User $actor): void
    {
        $this->tenant->runAs($actor->company_id, function () use ($project, $actor): void {
            $p = Project::whereKey($project->id)->firstOrFail();
            $fresh = $this->actor($p, $actor);
            abort_unless($p->owner_company_id === $fresh->company_id && $this->authz->allows($fresh, 'project.configure', $p), 403);
        }, $project->id);
    }

    public function dashboard(Project $project, User $actor, array $query): array
    {
        $this->assertConfigurator($project, $actor);
        $d = Validator::make($query, [
            'target_company_id' => ['nullable', 'uuid'],
            'batch_id' => ['required_with:document_id,scope_id,hierarchy_revision_id', 'nullable', 'uuid'],
            'document_id' => ['required_with:batch_id,scope_id,hierarchy_revision_id', 'nullable', 'uuid'],
            'scope_id' => ['required_with:batch_id,document_id,hierarchy_revision_id', 'nullable', 'uuid'],
            'hierarchy_revision_id' => ['required_with:batch_id,document_id,scope_id', 'nullable', 'string', 'max:96', 'regex:/\S/u'],
        ])->validate();

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): array {
            $p = Project::whereKey($project->id)->firstOrFail();
            $companies = Company::with('plan')->whereIn('id', DB::table('project_company')->where('project_id', $p->id)->select('company_id'))
                ->orderBy('code')->get()->filter(fn ($c) => $c->plan?->hasModule('proc') && $c->plan->allowsPack($p->standards_pack));
            $target = $d['target_company_id'] ?? null;
            abort_if($target !== null && ! $companies->contains('id', $target), 403);
            $tuples = DB::table('accepted_mto_rows as r')
                ->join('accepted_mto_batches as b', fn ($j) => $j->on('b.id', '=', 'r.accepted_mto_batch_id')->on('b.project_id', '=', 'r.project_id')->on('b.mto_document_id', '=', 'r.mto_document_id')->on('b.mto_revision_id', '=', 'r.mto_revision_id'))
                ->join('mto_documents as m', fn ($j) => $j->on('m.id', '=', 'r.mto_document_id')->on('m.project_id', '=', 'r.project_id'))
                ->where('r.project_id', $p->id)->where('m.discipline', 'PIPING')->where('b.state', 'ACCEPTED')
                ->whereNotNull('r.scope_id')->whereNotNull('r.hierarchy_revision_id')->where('r.hierarchy_revision_id', '<>', '')
                ->whereColumn('b.hierarchy_revision_id', 'r.hierarchy_revision_id')
                ->select('b.id as batch_id', 'r.mto_document_id as document_id', 'r.scope_id', 'r.hierarchy_revision_id', 'm.document_number', 'b.revision_code')->distinct();
            $sources = DB::query()->fromSub($tuples, 'visible_sources')->orderBy('document_number')->orderBy('batch_id')->orderBy('scope_id')->paginate(25)->withQueryString();
            $selected = null;
            $history = collect();
            $current = null;
            $options = [];
            if (! empty($d['batch_id'])) {
                abort_unless($target !== null && $this->source($p, $d, $d['batch_id']), 403);
                $selected = $d;
                $key = $this->key($p, $d);
                $history = DB::table('demand_reader_policies')->where($key)->orderByDesc('version')->limit(20)->get();
                $current = $history->first();
                $options = $this->directory($p, $target);
            }
            $this->assertConfigurator($p, $actor);

            return ['project' => $p, 'companies' => $companies, 'sources' => $sources, 'selected' => $selected,
                'target' => $target, 'history' => $history, 'current' => $current, 'options' => $options,
                'writable' => $this->archives->isWritable($p)];
        }, $project->id);
    }

    private function nomination(Project $project, User $actor, array $d): ?object
    {
        $fresh = $this->actor($project, $actor);
        // Latest includes suspended/empty replacement; never fall back to an earlier readable grant.
        $policy = DB::table('demand_reader_policies')->where($this->key($project, $d))->orderByDesc('version')->first();
        if (! $policy || $policy->suspended) {
            return null;
        }
        $selectors = json_decode($policy->selectors, true, flags: JSON_THROW_ON_ERROR);
        if (in_array('user:'.$fresh->id, $selectors, true)) {
            return $policy;
        }
        $m = ProjectMembership::where('project_id', $project->id)->where('user_id', $fresh->id)->firstOrFail();
        foreach ($m->role_codes ?? [] as $role) {
            if (Role::where('code', $role)->exists() && in_array('role:'.$fresh->company_id.':'.$role, $selectors, true)) {
                return $policy;
            }
        }

        return null;
    }

    public function assess(Project $project, User $actor, string $batchId, string $documentId, string $scopeId, string $hierarchy): array
    {
        foreach ([$batchId, $documentId, $scopeId] as $id) {
            abort_unless(Str::isUuid($id), 404);
        }
        abort_unless(trim($hierarchy) !== '' && mb_strlen($hierarchy) <= 96, 404);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $batchId, $documentId, $scopeId, $hierarchy): array {
            $p = Project::whereKey($project->id)->firstOrFail();
            $d = ['target_company_id' => $actor->company_id, 'document_id' => $documentId, 'scope_id' => $scopeId, 'hierarchy_revision_id' => $hierarchy];
            $policy = $this->nomination($p, $actor, $d);
            abort_unless($policy, 403);
            $visible = $this->source($p, $d, $batchId);
            $current = $this->nomination($p, $actor, $d);
            abort_unless($current && $current->id === $policy->id, 403);

            return ['ready' => false, 'status' => $visible ? 'UNRESOLVED' : 'UNAVAILABLE', 'gross' => null, 'remaining' => null,
                'operationally_authorized' => false, 'findings' => $visible ? ['EFFECTIVE_SELECTION_ADAPTER_NOT_IMPLEMENTED', 'COMMITMENT_INVENTORY_NOT_ASSESSED'] : ['SOURCE_UNAVAILABLE_OR_UNRESOLVED']];
        }, $project->id);
    }
}
