<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** SA-01/02 internal capability preflight only. No package approval, admission or reservation writer. */
final class ScopeAdmissionAuthorityService
{
    private const ACTIONS = ['propose', 'review', 'record'];

    public function __construct(private readonly DemandReaderService $readers, private readonly TenantContext $tenant,
        private readonly ProjectArchiveService $archives) {}

    private function key(Project $p, array $d): array
    {
        return ['project_id' => $p->id, 'target_company_id' => $d['target_company_id'], 'document_id' => $d['document_id'],
            'scope_id' => $d['scope_id'], 'hierarchy_revision_id' => $d['hierarchy_revision_id'], 'action' => $d['action']];
    }

    public function configure(Project $project, User $actor, array $input): string
    {
        $this->readers->assertConfigurator($project, $actor);
        $d = Validator::make($input, [
            'target_company_id' => ['required', 'uuid'], 'document_id' => ['required', 'uuid'], 'scope_id' => ['required', 'uuid'],
            'hierarchy_revision_id' => ['required', 'string', 'max:96', 'regex:/\S/u'], 'batch_id' => ['nullable', 'uuid'],
            'action' => ['required', Rule::in(self::ACTIONS)], 'version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'suspended' => ['required', 'boolean'], 'selectors' => ['present', 'array', 'max:100'], 'selectors.*' => ['required', 'string', 'distinct'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ])->validate();

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): string {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->readers->assertConfigurator($p, $actor);
            $this->archives->assertWritable($p);
            $key = $this->key($p, $d);
            $head = DB::table('scope_admission_actor_policies')->where($key)->orderByDesc('version')->first();
            if ((int) ($head->version ?? 0) !== (int) $d['version']) {
                throw ValidationException::withMessages(['version' => __('scope_admission_authority.stale')]);
            }
            if ($d['suspended']) {
                abort_unless($head && $head->company_id === $actor->company_id, 404);
                if ($d['selectors'] !== []) {
                    throw ValidationException::withMessages(['selectors' => __('scope_admission_authority.invalid')]);
                }
            } else {
                abort_unless(! empty($d['batch_id']), 403);
                // Independent original-owner source visibility and eligible nomination directory, not a read grant.
                $metadata = $this->readers->dashboard($p, $actor, $d);
                if (! $metadata['selected'] || $d['selectors'] === [] || array_diff($d['selectors'], array_keys($metadata['options'])) !== []) {
                    throw ValidationException::withMessages(['selectors' => __('scope_admission_authority.invalid')]);
                }
            }
            $id = (string) Str::uuid();
            DB::table('scope_admission_actor_policies')->insert($key + ['id' => $id, 'company_id' => $actor->company_id, 'created_by' => $actor->id,
                'version' => (int) $d['version'] + 1, 'suspended' => $d['suspended'], 'selectors' => json_encode($d['selectors'], JSON_THROW_ON_ERROR), 'reason' => $d['reason']]);

            return $id;
        }, $project->id);
    }

    public function suspensionRegister(Project $project, User $actor): array
    {
        $this->readers->assertConfigurator($project, $actor);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor): array {
            $p = Project::whereKey($project->id)->firstOrFail();
            $policies = DB::table('scope_admission_actor_policies as h')->where('h.project_id', $p->id)->where('h.company_id', $actor->company_id)
                ->whereNotExists(function ($q) {
                    $q->selectRaw('1')->from('scope_admission_actor_policies as n');
                    foreach (['project_id', 'target_company_id', 'document_id', 'scope_id', 'hierarchy_revision_id', 'action'] as $column) {
                        $q->whereColumn('n.'.$column, 'h.'.$column);
                    }
                    $q->whereColumn('n.version', '>', 'h.version');
                })->select('h.id', 'h.version', 'h.target_company_id', 'h.suspended', 'h.created_at', 'h.action')
                ->orderByDesc('h.created_at')->orderBy('h.id')->paginate(25)->withQueryString();
            $this->readers->assertConfigurator($p, $actor);

            return ['project' => $p, 'policies' => $policies, 'writable' => $this->archives->isWritable($p)];
        }, $project->id);
    }

    /** Can ONLY append empty suspended successor of the exact current, owner-owned recorded policy. */
    public function suspendRecorded(Project $project, User $actor, array $input): string
    {
        $this->readers->assertConfigurator($project, $actor);
        $d = Validator::make($input, ['policy_id' => ['required', 'uuid'], 'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:2000', 'regex:/\S/u']])->validate();

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): string {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->readers->assertConfigurator($p, $actor);
            $this->archives->assertWritable($p);
            $record = DB::table('scope_admission_actor_policies')->where('id', $d['policy_id'])->where('project_id', $p->id)->where('company_id', $actor->company_id)->first();
            abort_unless($record, 404);
            $key = $this->key($p, (array) $record);
            $current = DB::table('scope_admission_actor_policies')->where($key)->orderByDesc('version')->first();
            if (! $current || $current->id !== $record->id || (int) $current->version !== (int) $d['version'] || $current->suspended) {
                throw ValidationException::withMessages(['version' => __('scope_admission_authority.stale')]);
            }
            $id = (string) Str::uuid();
            DB::table('scope_admission_actor_policies')->insert($key + ['id' => $id, 'company_id' => $actor->company_id, 'created_by' => $actor->id,
                'version' => (int) $current->version + 1, 'suspended' => true, 'selectors' => '[]', 'reason' => $d['reason']]);

            return $id;
        }, $project->id);
    }

    public function dashboard(Project $project, User $actor, array $query): array
    {
        $this->readers->assertConfigurator($project, $actor);
        $d = Validator::make($query, ['action' => ['sometimes', 'required', Rule::in(self::ACTIONS)]])->validate();
        $action = $d['action'] ?? 'propose';
        $metadata = $this->readers->dashboard($project, $actor, $query);

        return $this->tenant->runAs($actor->company_id, function () use ($actor, $metadata, $action): array {
            $history = collect();
            if ($metadata['selected']) {
                $history = DB::table('scope_admission_actor_policies')->where($this->key($metadata['project'], $metadata['selected'] + ['action' => $action]))->orderByDesc('version')->limit(20)->get();
            }
            $this->readers->assertConfigurator($metadata['project'], $actor);

            return array_replace($metadata, ['action' => $action, 'history' => $history, 'current' => $history->first()]);
        }, $project->id);
    }

    /** Own current source/action nomination only. No client participant IDs or reviewed package claims. */
    public function diagnostics(Project $project, User $actor, array $source): array
    {
        $d = Validator::make($source, ['batch_id' => ['required', 'uuid'], 'document_id' => ['required', 'uuid'],
            'scope_id' => ['required', 'uuid'], 'hierarchy_revision_id' => ['required', 'string', 'max:96', 'regex:/\S/u']])->validate();

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): array {
            $p = Project::whereKey($project->id)->firstOrFail();
            $reference = $this->readers->assess($p, $actor, $d['batch_id'], $d['document_id'], $d['scope_id'], $d['hierarchy_revision_id']);
            $actions = array_fill_keys(self::ACTIONS, false);
            if ($reference['status'] === 'UNRESOLVED') {
                foreach (self::ACTIONS as $action) {
                    $actions[$action] = $this->policy($p, $actor, $this->key($p, $d + ['target_company_id' => $actor->company_id, 'action' => $action])) !== null;
                }
            }
            // Recheck independent source nomination/visibility before presenting booleans; never a write lease.
            $again = $this->readers->assess($p, $actor, $d['batch_id'], $d['document_id'], $d['scope_id'], $d['hierarchy_revision_id']);
            if ($again['status'] !== 'UNRESOLVED') {
                $actions = array_fill_keys(self::ACTIONS, false);
            }

            return ['status' => $again['status'], 'actions' => $actions, 'writable' => $this->archives->isWritable($p),
                'ready_to_admit' => false, 'package_verified' => false, 'operationally_authorized' => false, 'gross' => null];
        }, $project->id);
    }

    private function policy(Project $p, User $actor, array $key): ?object
    {
        $h = DB::table('scope_admission_actor_policies')->where($key)->orderByDesc('version')->first();
        if (! $h || $h->suspended) {
            return null;
        }
        $selectors = json_decode($h->selectors, true, flags: JSON_THROW_ON_ERROR);
        if (in_array('user:'.$actor->id, $selectors, true)) {
            return $h;
        }
        $membership = ProjectMembership::where('project_id', $p->id)->where('user_id', $actor->id)->first();
        foreach ($membership?->role_codes ?? [] as $role) {
            if (Role::where('code', $role)->exists() && in_array('role:'.$actor->company_id.':'.$role, $selectors, true)) {
                return $h;
            }
        }

        return null;
    }

    /** Participant IDs must come from a future trusted immutable package, never HTTP claims. */
    public function preflight(Project $project, User $actor, array $source, string $action, ?string $proposerId = null, ?string $reviewerId = null): array
    {
        abort_unless(in_array($action, self::ACTIONS, true), 403);
        $d = Validator::make($source, ['batch_id' => ['required', 'uuid'], 'document_id' => ['required', 'uuid'], 'scope_id' => ['required', 'uuid'],
            'hierarchy_revision_id' => ['required', 'string', 'max:96', 'regex:/\S/u']])->validate();

        $proposerId = $proposerId === null ? null : strtolower($proposerId);
        $reviewerId = $reviewerId === null ? null : strtolower($reviewerId);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d, $action, $proposerId, $reviewerId): array {
            $p = Project::whereKey($project->id)->firstOrFail();
            $this->archives->assertWritable($p);
            $reference = $this->readers->assess($p, $actor, $d['batch_id'], $d['document_id'], $d['scope_id'], $d['hierarchy_revision_id']);
            abort_unless($reference['status'] === 'UNRESOLVED', 403);
            $key = $this->key($p, $d + ['target_company_id' => $actor->company_id, 'action' => $action]);
            $head = $this->policy($p, $actor, $key);
            abort_unless($head, 403);
            if ($action === 'propose') {
                abort_if($proposerId !== null && $proposerId !== $actor->id, 403);
            } elseif ($action === 'review') {
                abort_unless($proposerId !== null && Str::isUuid($proposerId) && $proposerId !== $actor->id
                    && ($reviewerId === null || $reviewerId === $actor->id), 403);
            } else {
                abort_unless($proposerId !== null && $reviewerId !== null && Str::isUuid($proposerId) && Str::isUuid($reviewerId)
                    && $proposerId !== $reviewerId && in_array($actor->id, [$proposerId, $reviewerId], true), 403);
            }
            // Recheck current role/policy and independent reader eligibility. No cached old grant fallback.
            $again = $this->readers->assess($p, $actor, $d['batch_id'], $d['document_id'], $d['scope_id'], $d['hierarchy_revision_id']);
            $current = $this->policy($p, $actor, $key);
            abort_unless($again['status'] === 'UNRESOLVED' && $current && $head->id === $current->id, 403);

            return ['action' => $action, 'capability_preflight' => true, 'ready_to_admit' => false, 'operationally_authorized' => false, 'package_verified' => false, 'admission_state' => 'UNADMITTED',
                'gross' => null, 'writes_performed' => 0, 'findings' => ['TRUSTED_PACKAGE_AND_EVIDENCE_NOT_VERIFIED', 'EFFECTIVE_SELECTION_NOT_IMPLEMENTED']];
        }, $project->id);
    }
}
