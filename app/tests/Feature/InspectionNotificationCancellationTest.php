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
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationCancellationTest extends TestCase
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

    private function browser(): void
    {
        if (! app(PdfRenderer::class)->isAvailable()) {
            $this->markTestSkipped('Headless Chromium required for actual PDF verification.');
        }
    }

    private function inspect(string $bytes, string $tool = 'pdftotext'): string
    {
        if (trim((string) shell_exec('command -v '.escapeshellarg($tool))) === '') {
            $this->markTestSkipped($tool.' required.');
        }
        $file = tempnam(sys_get_temp_dir(), 'notification-pdf-');
        try {
            file_put_contents($file, $bytes);
            $args = $tool === 'pdftotext' ? [$tool, '-enc', 'UTF-8', $file, '-'] : [$tool, $file];
            $p = new Process($args);
            $p->mustRun();

            return preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $p->getOutput()) ?? $p->getOutput();
        } finally {
            @unlink($file);
        }
    }

    private function artifact(string $name, string $bytes): void
    {
        if (getenv('EPCC_NOTIFICATION_CANCEL_EVIDENCE') === '1') {
            $dir = base_path('../docs/evidence/2026-10-20-inspection-notification-cancellation');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.'/'.$name, $bytes);
        }
    }

    private function cancelUrl(): string
    {
        return $this->base.'/'.$this->id.'/cancel?lang=en';
    }

    private function cancel(string $reason = 'CANCELLATIONREASON / دلیل ابطال'): void
    {
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => $this->row()->version, 'reason' => $reason]);
    }

    private function events()
    {
        return $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->where('notification_id', $this->id)->get());
    }

    private function raw(array $overrides = []): void
    {
        $row = $this->row();
        $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->insert(array_replace([
            'project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
            'notification_id' => $this->id, 'issued_version' => $row->version, 'actor_id' => $this->writer->id, 'reason' => 'Raw cancellation',
        ], $overrides)));
    }

    private function rawRefused(array $overrides = []): void
    {
        try {
            $this->raw($overrides);
            $this->fail('Expected SQL cancellation refusal');
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOTIFICATION_CANCELLATION_', $e->getMessage());
        }
    }

    public function test_current_issuer_cancels_once_without_rewriting_any_issued_or_history_body_and_bilingual_readers_see_event(): void
    {
        $this->issue();
        $before = $this->row();
        $history = $this->asWriter(fn () => DB::table('inspection_notification_draft_history')->get());
        $this->actingAs($this->writer)->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'CANCELLATIONREASON <script>alert(1)</script> / دلیل ابطال']])->assertRedirect();
        $e = $this->events()->sole();
        $this->assertSame($this->writer->id, $e->actor_id);
        $this->assertSame($this->writer->name, $e->actor_name);
        $this->assertSame(2, $e->issued_version);
        $this->assertSame('PIPING', $e->discipline);
        $this->assertNotNull($e->recorded_at);
        $this->assertEquals($before, $this->row());
        $this->assertEquals($history, $this->asWriter(fn () => DB::table('inspection_notification_draft_history')->get()));
        foreach ([$this->writer, $this->recipient] as $reader) {
            foreach (['en', 'fa'] as $lang) {
                $r = $this->actingAs($reader)->get($this->base.'/'.$this->id.'?lang='.$lang)->assertOk()->assertSee('CANCELLED')->assertSee('CANCELLATIONREASON')->assertSee($e->actor_name)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('id="inspection-notification-cancel"', false);
                $this->assertNotNull($r->viewData('cancellation'));
                $this->assertFalse($r->viewData('canCancel'));
                if ($reader->id === $this->recipient->id) {
                    $r->assertDontSee('inspection-notification-draft-history');
                    $this->artifact('cancelled-detail-'.$lang.'.html', preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-test-token"', $r->getContent()));
                }
            }
        }
        $this->actingAs($this->writer)->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'Another reason']])->assertUnprocessable();
        $this->assertEquals($e, $this->events()->sole());
        $this->postJson($this->base.'/'.$this->id.'/issue', ['issuance' => ['version' => 2]])->assertUnprocessable();
        $this->assertEquals($before, $this->row());
    }

    public function test_cancel_form_is_only_for_current_own_company_issuer_and_draft_only_grant_cannot_cancel(): void
    {
        $this->issue();
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->writer)->get($this->base.'/'.$this->id.'?lang='.$lang)->assertOk()->assertSee('id="inspection-notification-cancel"', false)->assertSee('name="cancellation[reason]"', false);
            $this->actingAs($this->recipient)->get($this->base.'/'.$this->id.'?lang='.$lang)->assertOk()->assertDontSee('id="inspection-notification-cancel"', false);
        }
        foreach ([$this->recipient, $this->owner] as $user) {
            $this->actingAs($user)->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'No delegation']])->assertNotFound();
        }
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$this->writer->id], 'issue_users' => [], 'reason' => 'Revoke issue permission']);
        $this->actingAs($this->writer)->get($this->base.'/'.$this->id)->assertOk()->assertDontSee('id="inspection-notification-cancel"', false);
        $this->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'Revoked']])->assertForbidden();
        $this->rawRefused();
        $this->assertCount(0, $this->events());
    }

    public function test_another_currently_nominated_issuer_can_cancel_without_being_the_original_issuer(): void
    {
        $this->issue();
        $other = $this->userByEmail('sadeghi@pars.example');
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [], 'issue_users' => [$other->id], 'reason' => 'Current explicit issuer']);
        $this->actingAs($other)->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'Authorized successor']])->assertRedirect();
        $e = $this->events()->sole();
        $this->assertSame($other->id, $e->actor_id);
        $this->assertNotSame($this->row()->issued_by, $e->actor_id);
        $this->assertSame($this->asWriter(fn () => $this->service()->latest($this->project))->id, $e->cancel_setting_id);
    }

    public function test_drafts_stale_versions_empty_overlong_or_injected_payloads_make_no_event(): void
    {
        $this->actingAs($this->writer);
        $this->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'Draft cannot be cancelled']])->assertUnprocessable();
        $this->rawRefused();
        $this->issue();
        $before = $this->row();
        foreach ([[], ['version' => 1, 'reason' => 'Stale'], ['version' => 3, 'reason' => 'Future'], ['version' => 2, 'reason' => " \t\n "], ['version' => 2, 'reason' => "\u{00A0}"], ['version' => 2, 'reason' => str_repeat('x', 4001)], ['version' => 2, 'reason' => ['array']], ['version' => 2, 'reason' => 'Valid', 'actor_id' => $this->owner->id]] as $bad) {
            $this->postJson($this->cancelUrl(), ['cancellation' => $bad])->assertUnprocessable();
        }
        $this->assertCount(0, $this->events());
        $this->assertEquals($before, $this->row());
    }

    public function test_sql_guard_captures_server_identity_time_and_setting_and_rejects_forgery_or_mutation(): void
    {
        $this->issue();
        $before = $this->row();
        $this->rawRefused(['issued_version' => 999]);
        $this->rawRefused(['actor_id' => $this->recipient->id]);
        $this->rawRefused(['project_id' => Project::where('code', 'BB-2')->value('id')]);
        $this->rawRefused(['company_id' => $this->recipient->company_id]);
        $this->raw(['actor_name' => 'FORGED NAME', 'recorded_at' => '1999-01-01T00:00:00+00:00', 'cancel_setting_id' => (string) Str::uuid(), 'discipline' => 'CIVIL']);
        $e = $this->events()->sole();
        $this->assertSame($this->writer->name, $e->actor_name);
        $this->assertSame('PIPING', $e->discipline);
        $this->assertNotSame('1999-01-01', substr($e->recorded_at, 0, 10));
        foreach (['update', 'delete'] as $op) {
            try {
                $this->asWriter(function () use ($op): void {
                    $q = DB::table('inspection_notification_cancellations')->where('notification_id', $this->id);
                    $op === 'update' ? $q->update(['reason' => 'Rewrite']) : $q->delete();
                });
                $this->fail('Expected append-only guard');
            } catch (QueryException $ex) {
                $this->assertStringContainsString('NOTIFICATION_CANCELLATION_IMMUTABLE', $ex->getMessage());
            }
        }
        $this->rawRefused();
        $this->assertEquals($e, $this->events()->sole());
        $this->assertEquals($before, $this->row());
    }

    public function test_effective_register_state_filters_keep_cancelled_evidence_visible_with_scoped_counts_and_no_reason_search(): void
    {
        $this->issue();
        $this->cancel();
        $private = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        foreach ([$this->writer, $this->recipient] as $reader) {
            $this->actingAs($reader);
            $r = $this->get($this->base.'?notice_state=CANCELLED&lang=fa')->assertOk()->assertSee('CANCELLED');
            $this->assertSame([$this->id], collect($r->viewData('notifications')->items())->pluck('id')->all());
            $this->assertSame($reader->id === $this->writer->id ? 2 : 1, $r->viewData('noticeVisibleTotal'));
            $r = $this->get($this->base.'?notice_state=ISSUED')->assertOk();
            $this->assertSame(0, $r->viewData('notifications')->total());
            $r = $this->get($this->base.'?notice_q=CANCELLATIONREASON')->assertOk();
            $this->assertSame(0, $r->viewData('notifications')->total());
            $r = $this->get($this->base.'?notice_state=CANCELLED&activity_from=2026-10-25T06%3A00%3A00%2B00%3A00')->assertOk();
            $this->assertSame(1, $r->viewData('notifications')->total());
        }
        $this->actingAs($this->owner)->get($this->base.'?notice_state=CANCELLED')->assertOk()->assertDontSee($this->row()->number);
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['name' => 'LATER NAME']));
        $this->actingAs($this->recipient)->get($this->base.'/'.$this->id)->assertOk()->assertSee($this->writer->name)->assertDontSee('LATER NAME');
        $rows = app(TenantContext::class)->runAs($this->recipient->company_id, fn () => DB::table('inspection_notification_cancellations')->get(), $this->project->id);
        $this->assertCount(1, $rows);
        $rows = app(TenantContext::class)->runAs($this->owner->company_id, fn () => DB::table('inspection_notification_cancellations')->get(), $this->project->id);
        $this->assertCount(0, $rows);
    }

    public function test_source_archival_does_not_prevent_withdrawal_but_project_archival_blocks_all_new_cancellations(): void
    {
        $this->issue();
        $second = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        $this->service()->issue($this->project, $this->writer, $second, ['version' => 1]);
        $this->asWriter(fn () => DB::table('document_revisions')->where('id', $this->revision)->update(['cde_state' => 'ARCHIVED']));
        $this->cancel();
        $this->assertCount(1, $this->events());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only project');
        $this->actingAs($this->writer)->postJson($this->base.'/'.$second.'/cancel', ['cancellation' => ['version' => 2, 'reason' => 'Archived']])->assertUnprocessable();
        $this->actingAs($this->recipient)->get($this->base.'/'.$this->id)->assertOk()->assertSee('CANCELLED');
    }

    public function test_current_active_discipline_pack_and_participation_gates_still_apply_to_cancellation_and_read(): void
    {
        $this->issue();
        $this->actingAs($this->writer);
        $body = ['cancellation' => ['version' => 2, 'reason' => 'Gate test']];
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->postJson($this->cancelUrl(), $body)->assertNotFound();
        $this->rawRefused();
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => null]));
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['is_active' => false]));
        $this->postJson($this->cancelUrl(), $body)->assertNotFound();
        $this->rawRefused();
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['is_active' => true]));
        $starter = Plan::where('code', 'starter')->value('id');
        $full = Plan::where('code', 'full')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->postJson($this->cancelUrl(), $body)->assertForbidden();
        $this->rawRefused();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        $this->cancel();
        app(TenantContext::class)->runAs($this->recipient->company_id, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]), $this->project->id);
        $this->actingAs($this->recipient)->getJson($this->base.'/'.$this->id)->assertNotFound();
        app(TenantContext::class)->runAs($this->recipient->company_id, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => null]), $this->project->id);
        $this->asWriter(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->recipient->company_id)->delete());
        $this->getJson($this->base.'/'.$this->id)->assertNotFound();
        $rows = app(TenantContext::class)->runAs($this->recipient->company_id, fn () => DB::table('inspection_notification_cancellations')->get(), $this->project->id);
        $this->assertCount(0, $rows);
    }

    public function test_real_bilingual_cancelled_pdf_retains_issued_content_and_current_event_but_never_private_draft_history(): void
    {
        $this->browser();
        $d = $this->draft();
        $d['notes_en'] = 'PRIVATECANCELDRAFTBODY';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        unset($d['itp_revision_id'], $d['itp_stage_id']);
        $d['version'] = 1;
        $d['notes_en'] = 'CURRENTISSUEDCANCELBODY';
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $d);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $this->cancel();
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->url($lang))->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $text = $this->inspect($r->getContent());
            $this->assertStringContainsString('CANCELLED', $text);
            $this->assertStringContainsString('CANCELLATIONREASON', $text);
            if ($lang === 'en') {
                $this->assertStringNotContainsString('Issued —', $text);
            }
            $this->assertStringContainsString('CURRENTISSUEDCANCELBODY', $text);
            $this->assertStringNotContainsString('PRIVATECANCELDRAFTBODY', $text);
            $this->assertStringContainsString($before->number, $text);
            $this->assertStringContainsString('no-store', $r->headers->get('cache-control'));
            $this->artifact('cancelled-'.$lang.'.pdf', $r->getContent());
            $this->artifact('cancelled-'.$lang.'.txt', $text);
        }
        $this->assertEquals($before, $this->row());
        $this->assertCount(1, $this->events());
    }

    public function test_forward_install_on_populated_archived_project_creates_no_cancellations_or_body_changes(): void
    {
        $this->issue();
        $before = $this->row();
        $draft = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        $draftBefore = $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $draft)->first());
        DB::statement('DROP TABLE inspection_notification_acknowledgments');
        DB::statement('DROP TABLE inspection_notification_cancellations');
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Populated forward install');
        $migration = require database_path('migrations/2026_10_20_130000_add_inspection_notification_cancellations.php');
        $migration->up();
        $ackMigration = require database_path('migrations/2026_10_20_140000_add_inspection_notification_acknowledgments.php');
        $ackMigration->up();
        $this->assertEquals($before, $this->row());
        $this->assertEquals($draftBefore, $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $draft)->first()));
        $this->assertCount(0, $this->events());
        $this->actingAs($this->recipient)->get($this->base.'/'.$this->id)->assertOk()->assertDontSee('CANCELLED');
    }

    public function test_late_failure_rolls_back_cancellation_and_no_related_business_records_change(): void
    {
        $this->issue();
        $tables = ['inspection_notifications', 'inspection_notification_draft_history', 'inspection_notification_settings', 'inspection_itp_stages', 'purchase_orders', 'material_requisitions', 'material_lots', 'material_movements'];
        $snapshot = fn () => $this->asWriter(function () use ($tables) {
            $out = [];
            foreach ($tables as $table) {
                $out[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
        $before = $snapshot();
        $this->asWriter(fn () => DB::unprepared("CREATE OR REPLACE FUNCTION notification_cancel_late_test() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'NOTIFICATION_CANCEL_TEST_LATE'; END \$\$; CREATE TRIGGER zz_notification_cancel_late_test AFTER INSERT ON inspection_notification_cancellations FOR EACH ROW EXECUTE FUNCTION notification_cancel_late_test();"));
        $this->actingAs($this->writer)->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'Must roll back']])->assertUnprocessable();
        $this->assertCount(0, $this->events());
        $this->assertSame($before, $snapshot());
        $this->asWriter(fn () => DB::statement('DROP TRIGGER zz_notification_cancel_late_test ON inspection_notification_cancellations'));
        $this->cancel();
        $this->assertCount(1, $this->events());
        $this->assertSame($before, $snapshot());
    }

    public function test_changing_main_contractor_revokes_former_company_cancellation_without_granting_cross_company_control(): void
    {
        $this->issue();
        $newMain = $this->userByEmail('mousavi@sahand.example');
        $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $newMain->company_id, 'draft_users' => [], 'issue_users' => [$newMain->id], 'reason' => 'Explicit new main contractor']);
        $this->actingAs($this->writer)->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'Old main grant']])->assertForbidden();
        $this->rawRefused();
        $this->actingAs($newMain)->postJson($this->cancelUrl(), ['cancellation' => ['version' => 2, 'reason' => 'Other company']])->assertNotFound();
        $this->assertCount(0, $this->events());
    }

    private function waitingCancellers(int $count, bool $revoke = false): array
    {
        $workers = [];
        $this->asWriter(function () use ($count, $revoke, &$workers): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-notification:'.$this->project->id]);
            for ($i = 0; $i < $count; $i++) {
                $p = new Process([PHP_BINARY, base_path('../scripts/tests/inspection_notification_worker.php'), $this->writer->id, $this->project->id, $this->id, 'cancel', json_encode(['version' => 2, 'reason' => 'Concurrent cancellation '.$i])], base_path(), ['APP_ENV' => 'testing', 'EPCC_NOTIFICATION_TEST' => 'isolated']);
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 8;
            do {
                $ready = true;
                foreach ($workers as $p) {
                    $p->checkTimeout();
                    $ready = str_contains($p->getOutput(), 'ready') && $ready;
                }
                $waiting = (int) DB::selectOne("WITH k AS (SELECT hashtextextended(?,0) v) SELECT count(*) n FROM pg_locks,k WHERE locktype='advisory' AND NOT granted AND classid::bigint=((v >> 32) & 4294967295) AND objid::bigint=(v & 4294967295) AND objsubid=1", ['inspection-notification:'.$this->project->id])->n;
                if ($ready && $waiting === $count) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $messages = implode(' | ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $workers));
                    foreach ($workers as $p) {
                        $p->stop(0.1);
                    }
                    $this->fail('Cancellation barrier not reached: '.$messages);
                }
                usleep(1000);
            } while (true);
            $this->assertSame($count, $waiting);
            if ($revoke) {
                $this->service()->configure($this->project, $this->owner, ['version' => 1, 'main_company_id' => $this->writer->company_id, 'draft_users' => [$this->writer->id], 'issue_users' => [], 'reason' => 'Revoke before blocked cancel acquires project lock']);
            }
        });
        $codes = [];
        foreach ($workers as $p) {
            $codes[] = $p->wait();
            $this->assertNotSame(3, $p->getExitCode(), $p->getErrorOutput());
        }
        sort($codes);

        return $codes;
    }

    public function test_independent_concurrent_cancellations_record_one_event_and_never_change_the_original_version(): void
    {
        $this->issue();
        $before = $this->row();
        $this->assertSame([0, 2], $this->waitingCancellers(2));
        $this->assertCount(1, $this->events());
        $this->assertEquals($before, $this->row());
    }

    public function test_waiting_cancellation_rechecks_committed_issue_grant_revocation(): void
    {
        $this->issue();
        $before = $this->row();
        $this->assertSame([2], $this->waitingCancellers(1, true));
        $this->assertCount(0, $this->events());
        $this->assertEquals($before, $this->row());
    }

    public function test_cancellation_during_pdf_render_refuses_stale_active_bytes_and_requires_retry(): void
    {
        $this->issue();
        $before = $this->row();
        $config = config('database.connections.pgsql');
        $this->assertSame('epcc_test', $config['database']);
        $binary = tempnam(sys_get_temp_dir(), 'notification-cancel-render-');
        $marker = $binary.'.ran';
        $dsn = 'pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];
        $script = '#!'.PHP_BINARY."\n<?php\n";
        $script .= '$db=new PDO('.var_export($dsn, true).', '.var_export($config['username'], true).', '.var_export($config['password'], true).",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);\n";
        $script .= '$db->beginTransaction();$db->exec("SET LOCAL statement_timeout=5000");'."\n";
        $script .= '$q=$db->prepare("SELECT set_config(\'epcc.company_id\', ?, true), set_config(\'epcc.project_id\', ?, true)");$q->execute('.var_export([$this->writer->company_id, $this->project->id], true).");\n";
        $script .= '$q=$db->prepare("INSERT INTO inspection_notification_cancellations(project_id,company_id,notification_id,issued_version,actor_id,reason) VALUES(?,?,?,2,?,?)");$q->execute('.var_export([$this->project->id, $this->writer->company_id, $this->id, $this->writer->id, 'Committed during PDF render'], true).");\n";
        $script .= '$db->commit();file_put_contents('.var_export($marker, true).',"cancelled");'."\n";
        $script .= 'foreach($argv as $arg){if(str_starts_with($arg,"--print-to-pdf=")){file_put_contents(substr($arg,15),"%PDF-".str_repeat("test-only",200));}}';
        try {
            file_put_contents($binary, $script);
            chmod($binary, 0700);
            $this->app->instance(PdfRenderer::class, new PdfRenderer(10, [$binary]));
            $r = $this->actingAs($this->recipient)->getJson($this->url())->assertStatus(409)->assertHeaderMissing('content-disposition');
            $this->assertFileExists($marker);
            $this->assertStringNotContainsString('%PDF-', $r->getContent());
            $this->assertCount(1, $this->events());
            $this->assertEquals($before, $this->row());
        } finally {
            @unlink($binary);
            @unlink($marker);
        }
    }
}
