<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassNote;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\PmsAccessService;
use App\Services\PmsEntryService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PmsAccessTest extends TestCase
{
    private Project $project;

    private User $writer;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->identities();
    }

    private function identities(): void
    {
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
    }

    private function bound(callable $fn, ?User $actor = null): mixed
    {
        return app(TenantContext::class)->runAs(($actor ?? $this->writer)->company_id, $fn, $this->project->id);
    }

    private function nominate(array $selectors, int $version = 0, bool $suspend = false): void
    {
        app(PmsAccessService::class)->configure($this->project, $this->owner, [
            'version' => $version, 'selectors' => $selectors, 'suspend' => $suspend, 'reason' => 'Explicit synthetic author configuration',
        ]);
    }

    private function header(array $changes = []): array
    {
        return array_replace(['code' => 'AUTH-TEST', 'source_document' => 'SYNTHETIC-PMS', 'source_revision' => 'A',
            'source_text' => 'Original source', 'version' => 0, 'reason' => 'Synthetic transcription'], $changes);
    }

    private function create(): PipingClass
    {
        return app(PmsEntryService::class)->saveHeader($this->project, $this->writer, $this->header());
    }

    private function sqlRefused(callable $fn, string $state): void
    {
        try {
            $this->bound(fn () => DB::transaction($fn));
            $this->fail('Expected SQL refusal.');
        } catch (QueryException $e) {
            $this->assertSame($state, $e->getCode());
        }
    }

    public function test_unconfigured_project_is_readable_but_no_actor_including_admin_can_write(): void
    {
        foreach ([$this->writer, $this->owner] as $actor) {
            $this->assertFalse(app(PmsEntryService::class)->canWrite($this->project, $actor));
            $this->actingAs($actor)->get('/projects/PH-14/piping/pms?lang=en')->assertOk()
                ->assertSee('data-pms-access="unconfigured"', false)->assertDontSee('id="pms-header-form"', false);
            $this->post('/projects/PH-14/piping/pms', ['header' => $this->header()])->assertForbidden();
        }
        $this->assertSame(0, $this->bound(fn () => DB::table('pms_entry_events')->count()));
        $this->assertSame(0, $this->bound(fn () => DB::table('pms_author_policies')->count()));
        $this->assertSame(0, $this->bound(fn () => PipingClass::where('code', 'AUTH-TEST')->count()));
    }

    public function test_explicit_user_grant_is_audited_and_configuration_is_not_itself_a_write_grant(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $this->assertTrue(app(PmsEntryService::class)->canWrite($this->project, $this->writer));
        $this->assertFalse(app(PmsEntryService::class)->canWrite($this->project, $this->owner));
        $class = $this->create();
        $this->bound(function () use ($class): void {
            $policy = DB::table('pms_author_policies')->first();
            $event = DB::table('pms_entry_events')->first();
            $this->assertSame($policy->id, $event->author_policy_id);
            $this->assertSame($this->writer->id, $event->created_by);
            $this->assertSame($this->writer->company_id, $class->company_id);
            $this->assertSame($this->owner->id, $policy->created_by);
        });
    }

    public function test_nominated_members_cannot_edit_another_companys_content(): void
    {
        $this->nominate(['user:'.$this->writer->id, 'user:'.$this->owner->id]);
        $class = $this->create();
        $this->assertFalse(app(PmsEntryService::class)->canWrite($this->project, $this->owner, $class));
        $this->actingAs($this->owner)->post('/projects/PH-14/piping/pms/'.$class->id.'/header', ['header' => $this->header(['version' => 1, 'source_text' => 'Changed by other company'])])->assertForbidden();
        $own = app(PmsEntryService::class)->saveHeader($this->project, $this->owner, $this->header(['code' => 'OWNER-CLASS']));
        $this->assertSame($this->owner->company_id, $own->company_id);
        $this->assertSame('Original source', $this->bound(fn () => $class->fresh()->source_text));
    }

    public function test_company_role_grant_is_live_and_not_a_cached_list_of_people(): void
    {
        $role = $this->bound(fn () => $this->writer->membershipFor($this->project->id)->role_codes[0]);
        $this->nominate(['role:'.$this->writer->company_id.':'.$role]);
        // The same role code in another company must not inherit this nomination.
        $this->provisionIdentity(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->owner->id)->update(['role_codes' => [$role]]));
        $this->assertTrue(app(PmsAccessService::class)->allows($this->project, $this->writer));
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->owner));
        $affected = $this->provisionIdentity(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['role_codes' => []]));
        $this->assertSame(1, $affected);
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->writer));
        $this->provisionIdentity(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['role_codes' => [$role]]));
        $this->assertTrue(app(PmsAccessService::class)->allows($this->project, $this->writer));
    }

    public function test_inactive_account_or_non_piping_scope_revokes_a_direct_nomination(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $affected = $this->provisionIdentity(fn () => User::whereKey($this->writer->id)->update(['is_active' => false]));
        $this->assertSame(1, $affected);
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->writer));
        $this->provisionIdentity(fn () => User::whereKey($this->writer->id)->update(['is_active' => true]));
        $this->provisionIdentity(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->writer));
        $this->actingAs($this->writer)->post('/projects/PH-14/piping/pms', ['header' => $this->header()])->assertForbidden();
    }

    public function test_revocation_blocks_header_item_and_note_without_changing_prior_data(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $class = $this->create();
        $this->nominate([], 1, true);
        $base = '/projects/PH-14/piping/pms/'.$class->id;
        $this->actingAs($this->writer)->post($base.'/header', ['header' => $this->header(['version' => 1])])->assertForbidden();
        $this->post($base.'/items', ['item' => ['component_type' => 'PIPE', 'size_key' => '6', 'description_as_written' => 'Source', 'source_ref' => 'p1', 'reason' => 'Test', 'version' => 0]])->assertForbidden();
        $this->post($base.'/notes', ['note' => ['version' => 1, 'number' => 1, 'text_en' => 'Note', 'reason' => 'Test']])->assertForbidden();
        $this->bound(function () use ($class): void {
            $this->assertSame(1, $class->fresh()->entry_version);
            $this->assertSame(1, DB::table('pms_entry_events')->count());
            $this->assertSame(0, DB::table('piping_class_notes')->where('piping_class_id', $class->id)->count());
            $this->assertSame(0, DB::table('piping_class_items')->where('piping_class_id', $class->id)->count());
            $this->assertSame([2, 1], DB::table('pms_author_policies')->orderByDesc('version')->pluck('version')->all());
        });
        $this->get($base.'?lang=en')->assertSee('data-pms-access="suspended"', false)->assertDontSee('id="pms-header-form"', false);
    }

    public function test_configuration_requires_owner_project_configure_and_valid_project_selectors(): void
    {
        $data = ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Scope test'];
        $this->actingAs($this->writer)->post('/projects/PH-14/piping/pms/access', ['access' => $data])->assertForbidden();
        $this->actingAs($this->owner)->post('/projects/PH-14/piping/pms/access', ['access' => array_replace($data, ['selectors' => ['user:'.Str::uuid()]])])->assertSessionHasErrors('pms_access');
        $this->post('/projects/PH-14/piping/pms/access', ['access' => array_replace($data, ['selectors' => []])])->assertSessionHasErrors('pms_access');
        $this->post('/projects/PH-14/piping/pms/access', ['access' => array_replace($data, ['reason' => '   '])])->assertSessionHasErrors();
        $this->assertSame(0, $this->bound(fn () => DB::table('pms_author_policies')->count()));
        $this->post('/projects/PH-14/piping/pms/access', ['access' => $data + ['company_id' => $this->writer->company_id, 'created_by' => $this->writer->id]])->assertRedirect();
        $this->assertSame($this->owner->id, $this->bound(fn () => DB::table('pms_author_policies')->first()->created_by));
    }

    public function test_stale_policy_form_cannot_overwrite_latest_policy_and_history_is_immutable(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $this->nominate([], 1, true);
        try {
            $this->nominate(['user:'.$this->owner->id], 1);
            $this->fail('Expected stale form refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('pms_access', $e->errors());
        }
        $this->bound(function (): void {
            $this->assertSame(0, DB::table('pms_author_policies')->update(['reason' => 'Forged']));
            $this->assertSame(0, DB::table('pms_author_policies')->delete());
            $this->assertSame(2, DB::table('pms_author_policies')->count());
        }, $this->owner);
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->writer));
    }

    public function test_database_audit_guard_rejects_revoked_actor_and_old_policy_id(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $this->create();
        $event = $this->bound(fn () => (array) DB::table('pms_entry_events')->first());
        $event['id'] = (string) Str::uuid();
        $this->nominate([], 1, true);
        $this->sqlRefused(fn () => DB::table('pms_entry_events')->insert($event), '42501');
        $this->nominate(['user:'.$this->writer->id], 2);
        $this->sqlRefused(fn () => DB::table('pms_entry_events')->insert($event), '23514');
        $this->assertSame(1, $this->bound(fn () => DB::table('pms_entry_events')->count()));
    }

    public function test_configuration_ui_is_bilingual_and_escaped_and_not_a_global_identity_list(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        app(PmsAccessService::class)->configure($this->project, $this->owner, ['version' => 1, 'suspend' => true, 'reason' => '<script>bad()</script>']);
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $this->actingAs($this->owner)->get('/projects/PH-14/piping/pms?lang='.$lang)->assertOk()
                ->assertSee('id="pms-access-form"', false)->assertSee('dir="'.$dir.'"', false)
                ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>bad()</script>', false);
        }
        $this->actingAs($this->writer)->get('/projects/PH-14/piping/pms?lang=en')->assertOk()->assertDontSee('id="pms-access-form"', false)->assertSee('Policy version');
        $outsider = $this->userByEmail('hosseini@arvand.example');
        $this->actingAs($outsider)->get('/projects/PH-14/piping/pms')->assertNotFound();
        $this->actingAs($this->writer)->post('/projects/BB-2/piping/pms/access', ['access' => ['version' => 0, 'suspend' => true, 'reason' => 'Cross-project']])->assertForbidden();
    }

    public function test_notes_sql_archive_backstop_preserves_reads_and_handles_empty_context(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $class = $this->create();
        app(PmsEntryService::class)->saveNote($this->project, $this->writer, $class->id, ['version' => 1, 'number' => 1, 'text_en' => 'Retained note', 'reason' => 'Original']);
        $note = $this->bound(fn () => PipingClassNote::where('piping_class_id', $class->id)->first());
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Test archive'), $this->owner);
        $this->sqlRefused(fn () => PipingClassNote::create(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id, 'piping_class_id' => $class->id, 'number' => 2, 'text_en' => 'Forbidden']), '42501');
        $this->sqlRefused(fn () => DB::table('piping_class_notes')->where('id', $note->id)->update(['text_en' => 'Forbidden']), '42501');
        $this->bound(function () use ($note): void {
            $this->assertSame(0, DB::table('piping_class_notes')->where('id', $note->id)->delete());
            $this->assertSame('Retained note', $note->fresh()->text_en);
        });
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->writer));
        $this->assertSame(0, DB::table('piping_class_notes')->count());
        $this->assertSame(0, DB::table('pms_author_policies')->count());
    }

    public function test_forward_migration_keeps_old_content_events_and_absence_of_policy(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_09_29_235700'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->identities();
        [$class, $eventId] = $this->bound(function (): array {
            $class = PipingClass::create(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id, 'code' => 'LEGACY-AUTH']);
            $class->forceFill(['entry_version' => 1, 'source_text' => 'Historic source'])->save();
            $id = (string) Str::uuid();
            DB::table('pms_entry_events')->insert(['id' => $id, 'project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
                'piping_class_id' => $class->id, 'item_id' => null, 'created_by' => $this->writer->id, 'actor_name' => $this->writer->name,
                'created_at' => now(), 'version' => 1, 'reason' => 'Historic entry', 'before_data' => null, 'after_data' => json_encode($class->toArray())]);

            return [$class, $id];
        });
        $before = $this->bound(fn () => (array) DB::table('pms_entry_events')->where('id', $eventId)->first());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->bound(function () use ($class, $eventId, $before): void {
            $after = (array) DB::table('pms_entry_events')->where('id', $eventId)->first();
            $this->assertNull($after['author_policy_id']);
            unset($after['author_policy_id']);
            $this->assertSame($before, $after);
            $this->assertSame('Historic source', $class->fresh()->source_text);
            $this->assertSame(0, DB::table('pms_author_policies')->count());
        });
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->writer));
    }

    public function test_project_ownership_without_project_configure_cannot_change_policy(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $this->provisionIdentity(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->owner->id)->update(['role_codes' => []]));
        $this->actingAs($this->owner)->post('/projects/PH-14/piping/pms/access', ['access' => ['version' => 1, 'suspend' => true, 'reason' => 'Unauthorized']])->assertForbidden();
        $this->assertSame(1, $this->bound(fn () => DB::table('pms_author_policies')->count()));
        $this->assertTrue(app(PmsAccessService::class)->allows($this->project, $this->writer));
    }

    public function test_audit_waiting_on_policy_lock_observes_committed_revocation(): void
    {
        $this->nominate(['user:'.$this->writer->id]);
        $this->create();
        $event = $this->bound(fn () => (array) DB::table('pms_entry_events')->first());
        $event['id'] = (string) Str::uuid();
        $config = config('database.connections.pgsql');
        $escape = fn ($v) => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $v)."'";
        $connectionString = implode(' ', array_map(fn ($key) => $key.'='.$escape($config[$key]), ['host', 'port', 'username', 'password']));
        $connectionString = str_replace('username=', 'user=', $connectionString).' dbname='.$escape($config['database']);
        $other = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
        pg_query($other, "SET statement_timeout='5s'");
        pg_query_params($other, "SELECT set_config('epcc.company_id',$1,false),set_config('epcc.project_id',$2,false)", [$this->writer->company_id, $this->project->id]);
        $pid = (int) pg_fetch_result(pg_query($other, 'SELECT pg_backend_pid()'), 0, 0);
        $columns = array_keys($event);
        $sql = 'INSERT INTO pms_entry_events ('.implode(',', $columns).') VALUES ('.implode(',', array_map(fn ($n) => '$'.$n, range(1, count($columns)))).')';
        try {
            $this->bound(function () use ($other, $pid, $sql, $event): void {
                $this->nominate([], 1, true);
                $this->assertTrue(pg_send_query_params($other, $sql, array_values($event)));
                $waiting = false;
                $deadline = microtime(true) + 2;
                do {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $activity = DB::selectOne('SELECT wait_event FROM pg_stat_activity WHERE pid=?', [$pid]);
                    if (($activity->wait_event ?? null) === 'advisory') {
                        $waiting = true;
                        break;
                    }
                    usleep(10000); // Real two-connection synchronization, not application timing.
                } while (microtime(true) < $deadline);
                $this->assertTrue($waiting, 'Second connection did not reach the policy-lock barrier.');
            }, $this->owner);
            $result = pg_get_result($other);
            $this->assertSame('42501', pg_result_error_field($result, PGSQL_DIAG_SQLSTATE));
            $this->assertStringContainsString('PMS_AUTHOR_NOT_NOMINATED', pg_result_error($result));
        } finally {
            pg_close($other);
        }
        $this->assertSame(1, $this->bound(fn () => DB::table('pms_entry_events')->count()));
        $this->assertFalse(app(PmsAccessService::class)->allows($this->project, $this->writer));
    }
}
