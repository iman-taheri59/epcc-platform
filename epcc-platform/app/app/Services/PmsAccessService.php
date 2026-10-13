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

/** ADR-008 §17–18. Nomination is neither publication nor contractual adoption. */
final class PmsAccessService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $access,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
    ) {}

    /** Caller supplies the existing tenant scope; authorization is always read fresh. */
    public function allows(Project $project, User $actor): bool
    {
        return $this->tenant->runAs($actor->company_id, fn (): bool => DB::selectOne(
            'SELECT epcc_pms_author_policy(?, ?, ?) AS policy_id',
            [$project->id, $actor->id, $actor->company_id],
        )->policy_id !== null, $project->id);
    }

    private function latest(string $projectId): ?object
    {
        return DB::table('pms_author_policies')->where('project_id', $projectId)->orderByDesc('version')->first();
    }

    /** Configuration authority mirrors existing owner project-configuration, not editing nomination. */
    public function canConfigure(Project $project, User $actor): bool
    {
        if ($project->owner_company_id !== $actor->company_id || ! $this->archives->isWritable($project)) {
            return false;
        }

        return $this->authz->allows($actor, 'project.configure', $project);
    }

    /** @return array<string, string> Only active PIPING members of participating companies. */
    private function options(Project $project): array
    {
        $members = ProjectMembership::where('project_id', $project->id)->get()->keyBy('user_id');
        $companies = DB::table('companies')->whereIn('id', DB::table('project_company')->where('project_id', $project->id)->select('company_id'))->pluck('code', 'id');
        $people = $this->tenant->forIdentityLookup(fn () => User::whereIn('id', $members->keys())
            ->whereIn('company_id', $companies->keys())->where('is_active', true)->get(['id', 'name', 'company_id']));
        $roles = Role::all()->keyBy('code');
        $options = [];
        foreach ($people as $person) {
            $membership = $members[$person->id];
            if ($membership->discipline_scope !== null && ! in_array('PIPING', $membership->discipline_scope, true)) {
                continue;
            }
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
        $policies = DB::table('pms_author_policies')->where('project_id', $project->id)->orderByDesc('version')->limit(20)->get();
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
            $fresh = User::whereKey($actor->id)->where('is_active', true)->firstOrFail();
            abort_unless($this->canConfigure($project, $fresh), 403);
            $this->archives->assertWritable($project);
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pms-entry:'.$project->id]);
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
            DB::table('pms_author_policies')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id, 'company_id' => $actor->company_id,
                'created_by' => $actor->id, 'actor_name' => $fresh->name, 'created_at' => now(),
                'version' => (int) $data['version'] + 1, 'selectors' => json_encode($stored, JSON_THROW_ON_ERROR),
                'reason' => trim($data['reason']),
            ]);
        }), $project->id);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['pms_access' => __('pms.'.$key)]);
    }
}
