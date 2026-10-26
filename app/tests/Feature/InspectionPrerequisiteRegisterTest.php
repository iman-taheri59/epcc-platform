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
use App\Services\ProjectArchiveService;
use App\Support\InspectionNotificationPrerequisiteStage;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InspectionPrerequisiteRegisterTest extends TestCase
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

    public function test_empty_register_navigation_and_gets_do_not_create_any_prerequisite_or_change_business_data(): void
    {
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->register(['lang' => $lang]))->assertOk()->assertSee('id="inspection-prerequisite-filters"', false)->assertDontSee('method="post"', false);
            $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
            $this->assertSame(0, $r->viewData('prerequisiteVisibleTotal'));
            $this->assertSame(0, $r->viewData('prerequisites')->total());
            $this->actingAs($this->writer)->get($this->base.'?lang='.$lang)->assertOk()->assertSee('id="inspection-prerequisite-register-link"', false);
        }
        $this->assertEquals($before, $this->row());
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_prerequisites')->count()));
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_prerequisite_events')->count()));
    }

    public function test_scope_counts_company_status_and_literal_notice_filters_intersect_without_exposing_hidden_notice(): void
    {
        $a = $this->newNotice([$this->recipient->company_id, $this->owner->company_id]);
        $open = $this->create(['item_reference' => 'OPEN VISIBLE']);
        $this->create(['item_reference' => 'WITHDRAWN VISIBLE']);
        $this->command('WITHDRAW');
        $reported = $this->create(['item_reference' => 'REPORTED OTHER COMPANY'], $this->owner);
        $this->command('REPORT');
        $b = $this->newNotice([$this->owner->company_id]);
        $hidden = $this->create(['item_reference' => 'HIDDEN OTHER NOTICE'], $this->owner);
        $this->actingAs($this->recipient);
        $r = $this->get($this->register())->assertOk()->assertSee('OPEN VISIBLE')->assertSee('REPORTED OTHER COMPANY')->assertDontSee('HIDDEN OTHER NOTICE');
        $this->assertSame(3, $r->viewData('prerequisiteVisibleTotal'));
        $this->assertSame(3, $r->viewData('prerequisites')->total());
        $r = $this->get($this->register(['prerequisite_state' => 'REPORTED', 'prerequisite_company' => $this->owner->company_id]))->assertOk();
        $this->assertSame([$reported], $r->viewData('prerequisites')->pluck('id')->all());
        $this->assertSame(3, $r->viewData('prerequisiteVisibleTotal'));
        $r = $this->get($this->register(['prerequisite_state' => 'OPEN', 'prerequisite_company' => $this->owner->company_id]))->assertOk();
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $hiddenNumber = $this->row()->number;
        $r = $this->get($this->register(['prerequisite_notice' => $hiddenNumber]))->assertOk()->assertDontSee('HIDDEN OTHER NOTICE');
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $r = $this->actingAs($this->writer)->get($this->register())->assertOk();
        $this->assertSame(4, $r->viewData('prerequisites')->total());
        $r = $this->actingAs($this->userByEmail('tehrani@pmc.example'))->get($this->register())->assertOk();
        $this->assertSame(0, $r->viewData('prerequisiteVisibleTotal'));
        $this->assertSame(0, $r->viewData('prerequisites')->total());
    }

    public function test_literal_search_does_not_interpret_wildcards_or_search_prerequisite_bodies_notes_or_history(): void
    {
        $this->issue();
        $number = $this->row()->number;
        $this->create(['item_reference' => 'ITEMONLYUNIQUE', 'requirement' => 'BODYONLYUNIQUE']);
        $this->command('REPORT', null, ['note' => 'NOTEONLYUNIQUE']);
        $this->actingAs($this->recipient);
        foreach (['%', '_', '!', "' OR 1=1 --", 'ITEMONLYUNIQUE', 'BODYONLYUNIQUE', 'NOTEONLYUNIQUE'] as $term) {
            $r = $this->get($this->register(['prerequisite_notice' => $term]))->assertOk();
            $this->assertSame(0, $r->viewData('prerequisites')->total());
        }
        $r = $this->get($this->register(['prerequisite_notice' => strtolower(substr($number, -10))]))->assertOk();
        $this->assertSame(1, $r->viewData('prerequisites')->total());
        $r = $this->get($this->register(['prerequisite_notice' => '0']))->assertOk();
        $this->assertSame('0', $r->viewData('filters')['prerequisite_notice']);
    }

    public function test_malformed_filters_fail_422_for_json_or_html_without_referer_redirect_or_unfiltered_results(): void
    {
        $this->issue();
        $this->create(['item_reference' => 'NEVERRETURNONINVALID']);
        $this->actingAs($this->recipient);
        foreach ([['prerequisite_state' => ['OPEN']], ['prerequisite_state' => 'CANCELLED'], ['prerequisite_company' => 'invalid'], ['prerequisite_company' => [$this->recipient->company_id]], ['prerequisite_notice' => ['x']], ['prerequisite_notice' => str_repeat('x', 101)], ['prerequisite_notice' => "A\0B"], ['prerequisite_notice' => "\0"]] as $bad) {
            $this->getJson($this->register($bad))->assertUnprocessable()->assertDontSee('NEVERRETURNONINVALID');
            $this->withHeader('Referer', 'https://invalid.example/redirect')->get($this->register($bad))->assertUnprocessable()->assertHeaderMissing('Location')->assertDontSee('NEVERRETURNONINVALID');
        }
    }

    public function test_unknown_or_hidden_company_filters_return_scoped_zero_without_company_directory_lookup(): void
    {
        $this->issue();
        $this->create();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $this->actingAs($this->recipient);
        foreach ([(string) Str::uuid(), $this->owner->company_id] as $id) {
            $r = $this->get($this->register(['prerequisite_company' => $id]))->assertOk();
            $this->assertSame(0, $r->viewData('prerequisites')->total());
            $this->assertSame(1, $r->viewData('prerequisiteVisibleTotal'));
        }
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('inspection_itp_stages', $sql);
            $this->assertStringNotContainsString('document_revisions', $sql);
            $this->assertStringNotContainsString('inspection_notification_draft_history', $sql);
            $this->assertStringNotContainsString('inspection_notification_prerequisite_events', $sql);
        }
    }

    public function test_pagination_has_stable_order_preserves_only_valid_filters_language_and_resets_on_submit(): void
    {
        $this->issue();
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            $ids[] = $this->create(['item_reference' => 'ITEM-'.$i]);
        }
        $query = ['lang' => 'fa', 'prerequisite_state' => 'OPEN', 'prerequisite_company' => $this->recipient->company_id, 'prerequisite_notice' => $this->row()->number, 'private_unrelated' => 'DO_NOT_PROPAGATE', 'prerequisite_register_page' => 2];
        $r = $this->actingAs($this->recipient)->get($this->register($query))->assertOk()->assertDontSee('name="prerequisite_register_page"', false)->assertDontSee('DO_NOT_PROPAGATE');
        $p = $r->viewData('prerequisites');
        $this->assertSame(27, $p->total());
        $this->assertCount(2, $p);
        $this->assertSame(array_reverse(array_slice($ids, 0, 2)), $p->pluck('id')->all());
        $url = $p->url(1);
        foreach (['lang=fa', 'prerequisite_state=OPEN', 'prerequisite_company=', 'prerequisite_notice='] as $part) {
            $this->assertStringContainsString($part, $url);
        }$this->assertStringNotContainsString('private_unrelated', $url);
        $first = $this->get($this->register(array_diff_key($query, ['prerequisite_register_page' => true])))->assertOk()->viewData('prerequisites');
        $this->assertCount(25, $first);
        $this->assertSame([], $first->pluck('id')->intersect($p->pluck('id'))->all());
        $r = $this->get($this->register(['prerequisite_register_page' => 99]))->assertOk();
        $this->assertCount(0, $r->viewData('prerequisites'));
        $this->assertSame(27, $r->viewData('prerequisites')->total());
    }

    public function test_cancelled_and_archived_project_records_remain_visible_without_relabelling_prerequisite_state_or_allowing_writes(): void
    {
        $this->issue();
        $this->create();
        $before = $this->prerequisite();
        $this->service()->cancel($this->project, $this->writer, $this->id, ['version' => 2, 'reason' => 'Cancel for register']);
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only register');
        $r = $this->actingAs($this->recipient)->get($this->register(['prerequisite_state' => 'OPEN', 'lang' => 'en']))->assertOk()->assertSee('Original notice cancelled')->assertDontSee('method="post"', false);
        $this->assertSame('OPEN', $r->viewData('prerequisites')->sole()->state);
        $this->assertNotNull($r->viewData('prerequisites')->sole()->cancellation_id);
        $this->assertEquals($before, $this->prerequisite());
        $this->assertCount(1, $this->events());
        $this->postJson($this->register(), [])->assertMethodNotAllowed();
    }

    public function test_current_reader_discipline_membership_pack_and_participation_still_bound_register(): void
    {
        $this->issue();
        $this->create();
        $this->actingAs($this->recipient);
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => ['CIVIL']]));
        $r = $this->get($this->register())->assertOk();
        $this->assertSame(0, $r->viewData('prerequisiteVisibleTotal'));
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->update(['discipline_scope' => null]));
        $plan = Plan::where('code', 'full')->first()->replicate();
        $plan->code = 'register-qaqc';
        $plan->modules = ['qaqc'];
        $plan->standards_packs = ['NOT-THIS-PACK'];
        $plan->save();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['plan_id' => $plan->id]));
        $this->getJson($this->register())->assertForbidden();
        $plan->standards_packs = [$this->project->standards_pack];
        $plan->save();
        $r = $this->actingAs($this->anotherReader())->get($this->register())->assertOk();
        $this->assertSame(1, $r->viewData('prerequisites')->total());
        $this->actingAs($this->recipient);
        $this->asRecipient(fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->recipient->company_id)->delete());
        $this->getJson($this->register())->assertNotFound();
    }

    public function test_other_project_is_independently_scoped_even_for_same_company_multi_project_reader(): void
    {
        $this->issue();
        $this->create(['item_reference' => 'PH14ONLY']);
        $r = $this->actingAs($this->writer)->get('/projects/BB-2/inspection-notifications/prerequisites')->assertOk()->assertDontSee('PH14ONLY');
        $this->assertSame(0, $r->viewData('prerequisiteVisibleTotal'));
        $this->actingAs($this->recipient)->getJson('/projects/BB-2/inspection-notifications/prerequisites')->assertNotFound();
        $this->actingAs($this->writer)->getJson('/projects/KISH-1/inspection-notifications/prerequisites')->assertNotFound();
    }

    public function test_register_is_bilingual_escaped_and_uses_recorded_names_not_live_foreign_directory_or_private_history(): void
    {
        $data = $this->draft();
        $data['notes_en'] = 'PRIVATEOLDREGISTERDRAFT';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $data);
        unset($data['itp_revision_id'],$data['itp_stage_id']);
        $data['version'] = 1;
        $data['notes_en'] = 'CURRENTNOTICEONLY';
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $data);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['name' => 'RECORDEDACTOR <script>REGXSS()</script>']));
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['name_en' => 'RECORDEDCOMPANY <img src="x">']));
        $this->create(['item_reference' => 'ITEM <script>ITEMXSS()</script>']);
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->recipient->company_id)->update(['name_en' => 'LATERFOREIGNCOMPANY']));
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['name' => 'LATERACTORNAME']));
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->writer)->get($this->register(['lang' => $lang]))->assertOk()->assertSee('ITEM &lt;script&gt;ITEMXSS()&lt;/script&gt;', false)->assertDontSee('<script>ITEMXSS()', false)->assertSee('RECORDEDACTOR &lt;script&gt;REGXSS()&lt;/script&gt;', false)->assertDontSee('LATERACTORNAME')->assertDontSee('PRIVATEOLDREGISTERDRAFT')->assertDontSee('CURRENTNOTICEONLY');
            if ($lang === 'en') {
                $r->assertSee('RECORDEDCOMPANY &lt;img src=&quot;x&quot;&gt;', false)->assertDontSee('LATERFOREIGNCOMPANY');
            }
            if (getenv('EPCC_PREREQUISITE_REGISTER_EVIDENCE') === '1') {
                file_put_contents(base_path('../docs/evidence/2026-10-20-inspection-prerequisite-register/register-'.$lang.'.html'), $r->getContent());
            }
        }
        foreach ($queries as $sql) {
            foreach (['inspection_notification_draft_history', 'inspection_itp_stages', 'document_revisions', 'inspection_notification_prerequisite_events'] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
    }

    public function test_restored_inherited_stage_context_still_uses_retained_snapshot_and_no_live_sources(): void
    {
        $this->issue();
        $this->create();
        $notice = $this->row();
        $events = $this->events();
        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->recipient)->get($this->endpoint().'?lang='.$lang)->assertOk()->assertSee($this->stage)->assertSee($this->revision)->assertSee('id="inspection-prerequisite-stage"', false);
            $this->assertSame($this->stage, $r->viewData('prerequisiteStage')['stage_id']);
            $this->assertCount(9, $r->viewData('prerequisiteStage'));
            $r = $this->get($this->base.'/'.$this->id.'?lang='.$lang)->assertOk();
            $this->assertSame($this->revision, $r->viewData('prerequisiteStage')['revision_id']);
        }
        foreach ($queries as $sql) {
            foreach (['inspection_itp_stages', 'document_revisions', 'inspection_notification_draft_history'] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
        $this->assertEquals($notice, $this->row());
        $this->assertEquals($events, $this->events());
    }

    public function test_each_current_state_filters_exactly_and_latest_followup_refreshes_without_creating_history(): void
    {
        $this->issue();
        $open = $this->create(['item_reference' => 'STAYS OPEN']);
        $reported = $this->create(['item_reference' => 'COMPLETION REPORTED']);
        $this->command('REPORT');
        $fulfilled = $this->create(['item_reference' => 'RECIPIENT CONFIRMED']);
        $this->command('REPORT');
        $this->command('CONFIRM');
        $withdrawn = $this->create(['item_reference' => 'RECIPIENT WITHDREW']);
        $this->command('WITHDRAW');
        $this->actingAs($this->recipient);
        foreach (['OPEN' => $open, 'REPORTED' => $reported, 'FULFILLED' => $fulfilled, 'WITHDRAWN' => $withdrawn] as $state => $id) {
            $r = $this->get($this->register(['prerequisite_state' => $state, 'prerequisite_company' => $this->recipient->company_id]))->assertOk();
            $this->assertSame([$id], $r->viewData('prerequisites')->pluck('id')->all());
            $this->assertSame(4, $r->viewData('prerequisiteVisibleTotal'));
        }
        $r = $this->get($this->register(['prerequisite_state' => 'REPORTED', 'prerequisite_company' => $this->writer->company_id]))->assertOk();
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $this->prerequisiteId = $reported;
        $before = $this->events();
        $this->command('REJECT');
        $r = $this->get($this->register(['prerequisite_state' => 'REPORTED']))->assertOk();
        $this->assertSame(0, $r->viewData('prerequisites')->total());
        $r = $this->get($this->register(['prerequisite_state' => 'OPEN']))->assertOk();
        $this->assertSame(2, $r->viewData('prerequisites')->total());
        $this->assertCount($before->count() + 1, $this->events());
    }

    public function test_revoked_active_identity_or_membership_does_not_keep_register_access(): void
    {
        $this->issue();
        $this->create();
        $this->actingAs($this->recipient);
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['is_active' => false]));
        $this->getJson($this->register())->assertNotFound();
        $this->asRecipient(fn () => User::whereKey($this->recipient->id)->update(['is_active' => true]));
        $this->asRecipient(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->recipient->id)->delete());
        $this->getJson($this->register())->assertNotFound();
    }

    public function test_restored_stage_unknown_fallback_zero_and_injection_behavior_are_preserved(): void
    {
        $this->issue();
        $this->create();
        $snapshot = json_decode($this->row()->issued_snapshot, true);
        $notice = $this->row();
        foreach (['stage_id', 'itp_stage_id', 'revision_id', 'itp_revision_id', 'document_id'] as $key) {
            $this->actingAs($this->recipient)->postJson($this->base.'/'.$this->id.'/prerequisites', ['prerequisite' => ['item_reference' => 'A', 'requirement' => 'B', $key => (string) Str::uuid()]])->assertUnprocessable();
        }
        $snapshot['description_fa'] = null;
        $snapshot['description_en'] = '<script>RESTOREDXSS()</script>';
        $notice->issued_snapshot = json_encode($snapshot);
        $context = InspectionNotificationPrerequisiteStage::fromNotice($notice);
        $html = view('inspection-notifications.prerequisite-stage', ['prerequisiteStage' => $context, 'lang' => 'fa'])->render();
        $this->assertStringContainsString('&lt;script&gt;RESTOREDXSS()&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>RESTOREDXSS()', $html);
        $context['description_fa'] = '0';
        $html = view('inspection-notifications.prerequisite-stage', ['prerequisiteStage' => $context, 'lang' => 'fa'])->render();
        $this->assertStringContainsString('>0</dd>', $html);
        $this->assertStringNotContainsString('RESTOREDXSS()', $html);
        foreach (['en', 'fa'] as $lang) {
            app()->setLocale($lang);
            $html = view('inspection-notifications.prerequisite-stage', ['prerequisiteStage' => null, 'lang' => $lang])->render();
            $this->assertStringContainsString(__('inspection_notifications.prerequisite_itp_unknown'), $html);
            $this->assertStringNotContainsString('href=', $html);
        }
    }
}
