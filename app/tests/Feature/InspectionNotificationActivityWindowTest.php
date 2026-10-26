<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\PdfRenderer;
use App\Services\ProjectArchiveService;
use App\Support\InspectionNotificationPrintData;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InspectionNotificationActivityWindowTest extends TestCase
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
        $this->fixture();
    }

    private function fixture(bool $legacy = false): void
    {
        $options = ['--force' => true];
        if ($legacy) {
            $options['--path'] = array_map(fn ($p) => 'database/migrations/'.basename($p), array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_20_110000_add_inspection_notification_activity_window.php')));
        }
        $this->artisan('migrate:fresh', $options)->assertExitCode(0);
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
        if ($legacy) {
            // Exact former app INSERT shape, still subject to the historical SQL guard.
            $this->id = (string) Str::uuid();
            $d = $this->draft();
            $this->asWriter(fn () => DB::table('inspection_notifications')->insert([
                'id' => $this->id, 'project_id' => $this->project->id, 'company_id' => $this->writer->company_id, 'created_by' => $this->writer->id,
                'stage_id' => $this->stage, 'discipline' => 'PIPING', 'entry_setting_id' => $this->service()->latest($this->project)->id, 'state' => 'DRAFT', 'version' => 1,
                'work_reference' => $d['work_reference'], 'location' => $d['location'], 'scheduled_input' => '2026-10-25T09:30:00+03:30', 'scheduled_at' => '2026-10-25 06:00:00+00',
                'recipient_company_ids' => json_encode($d['recipient_company_ids']), 'notes_en' => $d['notes_en'], 'notes_fa' => $d['notes_fa'], 'created_at' => now(),
            ]));
        } else {
            $this->id = $this->service()->createDraft($this->project, $this->writer, $this->draft());
        }
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
        if (getenv('EPCC_NOTIFICATION_WINDOW_EVIDENCE') === '1') {
            $dir = base_path('../docs/evidence/2026-10-20-inspection-notification-activity-window');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.'/'.$name, $bytes);
        }
    }

    private function endpoint(): string
    {
        return $this->base.'/'.$this->id;
    }

    private function editData(int $version = 1): array
    {
        $d = $this->draft();
        unset($d['itp_stage_id'],$d['itp_revision_id']);

        return ['version' => $version] + $d;
    }

    private function upgrade(): void
    {
        $migration = require database_path('migrations/2026_10_20_110000_add_inspection_notification_activity_window.php');
        $migration->up();
        $history = require database_path('migrations/2026_10_20_120000_add_inspection_notification_draft_history.php');
        $history->up();
        $cancellations = require database_path('migrations/2026_10_20_130000_add_inspection_notification_cancellations.php');
        $cancellations->up();
        $acknowledgments = require database_path('migrations/2026_10_20_140000_add_inspection_notification_acknowledgments.php');
        $acknowledgments->up();
        $prerequisites = require database_path('migrations/2026_10_20_150000_add_inspection_notification_prerequisites.php');
        $prerequisites->up();
    }

    public function test_real_legacy_single_time_issue_can_be_withdrawn_without_fabricating_an_activity_window(): void
    {
        $this->fixture(true);
        $this->oldIssue();
        $before = $this->row();
        $this->upgrade();
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'Withdraw legacy invitation']);
        $after = $this->row();
        foreach ((array) $before as $key => $value) {
            $this->assertSame($value, $after->$key);
        }
        $this->assertNull($after->activity_start_at);
        $this->assertNull($after->activity_end_at);
        $this->actingAs($this->recipient)->get($this->base.'/'.$this->id.'?lang=en')->assertOk()->assertSee('CANCELLED')->assertSee('Withdraw legacy invitation');
    }

    public function test_real_legacy_single_time_issue_and_cancellation_can_be_acknowledged_without_backfilled_windows(): void
    {
        $this->fixture(true);
        $this->oldIssue();
        $before = $this->row();
        $this->upgrade();
        $this->service()->acknowledge($this->project, $this->recipient, $this->id, ['version' => 2, 'event_kind' => 'ISSUE']);
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'Legacy cancellation receipt']);
        $cancelId = $this->asWriter(fn () => DB::table('inspection_notification_cancellations')->where('notification_id', $this->id)->value('id'));
        $this->service()->acknowledge($this->project, $this->recipient, $this->id, ['version' => 2, 'event_kind' => 'CANCELLATION', 'cancellation_id' => $cancelId]);
        $after = $this->row();
        foreach ((array) $before as $key => $value) {
            $this->assertSame($value, $after->$key);
        }
        $this->assertNull($after->activity_start_at);
        $this->assertNull($after->activity_end_at);
        $this->assertSame(2, $this->asWriter(fn () => DB::table('inspection_notification_acknowledgments')->where('notification_id', $this->id)->count()));
    }

    private function oldIssue(): void
    {
        // Reproduce the former issue UPDATE under the actual prior SQL guard;
        // this is historical test fixture creation, never an issued-row rewrite.
        $this->asWriter(function (): void {
            $rev = DB::table('document_revisions')->where('id', $this->revision)->first();
            $doc = DB::table('documents')->where('id', $rev->document_id)->first();
            $stage = DB::table('inspection_itp_stages')->where('id', $this->stage)->first();
            $company = DB::table('companies')->where('id', $this->writer->company_id)->first();
            $party = DB::table('companies')->where('id', $this->recipient->company_id)->first();
            $snapshot = ['document_id' => $doc->id, 'document_number' => $doc->number, 'revision_id' => $rev->id, 'revision' => $rev->revision, 'cde_state' => $rev->cde_state, 'suitability' => $rev->suitability,
                'stage_id' => $stage->id, 'stage_sequence' => $stage->sequence, 'point' => $stage->point, 'description_en' => $stage->description_en, 'description_fa' => $stage->description_fa,
                'issuer_id' => $this->writer->id, 'issuer_name' => $this->writer->name, 'sender_code' => $company->code, 'sender_name_en' => $company->name_en, 'sender_name_fa' => $company->name_fa,
                'recipients' => [['id' => $party->id, 'code' => $party->code, 'name_en' => $party->name_en, 'name_fa' => $party->name_fa]]];
            DB::table('inspection_notifications')->where('id', $this->id)->update(['state' => 'ISSUED', 'version' => 2, 'sequence' => 1, 'number' => 'PH-14-IN-EPC-PARS-000001', 'issued_by' => $this->writer->id, 'issued_at' => now(), 'issue_setting_id' => $this->service()->latest($this->project)->id, 'issued_snapshot' => json_encode($snapshot)]);
        });
    }

    public function test_new_registration_and_edit_forms_use_two_required_boundaries_and_no_single_inspection_time(): void
    {
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->writer)->get($this->base.'?stage='.$this->stage.'&lang='.$lang)->assertOk()->assertSee('name="notification[activity_start_at]"', false)->assertSee('name="notification[activity_end_at]"', false)->assertDontSee('name="notification[scheduled_at]"', false);
            $this->get($this->endpoint().'/edit?lang='.$lang)->assertOk()->assertSee('name="notification[activity_start_at]"', false)->assertSee('name="notification[activity_end_at]"', false)->assertDontSee('name="notification[scheduled_at]"', false);
        }
    }

    public function test_registration_preserves_offsets_and_normalizes_both_instants_across_midnight(): void
    {
        $d = $this->draft();
        $d['activity_start_at'] = '2026-10-25T23:30:00+03:30';
        $d['activity_end_at'] = '2026-10-26T00:15:00+03:30';
        $r = $this->actingAs($this->writer)->post($this->base, ['notification' => $d])->assertRedirect();
        $id = basename(parse_url($r->headers->get('Location'), PHP_URL_PATH));
        $row = $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $id)->first());
        $this->assertSame($d['activity_start_at'], $row->activity_start_input);
        $this->assertSame($d['activity_end_at'], $row->activity_end_input);
        $this->assertStringContainsString('2026-10-25 20:00:00', $row->activity_start_at);
        $this->assertStringContainsString('2026-10-25 20:45:00', $row->activity_end_at);
        $this->assertNull($row->scheduled_at);
        $this->assertNull($row->scheduled_input);
        $this->assertNull($row->number);
        $this->assertNull($row->issued_snapshot);
    }

    public function test_invalid_or_missing_window_and_old_single_time_payload_do_not_create_or_edit(): void
    {
        $before = $this->row();
        $cases = [['activity_end_at' => null], ['activity_start_at' => ['nested']], ['activity_end_at' => ['nested']], ['activity_end_at' => '2026-02-30T12:00:00+03:30'], ['activity_end_at' => '2026-10-25T09:30:00+03:30'], ['activity_end_at' => '2026-10-25T06:00:00+00:00'], ['activity_end_at' => '2026-10-25T10:00:00+05:00'], ['activity_end_at' => '2026-10-25T12:00:00'], ['scheduled_at' => '2026-10-25T09:30:00+03:30']];
        foreach ($cases as $bad) {
            $this->actingAs($this->writer)->postJson($this->base, ['notification' => array_replace($this->draft(), $bad)])->assertUnprocessable();
            $this->putJson($this->endpoint(), ['notification' => array_replace($this->editData(), $bad)])->assertUnprocessable();
            $this->assertEquals($before, $this->row());
        }
        $this->assertSame(1, $this->asWriter(fn () => DB::table('inspection_notifications')->count()));
    }

    public function test_offset_comparison_and_edit_versioning_preserve_source_and_do_not_allocate_issue_evidence(): void
    {
        $before = $this->row();
        $d = $this->editData();
        $d['activity_start_at'] = '2026-10-25T02:30:00+02:00';
        $d['activity_end_at'] = '2026-10-25T02:15:00+01:00';
        $this->actingAs($this->writer)->putJson($this->endpoint(), ['notification' => $d])->assertRedirect();
        $row = $this->row();
        $this->assertSame(2, $row->version);
        $this->assertSame($d['activity_start_at'], $row->activity_start_input);
        $this->assertSame($d['activity_end_at'], $row->activity_end_input);
        foreach (['stage_id', 'created_at', 'created_by', 'entry_setting_id', 'number', 'sequence', 'issued_snapshot'] as $key) {
            $this->assertSame($before->$key, $row->$key);
        }
        $this->putJson($this->endpoint(), ['notification' => $d])->assertUnprocessable();
        $this->assertEquals($row, $this->row());
    }

    public function test_issued_window_is_visible_to_selected_recipients_and_immutable_even_by_direct_sql(): void
    {
        $this->issue();
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->recipient)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee($before->activity_start_input)->assertSee($before->activity_end_input)->assertDontSee(__('inspection_notifications.legacy_time_warning'));
        }
        try {
            $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $this->id)->update(['activity_end_at' => '2026-10-25 10:00:00+00', 'activity_end_input' => '2026-10-25T10:00:00+00:00', 'version' => 3]));
            $this->fail('Expected immutable issued window');
        } catch (QueryException $e) {
            $this->assertStringContainsString('NOTIFICATION_IMMUTABLE', $e->getMessage());
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_real_bilingual_pdf_shows_both_recorded_boundaries_and_never_a_new_single_time(): void
    {
        $this->browser();
        $this->issue();
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->url($lang))->assertOk()->assertHeader('content-type', 'application/pdf');
            $text = $this->inspect($r->getContent());
            $this->assertStringContainsString($before->activity_start_input, $text);
            $this->assertStringContainsString($before->activity_end_input, $text);
            $this->assertStringNotContainsString('Legacy proposed inspection time', $text);
            $this->artifact('window-'.$lang.'.pdf', $r->getContent());
            $this->artifact('window-'.$lang.'.txt', $text);
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_sql_rejects_zero_duration_reversed_missing_and_inconsistent_raw_or_utc_endpoints(): void
    {
        $before = $this->row();
        foreach ([['activity_end_at' => $before->activity_start_at, 'activity_end_input' => $before->activity_start_input], ['activity_end_at' => null], ['activity_end_input' => '2026-10-25T01:00:00+03:30'], ['activity_start_input' => 'not-a-date'], ['scheduled_input' => '2026-10-25T09:30:00+03:30', 'scheduled_at' => '2026-10-25 06:00:00+00']] as $bad) {
            try {
                $this->asWriter(fn () => DB::table('inspection_notifications')->where('id', $this->id)->update($bad + ['version' => 2, 'last_edited_by' => $this->writer->id, 'last_edit_setting_id' => $before->entry_setting_id, 'last_edited_at' => now()]));
                $this->fail('Expected SQL window rejection');
            } catch (QueryException $e) {
                $this->assertStringContainsString('NOTIFICATION_ACTIVITY_WINDOW_', $e->getMessage());
            }
            $this->assertEquals($before, $this->row());
        }
    }

    public function test_pdf_validator_refuses_partial_mixed_or_inconsistent_schedule_without_inference(): void
    {
        $this->issue();
        $before = $this->row();
        foreach (['activity_end_input' => null, 'activity_start_input' => '<script>time()</script>', 'activity_end_at' => $before->activity_start_at, 'scheduled_input' => $before->activity_start_input] as $key => $bad) {
            $row = clone $before;
            $row->$key = $bad;
            try {
                InspectionNotificationPrintData::snapshot($row);
                $this->fail('Expected inconsistent schedule refusal');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('notification', $e->errors());
            }
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_forward_upgrade_keeps_legacy_issued_time_and_snapshot_even_on_archived_project_and_exports_explicit_legacy(): void
    {
        $this->browser();
        $this->fixture(true);
        $this->oldIssue();
        $before = $this->row();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Legacy issued schedule retained');
        $this->upgrade();
        foreach (['activity_start_input', 'activity_end_input', 'activity_start_at', 'activity_end_at'] as $key) {
            $before->$key = null;
        }
        $this->assertEquals($before, $this->row());
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->recipient)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee($before->scheduled_input)->assertSee(__('inspection_notifications.legacy_time_warning'));
            $r = $this->get($this->url($lang))->assertOk();
            $text = preg_replace('/\s+/u', ' ', $this->inspect($r->getContent()));
            $this->assertStringContainsString($before->scheduled_input, $text);
            if ($lang === 'en') {
                $this->assertStringContainsString('Legacy proposed inspection time', $text);
                $this->assertStringNotContainsString('Planned activity end:', $text);
            }
            $this->artifact('legacy-'.$lang.'.pdf', $r->getContent());
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_legacy_draft_requires_explicit_versioned_conversion_before_issue_and_does_not_guess_boundaries(): void
    {
        $this->fixture(true);
        $before = $this->row();
        $this->upgrade();
        $this->actingAs($this->writer)->get($this->endpoint())->assertOk()->assertSee(__('inspection_notifications.activity_window_required'))->assertDontSee('id="inspection-notification-issue"', false);
        $this->get($this->endpoint().'/edit')->assertOk()->assertSee('name="notification[activity_start_at]" placeholder=', false)->assertSee($before->scheduled_input);
        $this->postJson($this->endpoint().'/issue', ['issuance' => ['version' => 1]])->assertUnprocessable();
        $this->assertSame(1, $this->row()->version);
        $this->assertSame($before->scheduled_input, $this->row()->scheduled_input);
        foreach (['insert', 'issue'] as $operation) {
            try {
                $this->asWriter(function () use ($operation): void {
                    if ($operation === 'insert') {
                        $copy = (array) $this->row();
                        $copy['id'] = (string) Str::uuid();
                        DB::table('inspection_notifications')->insert($copy);
                    } else {
                        DB::table('inspection_notifications')->where('id', $this->id)->update(['state' => 'ISSUED', 'version' => 2, 'sequence' => 1, 'number' => 'PH-14-IN-EPC-PARS-000001', 'issued_by' => $this->writer->id, 'issued_at' => now(), 'issue_setting_id' => $this->service()->latest($this->project)->id, 'issued_snapshot' => '{}']);
                    }
                });
                $this->fail('New writes cannot bypass required activity window with a legacy shape');
            } catch (QueryException $e) {
                $this->assertStringContainsString('NOTIFICATION_ACTIVITY_WINDOW_REQUIRED', $e->getMessage());
            }
        }
        $this->assertSame(1, $this->row()->version);

        $this->putJson($this->endpoint(), ['notification' => $this->editData()])->assertRedirect();
        $this->assertNull($this->row()->scheduled_at);
        $this->assertNull($this->row()->scheduled_input);
        $this->assertSame(2, $this->row()->version);
        $this->assertSame($this->draft()['activity_end_at'], $this->row()->activity_end_input);
        $this->postJson($this->endpoint().'/issue', ['issuance' => ['version' => 2]])->assertRedirect();
        $this->assertSame(3, $this->row()->version);
    }

    public function test_register_time_filter_excludes_legacy_single_time_without_guessing_a_window(): void
    {
        $this->fixture(true);
        $this->oldIssue();
        $before = $this->row();
        $this->upgrade();
        $this->actingAs($this->recipient);
        $r = $this->get($this->base.'?notice_state=ISSUED')->assertOk();
        $this->assertSame(1, $r->viewData('notifications')->total());
        $r = $this->get($this->base.'?'.http_build_query(['activity_from' => '2026-10-25T00:00:00+03:30']))->assertOk();
        $this->assertSame(0, $r->viewData('notifications')->total());
        $this->assertSame(1, $r->viewData('noticeVisibleTotal'));
        foreach (['activity_start_input', 'activity_end_input', 'activity_start_at', 'activity_end_at'] as $key) {
            $before->$key = null;
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_real_legacy_single_time_issue_accepts_separate_prerequisite_followup_without_rewriting_retained_issue(): void
    {
        $this->fixture(true);
        $this->oldIssue();
        $before = $this->row();
        $this->upgrade();
        $id = $this->service()->createPrerequisite($this->project, $this->recipient, $this->id, ['item_reference' => 'Legacy activity', 'requirement' => 'Legacy preparation']);
        $this->service()->updatePrerequisite($this->project, $this->writer, $this->id, $id, ['version' => 1, 'action' => 'REPORT', 'note' => 'Legacy completion']);
        $this->service()->updatePrerequisite($this->project, $this->recipient, $this->id, $id, ['version' => 2, 'action' => 'CONFIRM']);
        foreach (['activity_start_input', 'activity_end_input', 'activity_start_at', 'activity_end_at'] as $key) {
            $before->$key = null;
        }
        $this->assertEquals($before, $this->row());
        $this->assertSame('FULFILLED', $this->asWriter(fn () => DB::table('inspection_notification_prerequisites')->where('id', $id)->value('state')));
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->base.'/'.$this->id.'/prerequisites/'.$id.'?lang='.$lang)->assertOk()->assertSee('Legacy preparation');
            $this->assertSame($before->stage_id, $r->viewData('prerequisiteStage')['stage_id']);
            $this->assertSame(json_decode($before->issued_snapshot, true)['revision_id'], $r->viewData('prerequisiteStage')['revision_id']);
        }
    }
}
