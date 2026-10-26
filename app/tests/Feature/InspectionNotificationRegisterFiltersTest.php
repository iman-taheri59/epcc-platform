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
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class InspectionNotificationRegisterFiltersTest extends TestCase
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

    private function listing(array $filters = [])
    {
        return $this->get($this->base.'?'.http_build_query($filters));
    }

    private function add(string $work, string $start = '2026-10-25T12:30:00+03:30', string $end = '2026-10-25T13:30:00+03:30'): string
    {
        $d = $this->draft();
        $d['work_reference'] = $work;
        $d['activity_start_at'] = $start;
        $d['activity_end_at'] = $end;

        return $this->service()->createDraft($this->project, $this->writer, $d);
    }

    private function resultIds($response): array
    {
        $response->assertOk();

        return collect($response->viewData('notifications')->items())->pluck('id')->all();
    }

    public function test_optional_filter_helpers_keep_canonical_get_names_and_required_draft_fields_independent(): void
    {
        $before = $this->row();
        foreach (['en', 'fa'] as $lang) {
            foreach (['empty' => [], 'prefilled' => ['activity_from' => '2026-10-25T09:30:45+03:30', 'activity_to' => '2026-10-25T09:30:45+03:30']] as $kind => $bounds) {
                $response = $this->actingAs($this->writer)->listing($bounds + ['lang' => $lang, 'stage' => $this->stage, 'notice_q' => 'SYNTHETIC', 'notice_state' => 'DRAFT']);
                $response->assertOk();
                $dom = new \DOMDocument;
                @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
                $xpath = new \DOMXPath($dom);
                $this->assertSame(2, $xpath->query('//fieldset[@data-time-optional]')->length);
                foreach (['from', 'to'] as $boundary) {
                    $nodes = $xpath->query('//form[@id="inspection-notification-filters"]//input[@name="activity_'.$boundary.'"]');
                    $this->assertSame(1, $nodes->length);
                    $this->assertFalse($nodes->item(0)->hasAttribute('required'));
                    $this->assertSame($bounds['activity_'.$boundary] ?? '', $nodes->item(0)->getAttribute('value'));
                }
                foreach (['start', 'end'] as $boundary) {
                    $nodes = $xpath->query('//input[@id="notification-'.$boundary.'-exact"]');
                    $this->assertSame(1, $nodes->length);
                    $this->assertTrue($nodes->item(0)->hasAttribute('required'));
                    $this->assertSame('notification[activity_'.$boundary.'_at]', $nodes->item(0)->getAttribute('name'));
                }
                $ids = [];
                foreach ($xpath->query('//*[@id]') as $node) {
                    $ids[] = $node->getAttribute('id');
                }
                $this->assertCount(count(array_unique($ids)), $ids);
                if (getenv('EPCC_FILTER_UI_EVIDENCE') === '1') {
                    $dir = base_path('../docs/evidence/2026-10-20-inspection-notification-filter-ui');
                    if (! is_dir($dir)) {
                        mkdir($dir, 0775, true);
                    }
                    $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-test-token"', $response->getContent());
                    file_put_contents($dir.'/'.$kind.'-'.$lang.'.html', $html);
                }
            }
            $edit = $this->get($this->base.'/'.$this->id.'/edit?lang='.$lang)->assertOk();
            if (getenv('EPCC_FILTER_UI_EVIDENCE') === '1') {
                file_put_contents($dir.'/edit-'.$lang.'.html', preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value="synthetic-test-token"', $edit->getContent()));
            }
        }
        $this->assertEquals($before, $this->row());
    }

    public function test_bilingual_filter_form_counts_and_default_results_preserve_existing_reader_scope(): void
    {
        $this->issue();
        $private = $this->add('Private draft activity');
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->writer)->listing(['lang' => $lang]);
            $this->assertCount(2, $this->resultIds($r));
            $this->assertSame(2, $r->viewData('noticeVisibleTotal'));
            $r->assertSee('inspection-notification-filters')->assertSee('name="notice_q"', false)->assertSee('name="activity_from"', false)->assertSee('inspection-notification-counts');
            $r = $this->actingAs($this->recipient)->listing(['lang' => $lang, 'notice_state' => 'DRAFT', 'notice_q' => 'Private']);
            $this->assertSame([], $this->resultIds($r));
            $this->assertSame(1, $r->viewData('noticeVisibleTotal'));
            $r->assertDontSee($private);
        }
        $r = $this->actingAs($this->owner)->listing(['notice_q' => 'Private']);
        $this->assertSame([], $this->resultIds($r));
        $this->assertSame(0, $r->viewData('noticeVisibleTotal'));
    }

    public function test_search_is_literal_bound_and_case_insensitive_for_current_number_or_activity_only(): void
    {
        $special = $this->add('Valve_50% ! \ فاز آزمایشی');
        $zero = $this->add('0');
        $this->issue();
        $number = $this->row()->number;
        $this->actingAs($this->writer);
        foreach (['Valve_50%', '%', '_', '!', chr(92), 'فاز'] as $term) {
            $this->assertSame([$special], $this->resultIds($this->listing(['notice_q' => $term])));
        }
        $this->assertEqualsCanonicalizing([$zero, $special, $this->id], $this->resultIds($this->listing(['notice_q' => '0'])));
        $this->assertSame([$this->id], $this->resultIds($this->listing(['notice_q' => strtolower($number)])));
        $this->assertSame([], $this->resultIds($this->listing(['notice_q' => "%' OR true --"])));
        $this->assertSame([], $this->resultIds($this->listing(['notice_q' => $number, 'notice_state' => 'DRAFT'])));
        $r = $this->listing(['notice_state' => 'ISSUED']);
        $this->assertSame([$this->id], $this->resultIds($r));
        $this->assertSame(1, $r->viewData('revisions')->total());
        $this->assertSame(1, $r->viewData('stages')->total());
    }

    public function test_activity_overlap_is_inclusive_offset_aware_and_accepts_one_bound_or_an_instant(): void
    {
        $other = $this->add('Later interval');
        $this->actingAs($this->writer);
        $cases = [
            [['activity_from' => '2026-10-25T10:30:00+03:30', 'activity_to' => '2026-10-25T09:00:00+00:00'], [$this->id, $other]],
            [['activity_from' => '2026-10-25T08:00:00+00:00'], [$this->id, $other]],
            [['activity_to' => '2026-10-25T06:00:00+00:00'], [$this->id]],
            [['activity_from' => '2026-10-25T08:00:00+00:00', 'activity_to' => '2026-10-25T08:00:00+00:00'], [$this->id]],
            [['activity_from' => '2026-10-25T09:00:01+00:00'], [$other]],
            [['activity_to' => '2026-10-25T05:59:59+00:00'], []],
        ];
        foreach ($cases as [$query,$ids]) {
            $actual = $this->resultIds($this->listing($query));
            sort($actual);
            sort($ids);
            $this->assertSame($ids, $actual);
        }
    }

    public function test_malformed_filters_are_refused_without_mutating_notice_or_history(): void
    {
        $before = $this->row();
        $this->actingAs($this->writer);
        foreach ([['notice_q' => ['nested']], ['notice_q' => str_repeat('x', 201)], ['notice_state' => ['DRAFT']], ['notice_state' => 'SUPERSEDED'], ['activity_from' => ['nested']], ['activity_to' => '2026-02-30T09:30:00+03:30'], ['activity_from' => '2026-10-25T09:30:00'], ['activity_from' => '2026-10-25T09:30:00+03:30', 'activity_to' => '2026-10-25T08:00:00+03:30']] as $bad) {
            $this->getJson($this->base.'?'.http_build_query($bad))->assertUnprocessable();
        }
        $this->listing(['activity_from' => 'bad'])->assertUnprocessable()->assertHeaderMissing('location');
        $this->assertEquals($before, $this->row());
        $this->assertSame(0, $this->asWriter(fn () => DB::table('inspection_notification_draft_history')->count()));
    }

    public function test_notice_paging_language_and_source_context_are_preserved_but_filter_submit_and_clear_reset_notice_page(): void
    {
        for ($i = 1; $i <= 26; $i++) {
            $this->add('FILTERMATCH '.$i);
        }
        $this->actingAs($this->writer);
        $r = $this->listing(['lang' => 'fa', 'stage' => $this->stage, 'notice_q' => 'FILTERMATCH', 'notice_page' => 2, 'revision_page' => 3, 'stage_page' => 2])->assertOk();
        $this->assertSame(26, $r->viewData('notifications')->total());
        $this->assertCount(1, $r->viewData('notifications')->items());
        $this->assertSame(27, $r->viewData('noticeVisibleTotal'));
        $this->assertSame(1, $r->viewData('revisions')->total());
        $this->assertSame(1, $r->viewData('stages')->total());
        $doc = new \DOMDocument;
        $doc->loadHTML($r->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xp = new \DOMXPath($doc);
        $form = $xp->query('//form[@id="inspection-notification-filters"]')->item(0);
        $this->assertSame(0, $xp->query('.//input[@name="notice_page"]', $form)->length);
        $this->assertSame('3', $xp->query('.//input[@name="revision_page"]', $form)->item(0)->getAttribute('value'));
        $clear = $xp->query('//a[@id="inspection-notification-clear-filters"]')->item(0)->getAttribute('href');
        parse_str(parse_url($clear, PHP_URL_QUERY), $params);
        $this->assertSame($this->stage, $params['stage']);
        $this->assertSame('fa', $params['lang']);
        $this->assertArrayNotHasKey('notice_q', $params);
        $this->assertArrayNotHasKey('notice_page', $params);
        $english = $xp->query('//a[normalize-space(text())="English"]')->item(0)->getAttribute('href');
        parse_str(parse_url($english, PHP_URL_QUERY), $params);
        $this->assertSame('FILTERMATCH', $params['notice_q']);
        $this->assertSame('2', $params['notice_page']);
        $this->assertSame('en', $params['lang']);
        $r = $this->listing(['notice_q' => 'FILTERMATCH']);
        $this->assertCount(25, $r->viewData('notifications')->items());
        $r->assertSee('notice_page=2', false)->assertSee('notice_q=FILTERMATCH', false);
    }

    public function test_filter_does_not_search_prior_private_history_notes_or_expand_recipient_rights(): void
    {
        $d = $this->draft();
        $d['work_reference'] = 'PRIVATEOLDREFERENCE';
        $this->id = $this->service()->createDraft($this->project, $this->writer, $d);
        unset($d['itp_revision_id'],$d['itp_stage_id']);
        $d['version'] = 1;
        $d['work_reference'] = 'CURRENTREFERENCE';
        $d['notes_en'] = 'CURRENTNOTES';
        $this->service()->updateDraft($this->project, $this->writer, $this->id, $d);
        $this->service()->issue($this->project, $this->writer, $this->id, ['version' => 2]);
        $this->actingAs($this->writer);
        $this->assertSame([], $this->resultIds($this->listing(['notice_q' => 'PRIVATEOLDREFERENCE'])));
        $queries = [];
        DB::listen(function ($q) use (&$queries): void {
            $queries[] = $q->sql;
        });
        $this->actingAs($this->recipient);
        $this->assertSame([$this->id], $this->resultIds($this->listing(['notice_q' => 'CURRENTREFERENCE', 'notice_state' => 'ISSUED'])));
        $this->assertSame([], $this->resultIds($this->listing(['notice_q' => 'CURRENTNOTES'])));
        foreach ($queries as $sql) {
            $this->assertStringNotContainsString('inspection_notification_draft_history', $sql);
        }
    }

    public function test_current_discipline_plan_and_active_user_gates_apply_before_search_and_counts(): void
    {
        $this->issue();
        $this->actingAs($this->writer);
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['CIVIL']]));
        $r = $this->listing(['notice_state' => 'ISSUED']);
        $this->assertSame([], $this->resultIds($r));
        $this->assertSame(0, $r->viewData('noticeVisibleTotal'));
        $this->asWriter(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => null]));
        $starter = Plan::where('code', 'starter')->value('id');
        $full = Plan::where('code', 'full')->value('id');
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->getJson($this->base.'?notice_q=abc')->assertForbidden();
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        $this->asWriter(fn () => User::whereKey($this->writer->id)->update(['is_active' => false]));
        $this->getJson($this->base.'?notice_q=abc')->assertNotFound();
    }

    public function test_filtered_get_is_nonmutating_and_search_text_is_html_escaped(): void
    {
        $tables = ['inspection_notifications', 'inspection_notification_draft_history', 'inspection_itp_stages', 'inspection_notification_settings', 'purchase_orders', 'material_requisitions', 'material_lots', 'material_movements'];
        $snapshot = fn () => $this->asWriter(function () use ($tables) {
            $out = [];
            foreach ($tables as $t) {
                $out[$t] = DB::table($t)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
        $before = $snapshot();
        $this->actingAs($this->writer)->listing(['notice_q' => '<script>FILTERXSS()</script>', 'notice_state' => 'DRAFT', 'activity_from' => '2026-10-25T00:00:00+03:30'])->assertOk()->assertSee('&lt;script&gt;FILTERXSS()&lt;/script&gt;', false)->assertDontSee('<script>FILTERXSS()', false);
        $this->assertSame($before, $snapshot());
    }
}
