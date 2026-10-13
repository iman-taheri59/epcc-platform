<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\PunchItem;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ProjectArchiveService;
use App\Support\ProjectState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-013 §7.2 - project archive.
 *
 * Two layers are under test and they fail differently:
 *   - the service refuses LOUDLY, with a message the user can act on;
 *   - RLS refuses SILENTLY for UPDATE/DELETE (zero rows, no error), which is
 *     why the service layer exists at all.
 *
 * The tests that matter most are the ones asserting an archived project is
 * still fully READABLE. An archive that hides data is just a slow delete.
 */
final class ProjectArchiveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        // The auto-numbering path is withdrawn in production (a counter
        // over surviving rows reissues a deleted line's number). These
        // tests exercise the numbering ENGINE, so they opt in explicitly.
        $this->allowAutoLineNumbers(\App\Models\Project::where('code', 'PH-14')->firstOrFail());
    }

    private function service(): ProjectArchiveService
    {
        return $this->app->make(ProjectArchiveService::class);
    }

    private function project(string $code = 'PH-14'): Project
    {
        return Project::query()->where('code', $code)->firstOrFail();
    }

    private function ownerOf(Project $project): User
    {
        // `users` carries RLS and a test binds no tenant, so this needs the
        // identity hatch - the same one the session guard uses.
        return $this->provisionIdentity(
            fn (): User => User::query()->where('company_id', $project->owner_company_id)->firstOrFail()
        );
    }

    /**
     * Run a closure with the owner company and project bound, which is what
     * every project-scoped table's RLS policy expects. Tests that read punch
     * items or subsystems have to go through here or they legitimately see
     * nothing.
     */
    private function asOwner(Project $project, callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function userNotIn(string $companyId): User
    {
        return $this->provisionIdentity(
            fn (): User => User::query()->where('company_id', '<>', $companyId)->firstOrFail()
        );
    }

    // ------------------------------------------------------ state machine --

    public function test_a_new_project_is_active(): void
    {
        $this->assertSame(ProjectState::ACTIVE, $this->project()->state);
        $this->assertTrue($this->project()->isWritable());
        $this->assertFalse($this->project()->isArchived());
    }

    public function test_the_lifecycle_is_reversible(): void
    {
        // A project archived by mistake, or reopened for a warranty claim, must
        // not need a database administrator.
        $this->assertTrue(ProjectState::canTransition(ProjectState::ACTIVE, ProjectState::ARCHIVED));
        $this->assertTrue(ProjectState::canTransition(ProjectState::ARCHIVED, ProjectState::ACTIVE));
    }

    public function test_there_is_no_deleted_state(): void
    {
        // Hard deletion is a ceremonial operation (ADR-013 §7.3), not one
        // dropdown away from archiving.
        $this->assertSame([ProjectState::ACTIVE, ProjectState::ARCHIVED], ProjectState::all());
        $this->assertFalse(ProjectState::isKnown('DELETED'));
    }

    public function test_only_active_is_writable(): void
    {
        $this->assertTrue(ProjectState::isWritable(ProjectState::ACTIVE));
        $this->assertFalse(ProjectState::isWritable(ProjectState::ARCHIVED));
    }

    // ---------------------------------------------------------- archiving --

    public function test_the_owner_can_archive_a_project(): void
    {
        $project = $this->project();
        $actor = $this->ownerOf($project);

        $archived = $this->service()->archive($project, $actor, 'Handover complete');

        $this->assertSame(ProjectState::ARCHIVED, $archived->state);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame($actor->id, $archived->archived_by);
        $this->assertSame('Handover complete', $archived->archive_reason);
    }

    public function test_archiving_records_who_and_when(): void
    {
        // Without provenance the audit trail is a shrug. Also enforced by a
        // CHECK constraint, tested separately below.
        $project = $this->project();
        $actor = $this->ownerOf($project);

        $before = now()->subSecond();
        $archived = $this->service()->archive($project, $actor);

        $this->assertTrue($archived->archived_at->greaterThanOrEqualTo($before));
        $this->assertSame($actor->id, $archived->archived_by);
        $this->assertNotNull(
            $this->provisionIdentity(fn () => $archived->archivedBy()->first()),
            'the archiving user must be resolvable for the audit trail',
        );
    }

    public function test_a_non_owner_company_cannot_archive(): void
    {
        $project = $this->project();
        $outsider = $this->userNotIn($project->owner_company_id);

        $this->expectException(ValidationException::class);
        $this->service()->archive($project, $outsider);
    }

    public function test_the_contractor_cannot_archive_the_project_it_works_on(): void
    {
        // Archiving ends everybody else's ability to write. That is the
        // employer's decision, not the contractor's.
        $project = $this->project();
        $contractor = Company::query()->where('code', 'EPC-ARVAND')->firstOrFail();
        $user = $this->provisionIdentity(
            fn (): ?User => User::query()->where('company_id', $contractor->id)->first()
        );
        $this->assertNotNull($user, 'fixture must have an EPC-ARVAND user');

        try {
            $this->service()->archive($project, $user);
            $this->fail('a contractor must not be able to archive');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('owner', strtolower(implode(' ', $e->errors()['project'] ?? [])));
        }
    }

    public function test_archiving_twice_is_refused(): void
    {
        $project = $this->project();
        $actor = $this->ownerOf($project);
        $this->service()->archive($project, $actor);

        $this->expectException(ValidationException::class);
        $this->service()->archive($project->fresh(), $actor);
    }

    // ------------------------------------------- READ still works (crux) --

    public function test_an_archived_project_is_still_fully_readable(): void
    {
        // The whole difference between an archive and a delete. If this test
        // ever fails, the feature has become a slow delete.
        $project = $this->project();
        $actor = $this->ownerOf($project);

        [$punchBefore, $subsystemsBefore] = $this->asOwner($project, fn () => [
            PunchItem::query()->count(),
            Subsystem::query()->count(),
        ]);
        $this->assertGreaterThan(0, $punchBefore, 'fixture must have punch items or this proves nothing');

        $this->service()->archive($project, $actor);

        [$punchAfter, $subsystemsAfter] = $this->asOwner($project, fn () => [
            PunchItem::query()->count(),
            Subsystem::query()->count(),
        ]);
        $this->assertSame($punchBefore, $punchAfter);
        $this->assertSame($subsystemsBefore, $subsystemsAfter);
    }

    public function test_archived_certificates_and_transmittals_survive(): void
    {
        // These are the records ADR-013 §6 showed a hard delete destroys:
        // MC certificates, payment certificates, transmittals.
        $project = $this->project();
        $actor = $this->ownerOf($project);

        $tables = ['mc_certificates', 'payment_certificates', 'transmittals'];
        $countAll = fn (): array => $this->asOwner($project, function () use ($tables, $project): array {
            $out = [];
            foreach ($tables as $table) {
                $out[$table] = DB::table($table)->where('project_id', $project->id)->count();
            }

            return $out;
        });

        $before = $countAll();
        $this->assertGreaterThan(0, array_sum($before), 'fixture must hold certificates');

        $this->service()->archive($project, $actor);

        foreach ($countAll() as $table => $after) {
            $this->assertSame($before[$table], $after, "{$table} must survive archiving");
        }
    }

    // ------------------------------------------------ WRITE is refused ----

    public function test_the_service_refuses_writes_loudly(): void
    {
        $project = $this->project();
        $actor = $this->ownerOf($project);
        $this->service()->archive($project, $actor);

        $this->expectException(ValidationException::class);
        $this->service()->assertWritable($project->fresh());
    }

    public function test_the_refusal_message_tells_the_user_what_to_do(): void
    {
        $project = $this->project();
        $this->service()->archive($project, $this->ownerOf($project));

        try {
            $this->service()->assertWritable($project->fresh());
            $this->fail('expected a refusal');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['project'] ?? []);
            $this->assertStringContainsString('read only', strtolower($message));
            $this->assertStringContainsString('reopen', strtolower($message));
        }
    }

    public function test_the_database_blocks_an_insert_even_without_the_service(): void
    {
        // The backstop. A route that forgets assertWritable() must still fail.
        $project = $this->project();
        $subsystem = $this->asOwner($project, fn () => Subsystem::query()->firstOrFail());
        $actor = $this->ownerOf($project);
        $this->service()->archive($project, $actor);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner($project, fn () => DB::table('punch_items')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'subsystem_id' => $subsystem->id,
            'company_id' => $project->owner_company_id,
            'number' => 'BYPASS-001',
            'sequence' => 99999,
            'category' => 'A',
            'state' => 'RAISED',
            'description_en' => 'x',
            'description_fa' => 'x',
            'raised_by' => $actor->id,
            'raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_the_database_blocks_an_update_even_without_the_service(): void
    {
        // UPDATE is caught by the same WITH CHECK as INSERT, and it does raise.
        $project = $this->project();
        $punch = $this->asOwner($project, fn () => PunchItem::query()->firstOrFail());
        $original = $punch->description_en;

        $this->service()->archive($project, $this->ownerOf($project));

        try {
            $this->asOwner($project, fn () => DB::table('punch_items')
                ->where('id', $punch->id)
                ->update(['description_en' => 'tampered']));
            $this->fail('an archived project must not accept an UPDATE');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('punch_items_archive_freeze', $e->getMessage());
        }

        $this->assertSame(
            $original,
            $this->asOwner($project, fn () => DB::table('punch_items')->where('id', $punch->id)->value('description_en')),
        );
    }

    public function test_an_archived_project_cannot_be_emptied_by_delete(): void
    {
        // REGRESSION. WITH CHECK is never evaluated for DELETE - there is no
        // new row to check - so the freeze policy alone let a DELETE straight
        // through: the punch list of an archived project reported DELETE 9 and
        // vanished. A second policy scoped FOR DELETE closes it.
        //
        // Note the asymmetry that remains: a blocked DELETE matches zero rows
        // and raises NOTHING, so calling code sees success. The rows are safe,
        // the feedback is not - which is why the service refuses first.
        $project = $this->project();
        $actor = $this->ownerOf($project);
        $before = $this->asOwner($project, fn () => PunchItem::query()->count());
        $this->assertGreaterThan(0, $before);

        $this->service()->archive($project, $actor);

        $deleted = $this->asOwner($project, fn () => DB::table('punch_items')
            ->where('project_id', $project->id)
            ->delete());

        $this->assertSame(0, $deleted, 'the delete must not land');
        $this->assertSame(
            $before,
            $this->asOwner($project, fn () => PunchItem::query()->count()),
            'every row must survive',
        );
    }

    public function test_delete_works_again_once_the_project_is_reopened(): void
    {
        // The mirror of the test above: proves the delete block is tied to the
        // archive state and is not a permanent freeze.
        $project = $this->project();
        $actor = $this->ownerOf($project);

        $this->service()->archive($project, $actor);
        $this->service()->reopen($project->fresh(), $actor);

        $deleted = $this->asOwner($project, fn () => DB::table('punch_items')
            ->where('project_id', $project->id)
            ->delete());

        $this->assertGreaterThan(0, $deleted);
    }

    public function test_other_projects_stay_writable(): void
    {
        // Archiving must not be a fleet-wide freeze.
        $archived = $this->project('PH-14');
        $other = Project::query()->where('code', '<>', 'PH-14')->firstOrFail();

        $this->service()->archive($archived, $this->ownerOf($archived));

        $this->assertTrue($other->fresh()->isWritable());
        $this->service()->assertWritable($other->fresh());
    }

    // ------------------------------------------------------------ reopen --

    public function test_the_owner_can_reopen_and_writes_work_again(): void
    {
        $project = $this->project();
        $actor = $this->ownerOf($project);
        $subsystem = $this->asOwner($project, fn () => Subsystem::query()->firstOrFail());

        $this->service()->archive($project, $actor);
        $reopened = $this->service()->reopen($project->fresh(), $actor);

        $this->assertSame(ProjectState::ACTIVE, $reopened->state);
        $this->assertNull($reopened->archived_at);
        $this->assertNull($reopened->archived_by);
        $this->assertNull($reopened->archive_reason);

        // And the database no longer blocks the insert.
        $this->asOwner($project, fn () => DB::table('punch_items')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'subsystem_id' => $subsystem->id,
            'company_id' => $project->owner_company_id,
            'number' => 'REOPENED-001',
            'sequence' => 99998,
            'category' => 'A',
            'state' => 'RAISED',
            'description_en' => 'x',
            'description_fa' => 'x',
            'raised_by' => $actor->id,
            'raised_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertSame(
            1,
            $this->asOwner($project, fn () => DB::table('punch_items')->where('number', 'REOPENED-001')->count()),
            'writes must work again after reopening',
        );
    }

    public function test_reopening_a_live_project_is_refused(): void
    {
        $project = $this->project();

        $this->expectException(ValidationException::class);
        $this->service()->reopen($project, $this->ownerOf($project));
    }

    public function test_a_non_owner_cannot_reopen(): void
    {
        $project = $this->project();
        $this->service()->archive($project, $this->ownerOf($project));
        $outsider = $this->userNotIn($project->owner_company_id);

        $this->expectException(ValidationException::class);
        $this->service()->reopen($project->fresh(), $outsider);
    }

    // ------------------------------------------------------------- quota --

    public function test_an_archived_project_does_not_consume_a_licence_slot(): void
    {
        // The commercial point. A starter customer (max_projects = 1) must be
        // able to finish one project and start the next WITHOUT deleting the
        // first, or the licence terms push them toward destroying evidence.
        $project = $this->project();
        $companyId = $project->owner_company_id;

        $before = $this->service()->activeProjectCount($companyId);
        $this->service()->archive($project, $this->ownerOf($project));
        $after = $this->service()->activeProjectCount($companyId);

        $this->assertSame($before - 1, $after);
    }

    public function test_reopening_is_refused_when_it_would_exceed_the_plan(): void
    {
        $project = $this->project();
        $company = Company::query()->findOrFail($project->owner_company_id);
        $actor = $this->ownerOf($project);

        $this->service()->archive($project, $actor);

        // Pin the plan to exactly the number of projects still active, so
        // reopening this one would be one too many.
        $active = $this->service()->activeProjectCount($company->id);
        $plan = Plan::query()->create([
            'code' => 'pinned-'.uniqid(),
            'name_en' => 'Pinned',
            'name_fa' => 'محدود',
            'max_projects' => max($active, 1),
            'max_users' => 10,
            'modules' => ['core'],
            'standards_packs' => ['*'],
        ]);
        $company->plan_id = $plan->id;
        $company->save();

        $this->expectException(ValidationException::class);
        $this->service()->reopen($project->fresh(), $actor);
    }

    public function test_an_unlimited_plan_never_blocks_reopening(): void
    {
        $project = $this->project();
        $company = Company::query()->findOrFail($project->owner_company_id);
        $actor = $this->ownerOf($project);

        $plan = Plan::query()->create([
            'code' => 'unlimited-'.uniqid(),
            'name_en' => 'Unlimited',
            'name_fa' => 'نامحدود',
            'max_projects' => null,
            'max_users' => null,
            'modules' => ['*'],
            'standards_packs' => ['*'],
        ]);
        $company->plan_id = $plan->id;
        $company->save();

        $this->service()->archive($project, $actor);
        $reopened = $this->service()->reopen($project->fresh(), $actor);

        $this->assertSame(ProjectState::ACTIVE, $reopened->state);
    }

    // -------------------------------------------------- database integrity -

    public function test_the_database_rejects_an_unknown_state(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('projects')->where('code', 'PH-14')->update(['state' => 'BANANA']);
    }

    public function test_the_database_rejects_an_archive_without_provenance(): void
    {
        // A CHECK constraint, because the service is not the only thing that
        // can write here - a future admin tool or import could too.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('projects')->where('code', 'PH-14')->update([
            'state' => ProjectState::ARCHIVED,
            'archived_at' => null,
            'archived_by' => null,
        ]);
    }

    // ------------------------------------------------------------ scopes --

    public function test_pickers_see_only_active_projects_by_default(): void
    {
        $project = $this->project();
        $totalBefore = Project::query()->count();

        $this->service()->archive($project, $this->ownerOf($project));

        $this->assertSame($totalBefore - 1, Project::query()->active()->count());
        $this->assertSame(1, Project::query()->archived()->count());
        // ...but it is still there, by id, in full.
        $this->assertNotNull(Project::query()->find($project->id));
        $this->assertSame($totalBefore, Project::query()->count());
    }

    // ------------------------------------------------------------- i18n ---

    public function test_a_persian_user_gets_persian_refusals(): void
    {
        // REGRESSION. SetUserLocale was registered on the Filament panel only,
        // so `__()` everywhere else fell back to the default locale: a refusal
        // thrown by the service reached a Persian user in English. The
        // hand-written views hid it, because they take language from their own
        // ?lang= closure - the page was Persian and only the translated
        // strings were not.
        $project = $this->project();
        $this->service()->archive($project, $this->ownerOf($project));

        app()->setLocale('fa');
        try {
            $this->service()->assertWritable($project->fresh());
            $this->fail('expected a refusal');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['project'] ?? []);
            $this->assertStringContainsString('بایگانی', $message, 'a Persian user must be refused in Persian');
            $this->assertStringNotContainsString('read only', strtolower($message));
        } finally {
            app()->setLocale('en');
        }
    }

    public function test_the_locale_middleware_covers_the_web_group(): void
    {
        // Pins the registration itself: the strings above only reach a real
        // user if something sets the locale on an ordinary web request.
        $kernel = $this->app->make(\Illuminate\Contracts\Http\Kernel::class);
        $web = $kernel->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(
            \App\Http\Middleware\SetUserLocale::class,
            $web,
            'SetUserLocale must run for the web group, not just the admin panel',
        );

        // And it must not displace the response rewriter from last place.
        $rewriter = array_search(\App\Http\Middleware\PreviewUrlRewriter::class, $web, true);
        $locale = array_search(\App\Http\Middleware\SetUserLocale::class, $web, true);
        if ($rewriter !== false) {
            $this->assertLessThan($rewriter, $locale, 'PreviewUrlRewriter must stay last in the group');
        }
    }

    public function test_every_archive_string_is_bilingual(): void
    {
        $en = require base_path('lang/en/epcc.php');
        $fa = require base_path('lang/fa/epcc.php');

        $this->assertSame(
            array_keys($en['project']),
            array_keys($fa['project']),
            'archive strings must exist in both languages',
        );

        foreach ($fa['project'] as $key => $value) {
            if (is_string($value)) {
                $this->assertNotSame('', trim($value), "fa project.{$key} is empty");
            }
        }
    }
}
