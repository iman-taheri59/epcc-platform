<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InspectionNotificationTimeUiTest extends TestCase
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

    private function evidence(string $name, string $html): void
    {
        if (getenv('EPCC_TIME_UI_EVIDENCE') === '1') {
            $dir = base_path('../docs/evidence/2026-10-20-inspection-notification-time-ui');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            // Synthetic fixture page, not a production token or authenticated session.
            $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-test-token"', $html);
            file_put_contents($dir.'/'.$name, $html);
        }
    }

    public function test_actual_bilingual_register_and_edit_html_has_accessible_progressive_controls_and_only_canonical_payload_names(): void
    {
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            foreach (['register' => $this->base.'?stage='.$this->stage.'&lang='.$lang, 'edit' => $this->base.'/'.$this->id.'/edit?lang='.$lang] as $kind => $url) {
                $r = $this->actingAs($this->writer)->get($url)->assertOk();
                foreach (['start', 'end'] as $part) {
                    $r->assertSee('id="notification-'.$part.'-date"', false)->assertSee('type="date"', false)->assertSee('type="time" step="1"', false)->assertSee('name="notification[activity_'.$part.'_at]"', false);
                }
                $r->assertSee('data-time-helper hidden', false)->assertSee('data-time-mode hidden', false)->assertSee('<noscript>', false)->assertSee('aria-live="polite"', false);
                $r->assertDontSee('name="activity_date"', false)->assertDontSee('name="notification[scheduled_at]"', false);
                $this->evidence($kind.'-'.$lang.'.html', $r->getContent());
            }
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_readable_display_keeps_recorded_dates_offsets_and_original_raw_values_without_live_time_zone_conversion(): void
    {
        $this->actingAs($this->writer)->get($this->base.'/'.$this->id.'?lang=fa')->assertOk()->assertSee('2026-10-25T09:30:00+03:30')->assertSee('2026-10-25 · 09:30:00 · UTC+03:30');
        $this->get($this->base.'?lang=en')->assertOk()->assertSee('2026-10-25 · 11:30:00 · UTC+03:30');
    }

    public function test_direct_entry_fallback_remains_the_unchanged_server_contract_and_bad_range_still_fails(): void
    {
        $before = $this->row();
        $d = $this->draft();
        unset($d['itp_stage_id'],$d['itp_revision_id']);
        $d['version'] = 1;
        $d['activity_start_at'] = '2026-10-25T23:30:45+03:30';
        $d['activity_end_at'] = '2026-10-26T00:05:15+03:30';
        $this->actingAs($this->writer)->putJson($this->base.'/'.$this->id, ['notification' => $d])->assertRedirect();
        $row = $this->row();
        $this->assertSame($d['activity_start_at'], $row->activity_start_input);
        $this->assertSame($d['activity_end_at'], $row->activity_end_input);
        $this->assertSame($before->version + 1, $row->version);
        $d['version'] = 2;
        $d['activity_end_at'] = $d['activity_start_at'];
        $this->putJson($this->base.'/'.$this->id, ['notification' => $d])->assertUnprocessable();
        $this->assertEquals($row, $this->row());
    }

    public function test_invalid_restored_direct_value_is_escaped_and_not_replaced_by_a_default_browser_date(): void
    {
        $d = $this->draft();
        unset($d['itp_stage_id'],$d['itp_revision_id']);
        $d['version'] = 1;
        $d['activity_start_at'] = '2026-02-30T09:30:00+03:30';
        $url = $this->base.'/'.$this->id.'/edit?lang=fa';
        $this->actingAs($this->writer)->from($url)->put($this->base.'/'.$this->id, ['edit_target' => $this->id, 'notification' => $d])->assertSessionHasErrors('notification.activity_start_at');
        $r = $this->get($url)->assertOk()->assertSee('value="2026-02-30T09:30:00+03:30"', false);
        $this->evidence('invalid-restored-fa.html', $r->getContent());
        $this->assertSame(1,$this->row()->version);
    }
}
