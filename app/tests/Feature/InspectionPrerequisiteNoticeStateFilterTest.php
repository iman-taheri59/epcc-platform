<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Services\InspectionNotificationService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InspectionPrerequisiteNoticeStateFilterTest extends TestCase
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

    private function artifact(string $name, string $bytes): void
    {
        if (getenv('EPCC_PREREQUISITE_EVIDENCE') === '1') {
            $dir = base_path('../docs/evidence/2026-10-20-inspection-notification-prerequisites');
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($dir.'/'.$name, $bytes);
        }
    }

    private string $prerequisiteId;

    private function asRecipient(callable $f): mixed
    {
        return app(TenantContext::class)->runAs($this->recipient->company_id, $f, $this->project->id);
    }

    private function create(array $overrides = [], ?User $actor = null): string
    {
        return $this->prerequisiteId = $this->service()->createPrerequisite($this->project, $actor ?? $this->recipient, $this->id, array_replace(['item_reference' => 'ACTIVITY-A / مورد آزمایشی', 'requirement' => 'PREREQUISITEBODY / آماده‌سازی محل'], $overrides));
    }

    private function prerequisite(): object
    {
        return $this->asWriter(fn () => DB::table('inspection_notification_prerequisites')->where('id', $this->prerequisiteId)->first());
    }

    private function events()
    {
        return $this->asWriter(fn () => DB::table('inspection_notification_prerequisite_events')->where('prerequisite_id', $this->prerequisiteId)->orderBy('version')->get());
    }

    private function command(string $action, ?User $actor = null, array $overrides = []): void
    {
        $this->service()->updatePrerequisite($this->project, $actor ?? ($action === 'REPORT' ? $this->writer : $this->recipient), $this->id, $this->prerequisiteId,
            array_replace(['version' => $this->prerequisite()->version, 'action' => $action, 'note' => 'Explanation '.$action], $overrides));
    }

    private function endpoint(): string
    {
        return $this->base.'/'.$this->id.'/prerequisites/'.$this->prerequisiteId;
    }

    private function anotherReader(): User
    {
        return $this->asRecipient(function () {
            $u = User::create(['company_id' => $this->recipient->company_id, 'name' => 'OTHERPREREQUISITEREADER', 'email' => 'synthetic-prerequisite@karan.example', 'password' => 'synthetic-only', 'is_active' => true]);
            ProjectMembership::create(['project_id' => $this->project->id, 'user_id' => $u->id, 'role_codes' => [], 'discipline_scope' => null]);

            return $u;
        });
    }

    private function register(array $query = []): string
    {
        return $this->base.'/prerequisites'.($query ? '?'.http_build_query($query) : '');
    }

    private function newNotice(array $recipients): string
    {
        $data = $this->draft();
        $data['recipient_company_ids'] = $recipients;
        $this->id = $this->service()->createDraft($this->project, $this->writer, $data);
        $this->issue();

        return $this->id;
    }

    private function response(array $query = [])
    {
        return $this->actingAs($this->recipient)->get($this->register($query))->assertOk();
    }

    private function cancelCurrent(): void
    {
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'Synthetic notice lifecycle filter']);
    }

    public function test_default_all_and_active_cancelled_filters_preserve_states_and_business_records(): void
    {
        $activeNotice = $this->newNotice([$this->recipient->company_id]);
        $active = $this->create(['item_reference' => 'ACTIVE NOTICE ITEM']);
        $cancelledNotice = $this->newNotice([$this->recipient->company_id]);
        $cancelled = $this->create(['item_reference' => 'CANCELLED NOTICE ITEM']);
        $this->cancelCurrent();
        $tables = ['inspection_notifications', 'inspection_notification_prerequisites', 'inspection_notification_prerequisite_events', 'inspection_notification_cancellations', 'inspection_notification_acknowledgments', 'inspection_notification_draft_history', 'material_inspections', 'purchase_orders', 'material_requisitions', 'material_lots', 'material_movements'];
        $snapshot = fn () => $this->asWriter(function () use ($tables) {
            $out = [];
            foreach ($tables as $t) {
                $out[$t] = DB::table($t)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
        $before = $snapshot();
        foreach (['en', 'fa'] as $lang) {
            foreach (['' => [$active, $cancelled], 'ACTIVE' => [$active], 'CANCELLED' => [$cancelled]] as $state => $ids) {
                $r = $this->response(['lang' => $lang, 'prerequisite_notice_state' => $state]);
                $this->assertEqualsCanonicalizing($ids, $r->viewData('prerequisites')->pluck('id')->all());
                $this->assertSame(2, $r->viewData('prerequisiteVisibleTotal'));
                $this->assertSame($state, $r->viewData('filters')['prerequisite_notice_state']);
                foreach ($r->viewData('prerequisites') as $p) {
                    $this->assertSame('OPEN', $p->state);
                }
                $r->assertSee('id="inspection-prerequisite-notice-state"', false)->assertDontSee('method="post"', false);
                $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
                if ($state !== '' && getenv('EPCC_ACTIVE_NOTICE_FILTER_EVIDENCE') === '1') {
                    file_put_contents(base_path('../docs/evidence/2026-10-20-prerequisite-active-notice-filter/'.strtolower($state).'-'.$lang.'.html'), $r->getContent());
                }
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_original_lifecycle_and_all_prerequisite_states_intersect_without_reclassifying_terminal_records(): void
    {
        $this->issue();
        $open = $this->create();
        $reported = $this->create();
        $this->command('REPORT');
        $fulfilled = $this->create();
        $this->command('REPORT');
        $this->command('CONFIRM');
        $withdrawn = $this->create();
        $this->command('WITHDRAW');
        foreach (['OPEN' => $open, 'REPORTED' => $reported, 'FULFILLED' => $fulfilled, 'WITHDRAWN' => $withdrawn] as $state => $id) {
            $r = $this->response(['prerequisite_notice_state' => 'ACTIVE', 'prerequisite_state' => $state, 'prerequisite_company' => $this->recipient->company_id]);
            $this->assertSame([$id], $r->viewData('prerequisites')->pluck('id')->all());
        }
        $this->cancelCurrent();
        foreach (['OPEN' => $open, 'REPORTED' => $reported, 'FULFILLED' => $fulfilled, 'WITHDRAWN' => $withdrawn] as $state => $id) {
            $r = $this->response(['prerequisite_notice_state' => 'CANCELLED', 'prerequisite_state' => $state]);
            $this->assertSame([$id], $r->viewData('prerequisites')->pluck('id')->all());
            $r = $this->response(['prerequisite_notice_state' => 'ACTIVE', 'prerequisite_state' => $state]);
            $this->assertSame(0, $r->viewData('prerequisites')->total());
            $this->assertSame(4, $r->viewData('prerequisiteVisibleTotal'));
        }
    }

    public function test_hidden_parent_cancellation_and_unaddressed_company_never_leak_through_lifecycle_counts_or_picker(): void
    {
        $this->issue();
        $visible = $this->create();
        $this->newNotice([$this->owner->company_id]);
        $this->create(['item_reference' => 'HIDDEN CANCELLED CONDITION'], $this->owner);
        $this->cancelCurrent();
        $r = $this->response(['prerequisite_notice_state' => 'CANCELLED']);
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $this->assertSame(1, $r->viewData('prerequisiteVisibleTotal'));
        $this->assertSame([$this->recipient->company_id], $r->viewData('prerequisiteCompanies')->pluck('company_id')->all());
        $r->assertDontSee('HIDDEN CANCELLED CONDITION');
        foreach (['ACTIVE', 'CANCELLED'] as $state) {
            $r = $this->actingAs($this->userByEmail('tehrani@pmc.example'))->get($this->register(['prerequisite_notice_state' => $state]))->assertOk();
            $this->assertSame(0, $r->viewData('prerequisiteVisibleTotal'));
            $this->assertSame(0, $r->viewData('prerequisites')->total());
            $this->assertSame(0, $r->viewData('prerequisiteCompanies')->total());
        }
        $r = $this->actingAs($this->writer)->get($this->register(['prerequisite_notice_state' => 'CANCELLED']))->assertOk();
        $this->assertSame(1, $r->viewData('prerequisites')->total());
        $this->assertSame(2, $r->viewData('prerequisiteVisibleTotal'));
        $r = $this->actingAs($this->owner)->get($this->register(['prerequisite_notice_state' => 'CANCELLED']))->assertOk()->assertSee('HIDDEN CANCELLED CONDITION');
        $this->assertSame(1, $r->viewData('prerequisites')->total());
    }

    public function test_fresh_read_moves_after_committed_cancellation_without_changing_prerequisite_or_retained_issue(): void
    {
        $this->issue();
        $this->create();
        $before = $this->prerequisite();
        $events = $this->events();
        $notice = $this->row();
        $this->assertSame(1, $this->response(['prerequisite_notice_state' => 'ACTIVE'])->viewData('prerequisites')->total());
        $this->cancelCurrent();
        $this->assertSame(0, $this->response(['prerequisite_notice_state' => 'ACTIVE'])->viewData('prerequisites')->total());
        $this->assertSame(1, $this->response(['prerequisite_notice_state' => 'CANCELLED'])->viewData('prerequisites')->total());
        $this->assertEquals($before, $this->prerequisite());
        $this->assertEquals($events, $this->events());
        $this->assertEquals($notice, $this->row());
    }

    public function test_not_cancelled_does_not_mean_writable_or_ready_archived_project_remains_read_only(): void
    {
        $this->issue();
        $this->create();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Archive without cancellation');
        $r = $this->response(['lang' => 'en', 'prerequisite_notice_state' => 'ACTIVE'])->assertSee('Not cancelled does not mean a writable project')->assertDontSee('method="post"', false);
        $this->assertSame(1, $r->viewData('prerequisites')->total());
        $this->assertSame(0, $this->response(['prerequisite_notice_state' => 'CANCELLED'])->viewData('prerequisites')->total());
        $this->actingAs($this->recipient)->postJson($this->endpoint(), ['prerequisite' => ['version' => 1, 'action' => 'WITHDRAW', 'note' => 'Still not writable']])->assertUnprocessable();
        $this->assertSame('OPEN', $this->prerequisite()->state);
    }

    public function test_invalid_notice_lifecycle_returns_422_not_referer_redirect_or_unfiltered_success(): void
    {
        $this->issue();
        $this->create(['item_reference' => 'NEVER SHOW ON BAD FILTER']);
        $this->actingAs($this->recipient);
        foreach ([['ACTIVE'], 'OPEN', 'ISSUED', 'active', 'ALL', 'UNKNOWN', "\0", "ACTIVE\0", "\xff"] as $bad) {
            $url = $this->register(['prerequisite_notice_state' => $bad]);
            $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('prerequisite_notice_state')->assertDontSee('NEVER SHOW ON BAD FILTER');
            $this->withHeader('Referer', 'https://invalid.example/')->get($url)->assertUnprocessable()->assertHeaderMissing('Location')->assertDontSee('NEVER SHOW ON BAD FILTER');
        }
        foreach ([[], ['prerequisite_notice_state' => ''], ['prerequisite_notice_state' => '  ']] as $query) {
            $r = $this->response($query);
            $this->assertSame('', $r->viewData('filters')['prerequisite_notice_state']);
            $this->assertSame(1, $r->viewData('prerequisites')->total());
        }
    }

    public function test_pages_language_row_shortcuts_and_reset_preserve_or_clear_lifecycle_intentionally(): void
    {
        $this->issue();
        for ($i = 0; $i < 26; $i++) {
            $this->create(['item_reference' => 'ACTIVE ROW '.$i]);
        }$activeNumber = $this->row()->number;
        $this->newNotice([$this->recipient->company_id]);
        $this->create(['item_reference' => 'CANCELLED ONLY']);
        $this->cancelCurrent();
        $query = ['lang' => 'en', 'prerequisite_notice_state' => 'ACTIVE', 'prerequisite_state' => 'OPEN', 'prerequisite_company' => $this->recipient->company_id, 'prerequisite_notice' => $activeNumber, 'prerequisite_company_q' => 'MC-KARAN', 'prerequisite_register_page' => 2, 'prerequisite_company_page' => 2, 'unrelated_secret' => 'DO NOT PROPAGATE'];
        $r = $this->response($query)->assertDontSee('CANCELLED ONLY')->assertDontSee('DO NOT PROPAGATE')->assertDontSee('name="prerequisite_register_page"', false)->assertDontSee('name="prerequisite_company_page"', false);
        $this->assertSame(26, $r->viewData('prerequisites')->total());
        $this->assertCount(1, $r->viewData('prerequisites'));
        $this->assertSame(27, $r->viewData('prerequisiteVisibleTotal'));
        $this->assertStringContainsString('prerequisite_notice_state=ACTIVE', $r->viewData('prerequisites')->url(1));
        $this->assertStringContainsString('prerequisite_notice_state=ACTIVE', $r->viewData('prerequisiteCompanies')->url(1));
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$r->getContent());
        $found = ['language' => false, 'reset' => false, 'company' => false, 'notice' => false];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = $a->getAttribute('href');
            $label = trim($a->textContent);
            $q = [];
            parse_str(parse_url($href, PHP_URL_QUERY) ?? '', $q);
            if ($label === 'فارسی') {
                $this->assertSame('ACTIVE', $q['prerequisite_notice_state']);
                $this->assertSame('2', $q['prerequisite_register_page']);
                $this->assertSame('2', $q['prerequisite_company_page']);
                $found['language'] = true;
            }
            if ($label === 'Clear filters') {
                $this->assertArrayNotHasKey('prerequisite_notice_state', $q);
                $this->assertArrayNotHasKey('prerequisite_company_q', $q);
                $found['reset'] = true;
            }
            if (in_array($label, ['Filter this company', 'Filter this notice'], true)) {
                $this->assertSame('ACTIVE', $q['prerequisite_notice_state']);
                $this->assertArrayNotHasKey('prerequisite_register_page', $q);
                $this->assertArrayNotHasKey('prerequisite_company_page', $q);
                $found[$label === 'Filter this company' ? 'company' : 'notice'] = true;
            }
        }
        $this->assertSame(['language' => true, 'reset' => true, 'company' => true, 'notice' => true], $found);
    }

    public function test_company_picker_keeps_all_visible_companies_even_when_lifecycle_filters_out_their_only_notice(): void
    {
        $this->issue();
        $this->create();
        $this->newNotice([$this->recipient->company_id, $this->owner->company_id]);
        $this->create([], $this->owner);
        $this->cancelCurrent();
        $r = $this->response(['prerequisite_notice_state' => 'ACTIVE', 'prerequisite_company' => $this->owner->company_id, 'prerequisite_company_q' => 'NIOC']);
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $this->assertSame(2, $r->viewData('prerequisiteVisibleTotal'));
        $this->assertSame([$this->owner->company_id], $r->viewData('prerequisiteCompanies')->pluck('company_id')->all());
        $this->assertSame($this->owner->company_id, $r->viewData('selectedPrerequisiteCompany')->company_id);
        $r = $this->response(['prerequisite_notice_state' => 'CANCELLED', 'prerequisite_company' => $this->recipient->company_id, 'prerequisite_company_q' => 'MC-KARAN']);
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $this->assertSame([$this->recipient->company_id], $r->viewData('prerequisiteCompanies')->pluck('company_id')->all());
    }

    public function test_lifecycle_filter_never_bypasses_current_discipline_or_project_scope(): void
    {
        $this->issue();
        $this->create();
        $this->cancelCurrent();
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]));
        foreach (['ACTIVE', 'CANCELLED'] as $state) {
            $r = $this->response(['prerequisite_notice_state' => $state]);
            $this->assertSame(0, $r->viewData('prerequisites')->total());
            $this->assertSame(0, $r->viewData('prerequisiteVisibleTotal'));
            $this->assertSame(0, $r->viewData('prerequisiteCompanies')->total());
            $r = $this->actingAs($this->writer)->get('/projects/BB-2/inspection-notifications/prerequisites?prerequisite_notice_state='.$state)->assertOk();
            $this->assertSame(0, $r->viewData('prerequisiteVisibleTotal'));
        }
    }
}
