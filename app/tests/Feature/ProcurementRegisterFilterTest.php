<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\Plan;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProcurementRegisterFilterTest extends TestCase
{
    private $writer;

    private $owner;

    private Project $project;

    private string $vendor;

    private array $mrs;

    private array $pos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $policy = ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Synthetic register filtering'];
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, $policy);
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $policy);
        $this->vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        $this->mrs = [$this->create('mr', 'Needle pipe request', 'لوله ویژه'), $this->create('mr', 'Other request', 'قلم دیگر')];
        $this->pos = [$this->create('po', 'Needle pipe order', 'لوله ویژه'), $this->create('po', 'Other order', 'قلم دیگر')];
        $this->actingAs($this->writer);
    }

    private function bound(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $fn, $this->project->id);
    }

    private function create(string $kind, string $en, string $fa = 'عنوان آزمایشی', string $category = 'PIPE'): mixed
    {
        $data = ['title_en' => $en, 'title_fa' => $fa, 'discipline' => 'PIPING', 'material_category' => $category,
            'lines' => [['item_code' => 'HEADER-ONLY-NOT-SEARCHABLE', 'description_en' => 'Line description not searched', 'description_fa' => 'شرح خط جست‌وجو نمی‌شود', 'unit' => 'EA', 'quantity' => '2.100']]];
        if ($kind === 'po') {
            $data['vendor_company_id'] = $this->vendor;
        }

        return app($kind === 'mr' ? MaterialRequisitionEntryService::class : PurchaseOrderEntryService::class)->create($this->project, $this->writer, $data);
    }

    private function url(array $filters = [], string $project = 'PH-14'): string
    {
        return '/projects/'.$project.'/procurement?'.http_build_query(['lang' => 'en'] + $filters);
    }

    private function assertRows($response, array $mrIds, array $poIds): void
    {
        $response->assertOk();
        $this->assertEqualsCanonicalizing($mrIds, $response->viewData('requisitions')->modelKeys());
        $this->assertEqualsCanonicalizing($poIds, $response->viewData('orders')->modelKeys());
    }

    /** Synthetic legacy headers: deliberately no new human author/issue/receipt authority. */
    private function pagedFixtures(): array
    {
        return $this->bound(function (): array {
            $rows = ['mr' => [], 'po' => []];
            for ($i = 1; $i <= 26; $i++) {
                foreach (['mr', 'po'] as $kind) {
                    $row = ['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
                        'number' => 'PAGE-'.strtoupper($kind).'-'.$i, 'sequence' => 1000, 'state' => 'DRAFT', 'discipline' => 'PIPING',
                        'material_category' => 'PIPE', 'title_en' => 'Paged match', 'title_fa' => 'نتایج صفحه‌بندی', 'created_at' => now(), 'updated_at' => now()];
                    if ($kind === 'mr') {
                        $row += ['raised_by' => $this->writer->id, 'raised_at' => now()];
                    } else {
                        $row['vendor_company_id'] = $this->vendor;
                    }
                    $rows[$kind][] = $row;
                }
            }
            DB::table('material_requisitions')->insert($rows['mr']);
            DB::table('purchase_orders')->insert($rows['po']);
            $ids = [];
            foreach ($rows as $kind => $records) {
                $ids[$kind] = array_column($records, 'id');
                rsort($ids[$kind], SORT_STRING);
            }

            return $ids;
        });
    }

    private function pageFilters(array $extra = []): array
    {
        return $extra + ['mr_q' => 'Paged', 'po_q' => 'Paged', 'material_category' => 'PIPE', 'mr_state' => 'DRAFT', 'po_state' => 'DRAFT'];
    }

    private function urlQuery(string $url): array
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);

        return $query;
    }

    public function test_register_money_keeps_exact_decimal_currency_zero_and_unknown_in_both_languages(): void
    {
        $orders = [];
        foreach (['9999999999999999.99', '12.34', '0.00'] as $price) {
            $orders[] = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, [
                'title_en' => 'Exact displayed money', 'title_fa' => 'نمایش دقیق مبلغ', 'discipline' => 'PIPING', 'material_category' => 'PIPE',
                'vendor_company_id' => $this->vendor, 'currency' => 'EUR', 'lines' => [['item_code' => 'EXACT-MONEY', 'description_en' => 'Money display fixture',
                    'description_fa' => 'آزمون نمایش مبلغ', 'unit' => 'EA', 'quantity' => '1.000', 'unit_price' => $price]],
            ]);
        }
        $orders[] = $this->pos[0];
        $before = $this->bound(fn () => DB::table('purchase_orders')->orderBy('id')->get()->toJson());
        foreach (['en' => ['9999999999999999.99 EUR', '12.34 EUR', '0.00 EUR', '—'],
            'fa' => [str_repeat('۹', 16).'.۹۹ EUR', '۱۲.۳۴ EUR', '۰.۰۰ EUR', '—']] as $lang => $expected) {
            $page = $this->get(str_replace('lang=en', 'lang='.$lang, $this->url()))->assertOk();
            $doc = new \DOMDocument;
            $prior = libxml_use_internal_errors(true);
            $doc->loadHTML($page->getContent(), LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($prior);
            $xpath = new \DOMXPath($doc);
            foreach ($orders as $index => $order) {
                $nodes = $xpath->query('//a[contains(@href,"'.$order->id.'")]/ancestor::tr[1]/td[5]');
                $this->assertSame(1, $nodes->length);
                $this->assertSame($expected[$index], trim($nodes->item(0)->textContent));
            }
        }
        $this->assertSame($before, $this->bound(fn () => DB::table('purchase_orders')->orderBy('id')->get()->toJson()));
    }

    public function test_mr_and_po_paginate_independently_with_stable_tie_order_and_filtered_totals(): void
    {
        $ids = $this->pagedFixtures();
        foreach ([[1, 1], [2, 1], [1, 2], [2, 2]] as [$mrPage, $poPage]) {
            $page = $this->get($this->url($this->pageFilters(['mr_page' => $mrPage, 'po_page' => $poPage])))->assertOk();
            foreach (['mr' => ['requisitions', $mrPage], 'po' => ['orders', $poPage]] as $kind => [$name, $number]) {
                $documents = $page->viewData($name);
                $this->assertSame(26, $documents->total());
                $this->assertSame(25, $documents->perPage());
                $this->assertSame($number, $documents->currentPage());
                $this->assertSame(2, $documents->lastPage());
                $this->assertSame(array_slice($ids[$kind], ($number - 1) * 25, 25), $documents->modelKeys());
            }
        }
    }

    public function test_page_links_keep_other_register_filters_category_and_language_but_filter_form_resets_pages(): void
    {
        $this->pagedFixtures();
        $page = $this->get(str_replace('lang=en', 'lang=fa', $this->url($this->pageFilters(['mr_page' => 2, 'po_page' => 2]))))->assertOk();
        foreach (['mr' => 'requisitions', 'po' => 'orders'] as $kind => $name) {
            $documents = $page->viewData($name);
            $query = $this->urlQuery($documents->url(1));
            $this->assertSame('1', $query[$kind.'_page']);
            $this->assertSame('2', $query[($kind === 'mr' ? 'po' : 'mr').'_page']);
            $this->assertSame('fa', $query['lang']);
            foreach ($this->pageFilters() as $key => $value) {
                $this->assertSame($value, $query[$key]);
            }
            $page->assertSee($documents->url(1));
        }
        $page->assertSee('ردیف ۲۶ تا ۲۶ از ۲۶ سند منطبق')->assertSee('صفحهٔ اول');
        $doc = new \DOMDocument;
        $prior = libxml_use_internal_errors(true);
        $doc->loadHTML($page->getContent(), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prior);
        $this->assertSame(0, (new \DOMXPath($doc))->query('//form[@method="get"]//*[@name="mr_page" or @name="po_page"]')->length);
        $fresh = $this->get($this->url($this->pageFilters()))->assertOk();
        $this->assertSame(1, $fresh->viewData('requisitions')->currentPage());
        $this->assertSame(1, $fresh->viewData('orders')->currentPage());
        $reset = route('procurement', ['projectCode' => 'PH-14', 'lang' => 'fa', 'material_category' => 'PIPE']);
        $page->assertSee($reset);
        $this->assertSame(['lang' => 'fa', 'material_category' => 'PIPE'], $this->urlQuery($reset));
    }

    public function test_late_counter_covers_all_filtered_matches_not_only_current_po_page(): void
    {
        $ids = $this->pagedFixtures();
        $this->bound(function () use ($ids): void {
            DB::table('purchase_orders')->where('id', $ids['po'][25])->update(['promised_date' => '2026-12-01', 'forecast_date' => '2026-12-02']);
            DB::table('purchase_orders')->where('id', $ids['po'][0])->update(['promised_date' => '2026-12-02', 'forecast_date' => '2026-12-02']);
            DB::table('purchase_orders')->where('id', $ids['po'][1])->update(['promised_date' => '2026-12-02', 'forecast_date' => '2026-12-01']);
            DB::table('purchase_orders')->where('id', $ids['po'][2])->update(['forecast_date' => '2026-12-02']);
            foreach (['CANCELLED' => 'PIPE', 'DRAFT' => 'FLANGE'] as $state => $category) {
                PurchaseOrder::forceCreate(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id, 'vendor_company_id' => $this->vendor,
                    'number' => 'LATE-EXCLUDED-'.$state, 'sequence' => 9000, 'state' => $state, 'title_en' => 'Paged match', 'title_fa' => 'دیرکرد خارج از فیلتر',
                    'discipline' => 'PIPING', 'material_category' => $category, 'promised_date' => '2026-12-01', 'forecast_date' => '2026-12-02']);
            }
        });
        foreach ([1 => 0, 2 => 1] as $number => $pageLateCount) {
            $page = $this->get($this->url($this->pageFilters(['po_page' => $number])))->assertOk();
            $this->assertSame(26, $page->viewData('orders')->total());
            $this->assertSame(1, $page->viewData('lateOrderCount'));
            $this->assertSame($pageLateCount, $page->viewData('orders')->filter(fn ($order) => $order->isLate())->count());
        }
        $empty = $this->get($this->url($this->pageFilters(['po_q' => 'No match'])))->assertOk();
        $this->assertSame(0, $empty->viewData('orders')->total());
        $this->assertSame(0, $empty->viewData('lateOrderCount'));
    }

    public function test_total_and_late_counts_remain_hidden_from_competing_buyer_on_every_page(): void
    {
        $this->pagedFixtures();
        $this->bound(fn () => DB::table('purchase_orders')->where('title_en', 'Paged match')->update(['promised_date' => '2026-12-01', 'forecast_date' => '2026-12-02']));
        $this->actingAs($this->userByEmail('mousavi@sahand.example'));
        $page = $this->get($this->url($this->pageFilters(['mr_page' => 2, 'po_page' => 2])))->assertOk();
        $this->assertSame(0, $page->viewData('requisitions')->total());
        $this->assertSame(0, $page->viewData('orders')->total());
        $this->assertSame(0, $page->viewData('lateOrderCount'));
        $page->assertSee('No visible requisitions match these filters.')->assertDontSee('matching documents exist.');
        $this->actingAs($this->owner);
        $visible = $this->get($this->url($this->pageFilters(['mr_page' => 2, 'po_page' => 2])))->assertOk();
        $this->assertSame(26, $visible->viewData('requisitions')->total());
        $this->assertSame(26, $visible->viewData('orders')->total());
        $this->assertSame(26, $visible->viewData('lateOrderCount'));
    }

    public function test_page_parameter_types_and_positive_bounded_range_are_validated(): void
    {
        foreach (['mr_page', 'po_page'] as $key) {
            foreach ([0, -1, '1.5', 'NaN', ['2'], '1000001', '9999999999999999999999999999'] as $bad) {
                $this->getJson($this->url([$key => $bad]))->assertUnprocessable()->assertJsonValidationErrors($key);
            }
        }
    }

    public function test_out_of_range_pages_keep_true_totals_and_provide_independent_first_page_recovery(): void
    {
        $ids = $this->pagedFixtures();
        $page = $this->get($this->url($this->pageFilters(['mr_page' => 99, 'po_page' => 99])))->assertOk()
            ->assertSee('No rows on this page; matching documents exist.')->assertDontSee('No visible requisitions match these filters.');
        foreach (['mr' => 'requisitions', 'po' => 'orders'] as $kind => $name) {
            $documents = $page->viewData($name);
            $this->assertSame(26, $documents->total());
            $this->assertCount(0, $documents);
            $this->assertSame(99, $documents->currentPage());
            $first = $this->get($documents->url(1))->assertOk();
            $this->assertSame(array_slice($ids[$kind], 0, 25), $first->viewData($name)->modelKeys());
            $other = $first->viewData($kind === 'mr' ? 'orders' : 'requisitions');
            $this->assertSame(99, $other->currentPage());
            $this->assertSame(26, $other->total());
            $this->assertCount(0, $other);
        }
    }

    public function test_only_current_page_headers_and_relation_ids_are_materialized(): void
    {
        $ids = $this->pagedFixtures();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $page = $this->get($this->url($this->pageFilters(['mr_page' => 2, 'po_page' => 2])))->assertOk();
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
        foreach (['mr' => ['requisitions', 'material_requisitions', 'material_requisition_items'], 'po' => ['orders', 'purchase_orders', 'purchase_order_items']] as $kind => [$name, $headers, $lines]) {
            $this->assertCount(1, $page->viewData($name));
            $headerQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'from "'.$headers.'"') && str_contains($q['query'], 'limit 25 offset 25'));
            $this->assertCount(1, $headerQueries);
            $lineQueries = collect($queries)->filter(fn ($q) => str_contains($q['query'], 'from "'.$lines.'"'));
            $this->assertCount(1, $lineQueries);
            $this->assertSame([$ids[$kind][25]], array_values(array_intersect($lineQueries->first()['bindings'], $ids[$kind])));
        }
    }

    private function inspectionFixtures(): array
    {
        return $this->bound(function (): array {
            $rows = [];
            for ($i = 1; $i <= 26; $i++) {
                $rows[] = ['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
                    'purchase_order_id' => $this->pos[0]->id, 'number' => 'PAGE-INSPECTION-'.$i, 'sequence' => 1000,
                    'result' => 'FAIL', 'stage' => 'WORKS', 'findings_en' => '<script>inspection()</script>', 'findings_fa' => 'یافتهٔ بازرسی',
                    'inspected_by' => $this->writer->id, 'inspected_at' => now(), 'created_at' => now(), 'updated_at' => now()];
            }
            // Read fixtures, not a new human inspection-recording permission or operational receipt test.
            DB::table('material_inspections')->insert($rows);
            $ids = array_column($rows, 'id');
            rsort($ids, SORT_STRING);

            return $ids;
        });
    }

    public function test_inspection_pages_preserve_all_three_page_parameters_and_independent_results(): void
    {
        $this->pagedFixtures();
        $ids = $this->inspectionFixtures();
        foreach ([1, 2] as $number) {
            $page = $this->get($this->url($this->pageFilters(['mr_page' => 2, 'po_page' => 2, 'inspection_page' => $number])))->assertOk();
            $inspections = $page->viewData('inspections');
            $this->assertSame(26, $inspections->total());
            $this->assertSame(25, $inspections->perPage());
            $this->assertSame(array_slice($ids, ($number - 1) * 25, 25), $inspections->modelKeys());
            foreach (['requisitions', 'orders'] as $key) {
                $this->assertSame(2, $page->viewData($key)->currentPage());
                $query = $this->urlQuery($page->viewData($key)->url(1));
                $this->assertSame((string) $number, $query['inspection_page']);
            }
            $query = $this->urlQuery($inspections->url(1));
            $this->assertSame('2', $query['mr_page']);
            $this->assertSame('2', $query['po_page']);
            foreach ($this->pageFilters() as $key => $value) {
                $this->assertSame($value, $query[$key]);
            }
        }
        $fa = $this->get(str_replace('lang=en', 'lang=fa', $this->url(['inspection_page' => 2, 'mr_q' => 'No match', 'po_q' => 'No match', 'material_category' => 'FLANGE'])))->assertOk();
        $this->assertSame(26, $fa->viewData('inspections')->total());
        $this->assertSame(0, $fa->viewData('orders')->total());
        $this->assertSame('fa', $this->urlQuery($fa->viewData('inspections')->url(1))['lang']);
        $fa->assertSee('ردیف ۲۶ تا ۲۶ از ۲۶ سند منطبق')->assertSee('بازرسی همچنان مشورتی است');
    }

    public function test_inspection_page_validation_and_out_of_range_recovery_keep_real_total(): void
    {
        $this->inspectionFixtures();
        foreach ([0, -1, '1.5', ['2'], '1000001'] as $bad) {
            $this->getJson($this->url(['inspection_page' => $bad]))->assertUnprocessable()->assertJsonValidationErrors('inspection_page');
        }
        $page = $this->get($this->url(['inspection_page' => 99, 'mr_page' => 2, 'po_page' => 2]))->assertOk()->assertSee('No rows on this page; matching documents exist.');
        $documents = $page->viewData('inspections');
        $this->assertCount(0, $documents);
        $this->assertSame(26, $documents->total());
        $first = $this->get($documents->url(1))->assertOk();
        $this->assertCount(25, $first->viewData('inspections'));
        $this->assertSame(2, $first->viewData('orders')->currentPage());
        $this->assertSame(2, $first->viewData('requisitions')->currentPage());
        $this->assertSame(1, $this->get($this->url())->assertOk()->viewData('inspections')->currentPage());
    }

    public function test_inspection_counts_respect_original_rls_project_and_archived_read_without_mutations(): void
    {
        $this->inspectionFixtures();
        $other = Project::where('code', 'BB-2')->firstOrFail();
        app(TenantContext::class)->runAs($this->writer->company_id, fn () => DB::table('material_inspections')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $other->id, 'company_id' => $this->writer->company_id, 'number' => 'BB2-INSPECTION',
            'sequence' => 9000, 'result' => 'PASS', 'stage' => 'WORKS', 'inspected_by' => $this->writer->id, 'inspected_at' => now(),
        ]), $other->id);
        $this->actingAs($this->userByEmail('mousavi@sahand.example'));
        // Existing policy makes inspections project-participant-visible; do not invent buyer-only hiding.
        $count = app(TenantContext::class)->runAs($this->userByEmail('mousavi@sahand.example')->company_id,
            fn () => DB::table('material_inspections')->where('project_id', $this->project->id)->count(), $this->project->id);
        $this->assertSame($count, $this->get($this->url(['inspection_page' => 2]))->assertOk()->viewData('inspections')->total());
        $this->assertSame(26, $count);
        $outside = $this->userByEmail('ahmadi@nigc.example');
        app(TenantContext::class)->runAs($outside->company_id, fn () => $this->assertSame(0, DB::table('material_inspections')->count()), $this->project->id);
        $this->actingAs($outside)->get($this->url(['inspection_page' => 2]))->assertNotFound();
        $this->actingAs($this->writer);
        $this->assertSame(1, $this->get($this->url([], 'BB-2'))->assertOk()->viewData('inspections')->total());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Inspection read-only paging');
        $snapshot = fn () => $this->bound(function (): array {
            $rows = [];
            foreach (['material_inspections', 'material_requisitions', 'material_requisition_items', 'purchase_orders', 'purchase_order_items', 'po_pricing_revisions', 'po_execution_decisions', 'po_mr_allocations'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $rows;
        });
        $before = $snapshot();
        $page = $this->get($this->url(['inspection_page' => 2]))->assertOk()->assertDontSee('BB2-INSPECTION');
        $this->assertSame(26, $page->viewData('inspections')->total());
        $this->assertSame($before, $snapshot());
    }

    public function test_inspection_query_is_bounded_escaped_and_does_not_eager_load_unused_order_relation(): void
    {
        $this->inspectionFixtures();
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $page = $this->get($this->url(['inspection_page' => 2]))->assertOk()
                ->assertSee('&lt;script&gt;inspection()&lt;/script&gt;', false)->assertDontSee('<script>inspection()</script>', false);
            $queries = DB::connection()->getQueryLog();
        } finally {
            DB::connection()->disableQueryLog();
            DB::connection()->flushQueryLog();
        }
        $this->assertCount(1, $page->viewData('inspections'));
        $this->assertFalse($page->viewData('inspections')->first()->relationLoaded('purchaseOrder'));
        $this->assertCount(1, collect($queries)->filter(fn ($q) => str_contains($q['query'], 'from "material_inspections"') && str_contains($q['query'], 'limit 25 offset 25')));
    }

    public function test_number_english_persian_and_independent_queries_search_only_headers(): void
    {
        $this->assertRows($this->get($this->url(['mr_q' => 'nEeDlE'])), [$this->mrs[0]->id], array_column($this->pos, 'id'));
        $this->assertRows($this->get($this->url(['po_q' => 'OTHER'])), array_column($this->mrs, 'id'), [$this->pos[1]->id]);
        $this->assertRows($this->get($this->url(['mr_q' => 'لوله ویژه', 'po_q' => 'لوله ویژه'])), [$this->mrs[0]->id], [$this->pos[0]->id]);
        $this->assertRows($this->get($this->url(['mr_q' => $this->mrs[1]->number, 'po_q' => $this->pos[1]->number])), [$this->mrs[1]->id], [$this->pos[1]->id]);
        $this->assertRows($this->get($this->url(['mr_q' => 'HEADER-ONLY-NOT-SEARCHABLE', 'po_q' => 'Line description not searched'])), [], []);
        $this->assertRows($this->get($this->url(['mr_q' => '  ', 'po_q' => ''])), array_column($this->mrs, 'id'), array_column($this->pos, 'id'));
    }

    public function test_category_and_state_intersections_cannot_be_escaped_by_title_or_branches(): void
    {
        $this->bound(fn () => app(ProcurementService::class)->submitRequisition($this->mrs[0], $this->writer));
        $this->create('mr', 'Needle flange request', 'لوله ویژه', 'FLANGE');
        $this->create('po', 'Needle flange order', 'لوله ویژه', 'FLANGE');
        // Legacy compatibility record: no human author policy or operational cancellation endpoint.
        $this->bound(fn () => PurchaseOrder::forceCreate(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
            'vendor_company_id' => $this->vendor, 'number' => 'LEGACY-CANCELLED-FILTER', 'sequence' => 9000, 'state' => 'CANCELLED',
            'title_en' => 'Needle cancelled order', 'title_fa' => 'لوله ویژه', 'discipline' => 'PIPING', 'material_category' => 'PIPE']));
        $this->assertRows($this->get($this->url(['material_category' => 'PIPE', 'mr_q' => 'Needle', 'mr_state' => 'SUBMITTED', 'po_q' => 'Needle', 'po_state' => 'DRAFT'])), [$this->mrs[0]->id], [$this->pos[0]->id]);
        $this->assertRows($this->get($this->url(['material_category' => 'FLANGE', 'mr_state' => 'SUBMITTED', 'po_state' => 'CANCELLED'])), [], []);
        $this->assertRows($this->get($this->url(['material_category' => 'PIPE', 'mr_state' => 'DRAFT', 'po_state' => 'CANCELLED'])), [$this->mrs[1]->id], $this->bound(fn () => PurchaseOrder::where('state', 'CANCELLED')->pluck('id')->all()));
    }

    public function test_percent_underscore_backslash_and_sql_like_quotes_are_literal_bound_search_text(): void
    {
        $mr = $this->create('mr', "Literal %_\\ token ' OR 1=1 --");
        $po = $this->create('po', "Literal %_\\ token ' OR 1=1 --");
        foreach (['%', '_', '\\', "' OR 1=1 --"] as $text) {
            $this->assertRows($this->get($this->url(['mr_q' => $text, 'po_q' => $text])), [$mr->id], [$po->id]);
        }
        $this->assertRows($this->get($this->url(['mr_q' => "' OR true --", 'po_q' => "' OR true --"])), [], []);
    }

    public function test_invalid_query_shapes_lengths_and_state_vocabularies_are_rejected(): void
    {
        foreach (['mr_q' => ['nested'], 'po_q' => str_repeat('a', 121), 'mr_state' => 'ISSUED', 'po_state' => 'APPROVED', 'material_category' => 'PIPING'] as $key => $value) {
            $this->getJson($this->url([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->getJson($this->url(['po_state' => ['DRAFT'], 'mr_state' => ['DRAFT']]))->assertUnprocessable()->assertJsonValidationErrors(['po_state', 'mr_state']);
    }

    public function test_search_preserves_competing_buyer_and_vendor_rls_without_write_delegation(): void
    {
        $this->actingAs($this->userByEmail('mousavi@sahand.example'));
        $this->assertRows($this->get($this->url(['mr_q' => 'Needle', 'po_q' => 'Needle', 'material_category' => 'PIPE'])), [], []);
        $this->postJson('/projects/PH-14/procurement/orders', ['po' => ['title_en' => 'No draft authority from search']])->assertForbidden();
        $vendor = app(TenantContext::class)->forIdentityLookup(fn () => User::create(['company_id' => $this->vendor, 'name' => 'Filter vendor', 'email' => 'filter@vendor.example', 'password' => 'test-only', 'is_active' => true]));
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($this->vendor)->update(['plan_id' => Plan::where('code', 'full')->firstOrFail()->id]));
        app(TenantContext::class)->runAs($this->vendor, fn () => DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'user_id' => $vendor->id, 'role_codes' => '["vendor"]', 'created_at' => now(), 'updated_at' => now()]), $this->project->id);
        $this->actingAs($vendor);
        $this->assertRows($this->get($this->url(['mr_q' => 'Needle', 'po_q' => 'Needle'])), [], [$this->pos[0]->id]);
        $this->actingAs($this->userByEmail('ahmadi@nigc.example'))->get($this->url(['mr_q' => 'Needle']))->assertNotFound();
    }

    public function test_actual_other_project_titles_cannot_escape_current_project_scope(): void
    {
        $other = Project::where('code', 'BB-2')->firstOrFail();
        [$mr, $po] = app(TenantContext::class)->runAs($this->writer->company_id, fn () => [
            MaterialRequisition::create(['project_id' => $other->id, 'company_id' => $this->writer->company_id, 'number' => 'BB2-FILTER-MR', 'sequence' => 9000, 'state' => 'DRAFT', 'discipline' => 'PIPING', 'title_en' => 'Other project', 'title_fa' => 'Foreign-marker-only', 'raised_by' => $this->writer->id, 'raised_at' => now()]),
            PurchaseOrder::forceCreate(['project_id' => $other->id, 'company_id' => $this->writer->company_id, 'vendor_company_id' => $this->vendor, 'number' => 'BB2-FILTER-PO', 'sequence' => 9000, 'state' => 'DRAFT', 'title_en' => 'Other project', 'title_fa' => 'Foreign-marker-only', 'discipline' => 'PIPING']),
        ], $other->id);
        $filters = ['mr_q' => 'Foreign-marker-only', 'po_q' => 'Foreign-marker-only', 'material_category' => 'UNCLASSIFIED'];
        $this->assertRows($this->get($this->url($filters)), [], []);
        $this->assertRows($this->get($this->url($filters, 'BB-2')), [$mr->id], [$po->id]);
        $this->assertRows($this->get($this->url(['mr_q' => '0', 'po_q' => '0'], 'BB-2')), [], []);
    }

    public function test_filtered_gets_leave_archived_documents_and_evidence_unchanged(): void
    {
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Filter read-only archive fixture');
        $snapshot = fn () => $this->bound(function (): array {
            $result = [];
            foreach (['material_requisitions', 'material_requisition_items', 'purchase_orders', 'purchase_order_items', 'po_pricing_revisions', 'po_execution_decisions', 'po_mr_allocations'] as $table) {
                $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }

            return $result;
        });
        $before = $snapshot();
        $page = $this->get($this->url(['mr_q' => 'Needle', 'po_q' => 'Needle', 'mr_state' => 'DRAFT', 'po_state' => 'DRAFT']));
        $this->assertRows($page, [$this->mrs[0]->id], [$this->pos[0]->id]);
        $page->assertSee('counts reflect these results')->assertSee('The inspection result set is not narrowed by MR/PO filters.');
        $this->assertSame($before, $snapshot());
    }

    public function test_filter_controls_are_escaped_bilingual_and_reset_keeps_category_and_language(): void
    {
        $text = '<script>alert(1)</script>';
        $page = $this->get($this->url(['mr_q' => $text, 'po_q' => $text, 'mr_state' => 'DRAFT', 'po_state' => 'DRAFT', 'material_category' => 'PIPE']));
        $page->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee($text, false)->assertSee('No visible requisitions match these filters.');
        $this->assertSame($text, $page->viewData('registerFilters')['mr_q']);
        $fa = $this->get(str_replace('lang=en', 'lang=fa', $this->url(['material_category' => 'PIPE', 'mr_state' => 'DRAFT', 'po_state' => 'DRAFT', 'mr_q' => 'لوله ویژه'])));
        $fa->assertOk()->assertSee('فیلتر درخواست خرید')->assertSee('همهٔ وضعیت‌های ثبت‌شده')->assertSee('پیش‌نویس');
        $fa->assertSee(route('procurement', ['projectCode' => 'PH-14', 'lang' => 'fa', 'material_category' => 'PIPE']));
        $this->assertSame(['mr_q' => 'لوله ویژه', 'po_q' => '', 'mr_state' => 'DRAFT', 'po_state' => 'DRAFT'], $fa->viewData('registerFilters'));
    }
}
