<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\PdfRenderer;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationDraftHistoryTest extends TestCase
{
    private User $writer;

    private User $owner;

    private User $recipient;

    private Project $project;

    private string $revision;

    private string $stage;

    private string $id;

    private string $base = '/projects/PH-14/inspection-notifications';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->recipient = $this->userByEmail('jafari@karan.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->project->update(['name_en' => 'SYNTHETIC PROJECT RECORD', 'name_fa' => 'پروژهٔ آزمایشی — SYNTHETIC']);
        $doc = $this->asWriter(fn () => app(DocumentRegister::class)->register($this->project, $this->writer, [
            'discipline' => 'PIPING', 'doc_type' => 'ITP', 'title_en' => 'Synthetic ITP reference', 'title_fa' => 'سند آزمایشی بازرسی',
        ]));
        $this->revision = $doc->current_revision_id;
        $this->stage = $this->service()->addStage($this->project, $this->writer, [
            'revision_id' => $this->revision, 'sequence' => 1, 'point' => 'W', 'requires_notification' => true,
            'description_en' => 'Recorded stage <script>stage()</script>', 'description_fa' => 'بازرسی مرحلهٔ پیش‌نویس گچ ژاله',
        ]);
        $this->service()->configure($this->project, $this->owner, [
            'version' => 0, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$this->writer->id], 'issue_users' => [$this->writer->id], 'reason' => 'Synthetic PDF fixture',
        ]);
        $this->id = $this->service()->createDraft($this->project, $this->writer, $this->draft());
    }

    private function service(): InspectionNotificationService
    {
        return app(InspectionNotificationService::class);
    }

    private function asWriter(callable $f): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $f, $this->project->id);
    }

    private function row(): object
    {
        return $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $this->id)->first());
    }

    private function issue(): void
    {
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 1]);
    }

    private function url(string $lang = 'en'): string
    {
        return $this->base.'/'.$this->id.'.pdf?lang='.$lang;
    }

    private function draft(): array
    {
        return ['itp_revision_id' => $this->revision, 'itp_stage_id' => $this->stage, 'work_reference' => 'SYNTHETIC FIELD INSPECTION / نمونهٔ آزمایشی', 'activity_start_at' => '2026-10-25T09:30:00+03:30', 'activity_end_at' => '2026-10-25T11:30:00+03:30', 'location' => 'Synthetic site / محل آزمایشی', 'recipient_company_ids' => [$this->recipient->company_id], 'notes_en' => '<img src="https://invalid.example/a"> & <script>notes()</script>', 'notes_fa' => 'ارجاع‌شده برای تأیید حضور، نه تأیید نتیجه'];
    }

    private function data(int $version = 1): array
    {
        $d = $this->draft();
        unset($d['itp_stage_id'],$d['itp_revision_id']);

        return ['version' => $version] + $d;
    }

    private function edit(?array $d = null): void
    {
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $d ?? $this->data());
    }

    private function entries()
    {
        return $this->asWriter(fn () => DB::table('inspection_notification_draft_history')->where('notification_id', $this->id)->orderBy('after_version')->get());
    }

    private function endpoint(): string
    {
        return $this->base.'/'.$this->id;
    }

    private function withoutHistorySchema(): void
    {
        // Disposable prior-schema reconstruction only; parent guards/RLS remain active.
        DB::unprepared('DROP TRIGGER notification_capture_draft_edit ON inspection_notifications; DROP TABLE inspection_notification_draft_history;');
    }

    private function upgradeHistory(): void
    {
        $m = require database_path('migrations/2026_10_20_120000_add_inspection_notification_draft_history.php');
        $m->up();
    }

    public function test_successful_save_retains_complete_before_and_after_with_database_actor_time_setting_and_no_source_mutation(): void
    {
        $before = $this->row();
        $this->assertCount(0, $this->entries());
        $d = $this->data();
        $d['location'] = 'Changed location';
        $d['notes_en'] = '<script>HISTORY_ONLY_SCRIPT()</script>';
        $this->edit($d);
        $entry = $this->entries()->sole();
        $a = json_decode($entry->before_snapshot, true);
        $b = json_decode($entry->after_snapshot, true);
        $this->assertSame(1, $entry->before_version);
        $this->assertSame(2, $entry->after_version);
        $this->assertSame($this->writer->id, $entry->actor_id);
        $this->assertSame($this->writer->name, $entry->actor_name);
        $this->assertSame($this->row()->last_edit_setting_id, $entry->edit_setting_id);
        $this->assertSame(1, $a['schema_version']);
        $this->assertSame($before->location, $a['location']);
        $this->assertSame('Changed location', $b['location']);
        $this->assertSame($d['notes_en'], $b['notes_en']);
        foreach (['id', 'project_id', 'company_id', 'stage_id', 'created_by', 'entry_setting_id', 'created_at'] as $key) {
            $this->assertSame($a[$key], $b[$key]);
        }
        $this->assertSame($before->activity_start_input, $a['activity_start_input']);
        $this->assertSame($a['activity_start_input'], $b['activity_start_input']);
        $this->assertSame('2026-10-25T06:00:00.000000+00:00', $b['activity_start_at']);
        $this->assertSame(json_decode($before->recipient_company_ids, true), $a['recipient_company_ids']);
        $this->assertStringContainsString('+00:00', $b['last_edited_at']);
        $this->assertNotNull($entry->recorded_at);
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->writer)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee('inspection-notification-draft-history')->assertSee('&lt;script&gt;HISTORY_ONLY_SCRIPT()&lt;/script&gt;', false)->assertDontSee('<script>HISTORY_ONLY_SCRIPT()', false);
        }
    }

    public function test_unchanged_successful_save_is_still_one_record_and_null_is_not_empty_text(): void
    {
        $this->edit();
        $e = $this->entries()->sole();
        $a = json_decode($e->before_snapshot, true);
        $b = json_decode($e->after_snapshot, true);
        foreach (['work_reference', 'location', 'notes_en', 'notes_fa', 'activity_start_input', 'activity_end_input', 'recipient_company_ids', 'purchase_order_id'] as $key) {
            $this->assertSame($a[$key], $b[$key]);
        }
        $this->actingAs($this->writer)->get($this->endpoint().'?lang=en')->assertSee(__('inspection_notifications.draft_history_unchanged'));
        $d = $this->data(2);
        $d['notes_en'] = null;
        $this->edit($d);
        $d['version'] = 3;
        $d['notes_en'] = '';
        $this->edit($d);
        $e = $this->entries()->last();
        $this->assertNull(json_decode($e->before_snapshot, true)['notes_en']);
        $this->assertSame('', json_decode($e->after_snapshot, true)['notes_en']);
        $this->assertCount(3, $this->entries());
    }

    public function test_optional_po_removal_and_recipient_changes_record_only_references_not_commercial_metadata(): void
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Synthetic original-visible order']);
        $vendor = $this->asWriter(fn () => Company::where('code', 'VEN-AZAR')->value('id'));
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, ['discipline' => 'PIPING', 'material_category' => 'PIPE', 'title_en' => 'COMMERCIAL-TITLE-NOT-IN-HISTORY', 'title_fa' => 'سفارش آزمایشی', 'vendor_company_id' => $vendor, 'lines' => [['item_code' => 'P-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '1']]]);
        $d = $this->draft();
        $d['purchase_order_id'] = $po->id;
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        $d = $this->data();
        $d['purchase_order_id'] = null;
        $d['recipient_company_ids'] = [$this->owner->company_id];
        $this->edit($d);
        $e = $this->entries()->sole();
        $a = json_decode($e->before_snapshot, true);
        $b = json_decode($e->after_snapshot, true);
        $this->assertSame($po->id, $a['purchase_order_id']);
        $this->assertNull($b['purchase_order_id']);
        $this->assertSame([$this->recipient->company_id], $a['recipient_company_ids']);
        $this->assertSame([$this->owner->company_id], $b['recipient_company_ids']);
        $this->assertStringNotContainsString('COMMERCIAL-TITLE-NOT-IN-HISTORY', $e->before_snapshot.$e->after_snapshot);
    }

    public function test_stale_invalid_revoked_and_foreign_edits_produce_no_history_or_mutation(): void
    {
        $this->edit();
        $before = $this->row();
        $history = $this->entries();
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $this->data()])->assertUnprocessable();
        $d = $this->data(2);
        $d['activity_end_at'] = $d['activity_start_at'];
        $this->putJson($this->endpoint(), ['notification' => $d])->assertUnprocessable();
        $this->actingAs($this->recipient)->putJson($this->endpoint(), ['notification' => $this->data(2)])->assertNotFound();
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [], 'issue_users' => [$this->writer->id], 'reason' => 'Revoked draft writing']);
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $this->data(2)])->assertForbidden();
        $this->assertEquals($before, $this->row());
        $this->assertEquals($history, $this->entries());
    }

    public function test_late_failure_rolls_back_history_and_body_and_retry_has_exactly_one_record(): void
    {
        $before = $this->row();
        $this->asWriter(fn () => DB::unprepared("CREATE OR REPLACE FUNCTION notification_history_late_test() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'NOTIFICATION_HISTORY_TEST_LATE'; END \$\$; CREATE TRIGGER zz_notification_history_late_test AFTER UPDATE ON inspection_notifications FOR EACH ROW EXECUTE FUNCTION notification_history_late_test();"));
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $this->data()])->assertUnprocessable();
        $this->assertEquals($before, $this->row());
        $this->assertCount(0, $this->entries());
        $this->asWriter(fn () => DB::statement('DROP TRIGGER zz_notification_history_late_test ON inspection_notifications'));
        $this->edit();
        $this->assertCount(1, $this->entries());
        $this->assertSame(1, $this->entries()->sole()->before_version);
    }

    public function test_history_capture_failure_aborts_the_parent_edit(): void
    {
        $before = $this->row();
        $this->asWriter(fn () => DB::unprepared("CREATE OR REPLACE FUNCTION notification_history_insert_test() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'NOTIFICATION_HISTORY_TEST_INSERT'; END \$\$; CREATE TRIGGER zz_notification_history_insert_test BEFORE INSERT ON inspection_notification_draft_history FOR EACH ROW EXECUTE FUNCTION notification_history_insert_test();"));
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $this->data()])->assertUnprocessable();
        $this->assertEquals($before, $this->row());
        $this->assertCount(0, $this->entries());
    }

    public function test_history_rows_are_append_only_and_direct_forged_inserts_are_not_an_api(): void
    {
        $this->edit();
        $e = $this->entries()->sole();
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_draft_history')->where('id', $e->id)->update(['actor_name' => 'FORGED'])));
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_draft_history')->where('id', $e->id)->delete()));
        try {
            $this->asWriter(function () use ($e): void {
                $copy = (array) $e;
                $copy['id'] = (string) Str::uuid();
                DB::table('inspection_notification_draft_history')->insert($copy);
            });
            $this->fail('Expected capture-only insertion guard');
        } catch (QueryException $ex) {
            $this->assertStringContainsString('NOTIFICATION_HISTORY_CAPTURE_ONLY', $ex->getMessage());
        }
        $this->assertEquals($e, $this->entries()->sole());
    }

    public function test_recipient_gets_only_current_issued_body_not_prior_private_draft_history_or_raw_rows(): void
    {
        $d = $this->draft();
        $d['notes_en'] = 'PRIVATE-PRIOR-DRAFT-BODY';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        $d = $this->data();
        $d['notes_en'] = 'Current issued notes';
        $this->edit($d);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $history = $this->entries();
        $this->assertCount(1, $history);
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->recipient)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee('Current issued notes')->assertDontSee('PRIVATE-PRIOR-DRAFT-BODY')->assertDontSee('inspection-notification-draft-history')->assertDontSee('before_snapshot');
        }
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('inspection_notification_draft_history', $sql);
        }
        $this->assertSame(0, app(TenantContext::class)->runAs($this->recipient->company_id, fn () => DB::table('inspection_notification_draft_history')->count(), $this->project->id));
        $this->actingAs($this->writer)->get($this->endpoint())->assertOk()->assertSee('PRIVATE-PRIOR-DRAFT-BODY');
        $this->assertEquals($history, $this->entries());
        $other = Project::where('code', 'BB-2')->firstOrFail();
        $this->assertSame(0, app(TenantContext::class)->runAs($this->writer->company_id, fn () => DB::table('inspection_notification_draft_history')->count(), $other->id));
    }

    public function test_original_company_readers_keep_history_after_write_revocation_archival_or_person_name_change_without_source_joins(): void
    {
        $oldName = $this->writer->name;
        $this->edit();
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['name' => 'RENAMED CURRENT PERSON']));
        $this->edit($this->data(2));
        $this->assertSame($oldName, $this->entries()->first()->actor_name);
        $this->assertSame('RENAMED CURRENT PERSON', $this->entries()->last()->actor_name);
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [], 'issue_users' => [], 'reason' => 'History still readable after revoke']);
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['role_codes' => ['qc_inspector']]));
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Historical read only');
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->actingAs($this->writer)->get($this->endpoint())->assertOk()->assertSee($oldName)->assertSee('RENAMED CURRENT PERSON')->assertSee('inspection-notification-draft-history');
        foreach ($queries as $sql) {
            foreach (['"documents"', '"document_revisions"', '"purchase_orders"'] as $source) {
                $this->assertStringNotContainsString($source, $sql);
            }
        }
    }

    public function test_plan_discipline_active_status_and_departed_company_cannot_read_history(): void
    {
        $this->edit();
        $starter = Plan::where('code', 'starter')->value('id');
        $full = Plan::where('code', 'full')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->actingAs($this->writer)->getJson($this->endpoint())->assertForbidden();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->getJson($this->endpoint())->assertNotFound();
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => null]));
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['is_active' => false]));
        $this->getJson($this->endpoint())->assertNotFound();
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['is_active' => true]));
        $bridge = $this->asWriter(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->writer->company_id)->first());
        $this->asWriter(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->writer->company_id)->delete());
        $this->getJson($this->endpoint())->assertNotFound();
        $this->assertCount(0, $this->entries());
        $this->asWriter(fn () => DB::table('project_company')->insert((array) $bridge));
        $this->assertCount(1, $this->entries());
    }

    public function test_all_history_is_reachable_in_stable_twenty_five_row_pages(): void
    {
        for ($v = 1; $v <= 27; $v++) {
            $d = $this->data($v);
            $d['location'] = 'Location '.$v;
            $this->edit($d);
        }
        $r = $this->actingAs($this->writer)->get($this->endpoint().'?lang=en')->assertOk()->assertSee('history_page=2', false);
        $pager = $r->viewData('draftHistory');
        $this->assertSame(27, $pager->total());
        $this->assertCount(25, $pager->items());
        $this->assertSame(28, $pager->items()[0]->after_version);
        $r = $this->get($this->endpoint().'?lang=en&history_page=2')->assertOk();
        $this->assertCount(2, $r->viewData('draftHistory')->items());
        $this->assertSame(1, $r->viewData('historyFromVersion'));
    }

    public function test_forward_install_does_not_fabricate_prior_edits_and_first_new_capture_starts_at_actual_version(): void
    {
        $this->withoutHistorySchema();
        $this->edit();
        $this->edit($this->data(2));
        $before = $this->row();
        $this->upgradeHistory();
        $this->assertEquals($before, $this->row());
        $this->assertCount(0, $this->entries());
        $this->actingAs($this->writer)->get($this->endpoint().'?lang=en')->assertOk()->assertSee(__('inspection_notifications.draft_history_coverage'));
        $this->edit($this->data(3));
        $e = $this->entries()->sole();
        $this->assertSame(3, $e->before_version);
        $this->assertSame(4, $e->after_version);
        $r = $this->get($this->endpoint().'?lang=en')->assertOk();
        $this->assertSame(3, $r->viewData('historyFromVersion'));
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 4]);
        $this->assertCount(1, $this->entries());
        $this->assertSame(5, $this->row()->version);
    }

    public function test_forward_install_on_archived_populated_project_keeps_issued_and_draft_data_exactly(): void
    {
        $this->withoutHistorySchema();
        $this->issue();
        $issued = $this->row();
        $draftId = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        $draft = $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $draftId)->first());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Populated archived history upgrade');
        $this->upgradeHistory();
        $this->assertEquals($issued, $this->row());
        $this->assertEquals($draft, $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $draftId)->first()));
        $this->assertCount(0, $this->entries());
        $this->actingAs($this->recipient)->get($this->endpoint())->assertOk()->assertDontSee('inspection-notification-draft-history');
    }

    public function test_independent_same_version_edits_capture_exactly_one_history_event(): void
    {
        $id = $this->id;
        $workers = [];
        $this->asWriter(function () use ($id, &$workers): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-notification:'.$this->project->id]);
            for ($i = 0; $i < 2; $i++) {
                $p = new Process([PHP_BINARY, base_path('../scripts/tests/inspection_notification_worker.php'), $this->writer->id, $this->project->id, $id, 'edit', json_encode($this->data())], base_path(), ['APP_ENV' => 'testing', 'EPCC_NOTIFICATION_TEST' => 'isolated']);
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            // waitUntil() can consume an already-buffered readiness line in its
            // initial updateStatus before installing the callback. Read the
            // accumulated buffer instead, and prove both DB sessions are waiting
            // on the exact held advisory key before releasing the barrier.
            $deadline = microtime(true) + 8;
            do {
                $ready = true;
                foreach ($workers as $p) {
                    $p->checkTimeout();
                    $ready = str_contains($p->getOutput(), 'ready') && $ready;
                }
                $waiting = (int) DB::selectOne("WITH k AS (SELECT hashtextextended(?,0) v) SELECT count(*) n FROM pg_locks,k WHERE locktype='advisory' AND NOT granted AND classid::bigint=((v >> 32) & 4294967295) AND objid::bigint=(v & 4294967295) AND objsubid=1", ['inspection-notification:'.$this->project->id])->n;
                if ($ready && $waiting === 2) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $messages = implode(' | ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $workers));
                    foreach ($workers as $p) {
                        $p->stop(0.1);
                    }
                    $this->fail('Worker barrier not reached: '.$messages);
                }
                usleep(1000);
            } while (true);
            $this->assertSame(2, $waiting);
        });
        $codes = [];
        foreach ($workers as $p) {
            $codes[] = $p->wait();
            $this->assertNotSame(3, $p->getExitCode(), $p->getErrorOutput());
        }
        sort($codes);
        $this->assertSame([0, 2], $codes);
        $this->assertSame('DRAFT', $this->row()->state);
        $this->assertCount(1, $this->entries());
        $this->assertSame(1, $this->entries()->sole()->before_version);
        $this->assertSame(2, $this->entries()->sole()->after_version);
        $this->assertSame(2, $this->row()->version);
        $this->assertSame($this->row()->state === 'ISSUED' ? 1 : null, $this->row()->sequence);
        $this->assertSame(2, $this->row()->version);
    }

    public function test_explicit_second_editor_is_attributed_separately_from_the_original_creator(): void
    {
        $editor = $this->asWriter(function () {
            $u = $this->writer->replicate();
            $u->id = (string) Str::uuid();
            $u->email = 'history-editor@synthetic.example';
            $u->name = 'Synthetic historical second editor';
            $u->save();
            $m = ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->firstOrFail()->replicate();
            $m->id = (string) Str::uuid();
            $m->user_id = $u->id;
            $m->save();

            return $u;
        });
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$editor->id], 'issue_users' => [$this->writer->id], 'reason' => 'Explicit second editor']);
        $this->service()->updateDraft($this->project, $editor, $this->id, $this->data());
        $e = $this->entries()->sole();
        $this->assertSame($editor->id, $e->actor_id);
        $this->assertSame($editor->name, $e->actor_name);
        $this->assertSame($this->writer->id, json_decode($e->after_snapshot, true)['created_by']);
    }

    public function test_recipient_pdf_contains_current_issue_only_and_never_private_history_or_a_history_query(): void
    {
        if (! app(PdfRenderer::class)->isAvailable() || trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('Actual Chromium and pdftotext required.');
        }
        $d = $this->draft();
        $d['notes_en'] = 'PRIVATEHISTORYBODY';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        $d = $this->data();
        $d['notes_en'] = 'CURRENTISSUEDBODY';
        $this->edit($d);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $r = $this->actingAs($this->recipient)->get($this->url())->assertOk()->assertHeader('content-type', 'application/pdf');
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('inspection_notification_draft_history', $sql);
        }
        $file = tempnam(sys_get_temp_dir(), 'notification-history-pdf-');
        try {
            file_put_contents($file, $r->getContent());
            $p = new Process(['pdftotext', '-enc', 'UTF-8', $file, '-']);
            $p->mustRun();
            $this->assertStringContainsString('CURRENTISSUEDBODY', $p->getOutput());
            $this->assertStringNotContainsString('PRIVATEHISTORYBODY', $p->getOutput());
        } finally {
            @unlink($file);
        }
        if (getenv('EPCC_NOTIFICATION_HISTORY_EVIDENCE') === '1') {
            file_put_contents(base_path('../docs/evidence/2026-10-20-inspection-notification-draft-history/recipient-current-issued.pdf'), $r->getContent());
        }
    }
}
