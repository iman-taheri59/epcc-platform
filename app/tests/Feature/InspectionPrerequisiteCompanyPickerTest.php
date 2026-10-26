<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
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
use Illuminate\Support\Str;
use Tests\TestCase;

final class InspectionPrerequisiteCompanyPickerTest extends TestCase
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

    private function pickerResponse(array $query = [])
    {
        return $this->actingAs($this->recipient)->get($this->register($query))->assertOk();
    }

    public function test_only_visible_declaring_companies_are_options_not_every_project_company_or_hidden_notice_recipient(): void
    {
        $this->newNotice([$this->recipient->company_id, $this->owner->company_id]);
        $this->create();
        $this->create([], $this->owner);
        $this->newNotice([$this->userByEmail('tehrani@pmc.example')->company_id]);
        $this->create(['item_reference' => 'HIDDEN PMC'], $this->userByEmail('tehrani@pmc.example'));
        $r = $this->pickerResponse();
        $ids = $r->viewData('prerequisiteCompanies')->pluck('company_id')->all();
        $this->assertEqualsCanonicalizing([$this->recipient->company_id, $this->owner->company_id], $ids);
        $this->assertSame(2, $r->viewData('prerequisiteCompanies')->total());
        $r->assertSee('MC-KARAN')->assertSee('NIOC-AFF')->assertDontSee('PMC-TEHRAN')->assertDontSee('EPC-SAHAND');
        $r = $this->actingAs($this->userByEmail('tehrani@pmc.example'))->get($this->register())->assertOk();
        $this->assertSame([$this->userByEmail('tehrani@pmc.example')->company_id], $r->viewData('prerequisiteCompanies')->pluck('company_id')->all());
    }

    public function test_one_latest_created_visible_snapshot_per_company_is_searchable_not_live_directory_or_reporter(): void
    {
        $this->issue();
        $this->create(['item_reference' => 'FIRST']);
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['name_en' => 'RECORDED NEW NAME', 'name_fa' => 'نام ثبت‌شدهٔ تازه']));
        $this->create(['item_reference' => 'SECOND']);
        $this->command('REPORT');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['name_en' => 'UNRECORDED LIVE NAME', 'name_fa' => 'نام زندهٔ ثبت‌نشده']));
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = [$q->sql, $q->bindings];
        });
        $r = $this->pickerResponse(['lang' => 'en']);
        $this->assertSame(1, $r->viewData('prerequisiteCompanies')->total());
        $this->assertSame('RECORDED NEW NAME', $r->viewData('prerequisiteCompanies')->sole()->company_name_en);
        $r->assertDontSee('UNRECORDED LIVE NAME');
        foreach (['recorded new', 'MC-KARAN', 'نام ثبت‌شده'] as $term) {
            $r = $this->pickerResponse(['prerequisite_company_q' => $term]);
            $this->assertSame(1, $r->viewData('prerequisiteCompanies')->total());
            $this->assertSame(2, $r->viewData('prerequisites')->total());
        }
        $r = $this->pickerResponse(['prerequisite_company_q' => 'UNRECORDED LIVE']);
        $this->assertSame(0, $r->viewData('prerequisiteCompanies')->total());
        $this->assertSame(2, $r->viewData('prerequisites')->total());
        foreach ($queries as [$sql,$bindings]) {
            foreach (['inspection_notification_prerequisite_events', 'inspection_notification_draft_history', 'inspection_itp_stages', 'document_revisions'] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }if (str_contains($sql, 'from "companies"')) {
                $this->assertSame([$this->recipient->company_id], $bindings);
            }
        }
    }

    public function test_search_is_literal_bounded_and_independent_of_notice_status_or_company_result_filters(): void
    {
        $this->newNotice([$this->recipient->company_id, $this->owner->company_id]);
        $this->create();
        $this->create([], $this->owner);
        $this->command('REPORT');
        foreach (['%', '_', '!', "' OR 1=1 --"] as $term) {
            $r = $this->pickerResponse(['prerequisite_company_q' => $term]);
            $this->assertSame(0, $r->viewData('prerequisiteCompanies')->total());
            $this->assertSame(2, $r->viewData('prerequisites')->total());
        }
        $r = $this->pickerResponse(['prerequisite_state' => 'WITHDRAWN', 'prerequisite_notice' => 'NO SUCH NOTICE', 'prerequisite_company' => $this->recipient->company_id, 'prerequisite_company_q' => 'NIOC']);
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $this->assertSame([$this->owner->company_id], $r->viewData('prerequisiteCompanies')->pluck('company_id')->all());
        $this->assertSame($this->recipient->company_id, $r->viewData('selectedPrerequisiteCompany')->company_id);
        $r->assertSee('value="'.$this->recipient->company_id.'" selected', false);
    }

    public function test_unavailable_selection_is_retained_generically_not_silently_reset_or_looked_up(): void
    {
        $this->issue();
        $this->create();
        foreach ([$this->owner->company_id, (string) Str::uuid()] as $id) {
            $r = $this->pickerResponse(['lang' => 'en', 'prerequisite_company' => $id]);
            $this->assertNull($r->viewData('selectedPrerequisiteCompany'));
            $this->assertSame(0, $r->viewData('prerequisites')->total());
            $r->assertSee('Selected company unavailable')->assertSee('value="'.$id.'" selected', false);
            $this->assertSame(1, $r->viewData('prerequisiteCompanies')->total());
        }
    }

    public function test_malformed_company_search_is_rejected_for_html_and_json_including_nul_before_global_trim(): void
    {
        $this->issue();
        $this->create();
        $this->actingAs($this->recipient);
        foreach ([['x'], str_repeat('x', 101), "\xff", "\0", "A\0B", false] as $bad) {
            $url = $this->register(['prerequisite_company_q' => $bad]);
            if (is_bool($bad)) {
                continue;
            }// HTTP query encoding makes booleans ordinary strings; direct unit validation covers their PHP shape.
            $this->getJson($url)->assertUnprocessable();
            $this->withHeader('Referer', 'https://invalid.example/')->get($url)->assertUnprocessable()->assertHeaderMissing('Location');
        }
    }

    public function test_company_options_follow_current_reader_discipline_and_project_scope_and_survive_cancel_archive(): void
    {
        $this->issue();
        $this->create();
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'Retain options']);
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only option search');
        $r = $this->pickerResponse(['prerequisite_company_q' => 'MC-KARAN']);
        $this->assertSame(1, $r->viewData('prerequisiteCompanies')->total());
        // Reopen through the existing owner path before changing fixture membership.
        app(ProjectArchiveService::class)->reopen($this->project->fresh(), $this->owner);
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]));
        $r = $this->pickerResponse(['prerequisite_company' => $this->recipient->company_id]);
        $this->assertSame(0, $r->viewData('prerequisiteCompanies')->total());
        $this->assertNull($r->viewData('selectedPrerequisiteCompany'));
        $r = $this->actingAs($this->writer)->get('/projects/BB-2/inspection-notifications/prerequisites')->assertOk();
        $this->assertSame(0, $r->viewData('prerequisiteCompanies')->total());
    }

    public function test_bilingual_names_are_escaped_and_searching_or_selecting_creates_no_history(): void
    {
        $this->issue();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['name_en' => 'PICKER <script>NAME()</script>', 'name_fa' => 'شرکت <img src="x">']));
        $this->create();
        $before = $this->prerequisite();
        $events = $this->events();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->pickerResponse(['lang' => $lang, 'prerequisite_company_q' => 'PICKER', 'prerequisite_company' => $this->recipient->company_id]);
            $r->assertSee('id="inspection-prerequisite-company"', false)->assertDontSee('<script>NAME()', false)->assertDontSee('<img src="x">', false)->assertDontSee('method="post"', false);
            if ($lang === 'en') {
                $r->assertSee('PICKER &lt;script&gt;NAME()&lt;/script&gt;', false);
            } else {
                $r->assertSee('شرکت &lt;img src=&quot;x&quot;&gt;', false);
            }
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            if (getenv('EPCC_PICKER_EVIDENCE') === '1') {
                file_put_contents(base_path('../docs/evidence/2026-10-20-prerequisite-company-picker/picker-'.$lang.'.html'), $r->getContent());
            }
        }
        $this->assertEquals($before, $this->prerequisite());
        $this->assertEquals($events, $this->events());
    }

    public function test_company_and_record_pages_are_independent_bounded_and_selected_company_is_not_lost_off_page(): void
    {
        $users = [];
        $companyIds = [$this->recipient->company_id];
        for ($i = 0; $i < 26; $i++) {
            $c = app(TenantContext::class)->forIdentityLookup(function () use ($i) {
                $c = Company::whereKey($this->recipient->company_id)->first()->replicate();
                $c->code = sprintf('PICK-%02d', $i);
                $c->name_en = 'Picker company '.$i;
                $c->name_fa = 'شرکت انتخابگر '.$i;
                $c->save();

                return $c;
            });
            $users[] = app(TenantContext::class)->runAs($c->id, function () use ($c, $i) {
                DB::table('project_company')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $c->id, 'role' => 'MC', 'created_at' => now(), 'updated_at' => now()]);
                $u = User::create(['company_id' => $c->id, 'name' => 'Synthetic picker reader', 'email' => 'picker-'.$i.'@synthetic.example', 'password' => 'synthetic-only', 'is_active' => true]);
                ProjectMembership::create(['project_id' => $this->project->id, 'user_id' => $u->id, 'role_codes' => [], 'discipline_scope' => null]);

                return $u;
            }, $this->project->id);
            $companyIds[] = $c->id;
        }
        $this->newNotice($companyIds);
        $this->create();
        foreach ($users as $u) {
            $this->create([], $u);
        }
        $r = $this->pickerResponse(['lang' => 'fa', 'prerequisite_company_page' => 2, 'prerequisite_register_page' => 2, 'irrelevant' => 'NEVER PROPAGATE']);
        $p = $r->viewData('prerequisiteCompanies');
        $this->assertSame(27, $p->total());
        $this->assertCount(2, $p);
        $this->assertCount(2, $r->viewData('prerequisites'));
        $this->assertStringContainsString('prerequisite_register_page=2', $p->url(1));
        $this->assertStringContainsString('prerequisite_company_page=2', $r->viewData('prerequisites')->url(1));
        $this->assertStringNotContainsString('irrelevant', $p->url(1));
        $r->assertDontSee('name="prerequisite_company_page"', false)->assertDontSee('name="prerequisite_register_page"', false);
        $r = $this->pickerResponse(['prerequisite_company' => $this->recipient->company_id, 'prerequisite_company_page' => 2]);
        $this->assertSame($this->recipient->company_id, $r->viewData('selectedPrerequisiteCompany')->company_id);
        $r->assertSee('value="'.$this->recipient->company_id.'" selected', false);
        $this->assertSame(1, $r->viewData('prerequisites')->total());
    }
}
