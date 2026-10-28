<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a result-recorder nomination so a test can post inspection evidence.
 *
 * Recording is default-deny since A.4b slice 1: without an explicit, current,
 * non-suspended policy the guard refuses the insert. This fixture exists so
 * tests can state WHO is nominated instead of relying on bare membership.
 *
 * Selectors follow the house policy shape:
 *   'user:<uuid>'                      - one named person
 *   'role:<company uuid>:<role code>'  - every member holding that project role
 */
final class ResultRecorderPolicyFixture
{
    public const ACTIONS = ['record_weld', 'record_ndt', 'record_pack_result', 'register_certificate'];

    /**
     * Creates (or version-bumps) a nomination for one action.
     *
     * @param  list<string>  $selectors
     *
     * @return string policy id
     */
    public static function ensure(
        TenantContext $tenant,
        Project $project,
        User $author,
        string $targetCompanyId,
        string $action,
        array $selectors,
        bool $suspended = false,
    ): string {
        return $tenant->runAs($author->company_id, function () use ($project, $author, $targetCompanyId, $action, $selectors, $suspended): string {
            // The insert policy pins created_by to the session actor, so name one.
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS actor")->actor;
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$author->id]);

            $version = (int) DB::table('result_recorder_policies')
                ->where('project_id', $project->id)
                ->where('target_company_id', $targetCompanyId)
                ->where('action', $action)
                ->max('version');

            $id = (string) Str::uuid();
            DB::table('result_recorder_policies')->insert([
                'id' => $id,
                'project_id' => $project->id,
                'company_id' => $author->company_id,
                'target_company_id' => $targetCompanyId,
                'created_by' => $author->id,
                'action' => $action,
                'version' => $version + 1,
                'suspended' => $suspended,
                // A suspended policy revokes by emptying the selector set, per the CHECK.
                'selectors' => json_encode($suspended ? [] : array_values($selectors)),
                'reason' => 'Test nomination for '.$action,
            ]);
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$prior ?? '']);

            return $id;
        }, $project->id);
    }

    /**
     * Nominates one person for every evidence kind, by user selector.
     * Convenience for tests whose concern is not authority at all.
     *
     * @return array<string,string> action => policy id
     */
    public static function ensureAllForUser(TenantContext $tenant, Project $project, User $actor): array
    {
        $ids = [];
        foreach (self::ACTIONS as $action) {
            $ids[$action] = self::ensure(
                $tenant, $project, $actor, $actor->company_id, $action, ['user:'.$actor->id],
            );
        }

        return $ids;
    }

    /**
     * Nominates a whole project role for every evidence kind.
     *
     * @return array<string,string> action => policy id
     */
    public static function ensureAllForRole(
        TenantContext $tenant,
        Project $project,
        User $author,
        string $targetCompanyId,
        string $roleCode,
    ): array {
        $ids = [];
        foreach (self::ACTIONS as $action) {
            $ids[$action] = self::ensure(
                $tenant, $project, $author, $targetCompanyId, $action, ['role:'.$targetCompanyId.':'.$roleCode],
            );
        }

        return $ids;
    }
}
