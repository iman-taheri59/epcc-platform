<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialInspection;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\InspectionAccessService;
use App\Services\InspectionEntryService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InspectionEntryTest extends TestCase
{
    private $writer;

    private $owner;

    private $buyer;

    private Project $project;

    private PurchaseOrder $order;

    private string $config = '/projects/PH-14/procurement/inspection-author-access?lang=en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->writer = $this->userByEmail('tehrani@pmc.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $poPolicy = $this->policy();
        $poPolicy['selectors'] = ['user:'.$this->buyer->id];
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $poPolicy);
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        $this->order = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, [
            'title_en' => 'Inspection observation source', 'title_fa' => 'منبع مشاهدهٔ بازرسی', 'discipline' => 'PIPING', 'material_category' => 'PIPE',
            'vendor_company_id' => $vendor, 'lines' => [['item_code' => 'OBS-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '2.100']],
        ]);
    }

    private function bound(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $fn, $this->project->id);
    }

    private function policy(int $version = 0): array
    {
        return ['version' => $version, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Independent inspection fixture'];
    }

    private function nominate(?array $policy = null): void
    {
        app(InspectionAccessService::class)->configure($this->project, $this->owner, $policy ?? $this->policy());
    }

    private function detail(): string
    {
        return '/projects/PH-14/procurement/orders/'.$this->order->id.'?lang=en';
    }

    private function endpoint(): string
    {
        return '/projects/PH-14/procurement/orders/'.$this->order->id.'/inspections?lang=en';
    }

    private function input(): array
    {
        return ['purchase_order_id' => $this->order->id, 'result' => 'FAIL', 'stage' => 'WORKS', 'inspection_date' => '2026-10-18',
            'findings_en' => '<script>observation()</script>', 'findings_fa' => 'یافتهٔ اظهارشده'];
    }

    private function records(): array
    {
        return $this->bound(fn () => DB::table('material_inspections')->orderBy('sequence')->get()->all());
    }

    public function test_no_default_or_inherited_mr_po_finance_or_owner_authority(): void
    {
        $this->actingAs($this->writer)->get($this->detail())->assertOk()->assertDontSee('inspection-create-form');
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        $this->assertCount(0, $this->records());
    }

    public function test_owner_configures_versioned_policy_and_attributed_observation_without_commercial_stock_changes(): void
    {
        $this->actingAs($this->owner)->postJson($this->config, ['inspection_access' => $this->policy()])->assertRedirect()->assertSessionHasNoErrors();
        $snapshot = fn () => $this->bound(function (): array {
            $rows = [];
            foreach (['material_requisitions', 'material_requisition_items', 'purchase_orders', 'purchase_order_items', 'material_lots', 'material_movements', 'po_execution_decisions', 'po_pricing_revisions', 'po_mr_allocations'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $rows;
        });
        $before = $snapshot();
        $this->actingAs($this->writer)->get($this->detail())->assertOk()->assertSee('inspection-create-form');
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertRedirect($this->detail())->assertSessionHasNoErrors();
        $records = $this->records();
        $this->assertCount(1, $records);
        $row = $records[0];
        $this->assertSame('PH-14-IRN-0001', $row->number);
        $this->assertSame($this->writer->company_id, $row->company_id);
        $this->assertNotSame($this->buyer->company_id, $row->company_id);
        $this->assertSame($this->writer->id, $row->inspected_by);
        $this->assertSame($this->writer->name, $row->recorded_actor_name);
        $this->assertNotNull($row->entry_policy_id);
        $this->assertSame('FAIL', $row->result);
        $this->assertStringStartsWith('2026-10-18 00:00:00', $row->inspected_at);
        $this->assertSame($before, $snapshot());
        $this->get($this->detail())->assertOk()->assertSee('PH-14-IRN-0001')->assertSee('&lt;script&gt;observation()&lt;/script&gt;', false)->assertDontSee('<script>observation()</script>', false);
        $this->get(str_replace('lang=en', 'lang=fa', $this->detail()))->assertOk()->assertSee('یافتهٔ اظهارشده')->assertSee('ثبت بازرسی');
        $this->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee($row->number);
    }

    public function test_only_owner_configures_and_stale_unknown_or_forged_selections_are_refused(): void
    {
        $this->actingAs($this->writer)->postJson($this->config, ['inspection_access' => $this->policy()])->assertForbidden();
        $this->nominate();
        $this->actingAs($this->owner)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('inspection-access-form')->assertSee('Inspection recorder access');
        $this->get('/projects/PH-14/procurement?lang=fa')->assertOk()->assertSee('اختیار ثبت‌کنندگان بازرسی');
        $this->postJson($this->config, ['inspection_access' => $this->policy()])->assertUnprocessable();
        $bad = $this->policy(1);
        $bad['selectors'] = ['user:'.Str::uuid()];
        $this->postJson($this->config, ['inspection_access' => $bad])->assertUnprocessable();
        $bad = $this->policy(1) + ['company_id' => $this->writer->company_id];
        $this->postJson($this->config, ['inspection_access' => $bad])->assertUnprocessable();
        $this->bound(function (): void {
            $this->assertSame(1, DB::table('inspection_author_policies')->count());
            $this->assertSame(0, DB::table('inspection_author_policies')->update(['reason' => 'rewrite']));
            $this->assertSame(0, DB::table('inspection_author_policies')->delete());
        });
    }

    public function test_nomination_never_unlocks_hidden_po_or_other_project(): void
    {
        $other = $this->hiddenInspector();
        $policy = $this->policy();
        $policy['selectors'] = ['user:'.$other->id];
        $this->nominate($policy);
        $this->actingAs($other)->get($this->detail())->assertNotFound();
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertNotFound();
        $this->actingAs($this->buyer)->postJson(str_replace('PH-14', 'BB-2', $this->endpoint()), ['inspection' => $this->input()])->assertNotFound();
        $this->assertCount(0, $this->records());
    }

    public function test_contractor_and_vendor_are_not_eligible_even_with_independent_nomination_request(): void
    {
        $vendor = app(TenantContext::class)->forIdentityLookup(fn () => User::create([
            'company_id' => $this->order->vendor_company_id, 'name' => 'Vendor self inspection', 'email' => 'vendor-inspection@example.test', 'password' => 'test-only', 'is_active' => true,
        ]));
        $full = Plan::where('code', 'full')->firstOrFail()->id;
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($vendor->company_id)->update(['plan_id' => $full]));
        app(TenantContext::class)->runAs($vendor->company_id, fn () => DB::table('project_memberships')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'user_id' => $vendor->id, 'role_codes' => '["vendor"]', 'created_at' => now(), 'updated_at' => now(),
        ]), $this->project->id);
        foreach ([$this->buyer, $vendor] as $actor) {
            $policy = $this->policy();
            $policy['selectors'] = ['user:'.$actor->id];
            $this->actingAs($this->owner)->postJson($this->config, ['inspection_access' => $policy])->assertUnprocessable();
            $this->actingAs($actor)->get($this->detail())->assertOk()->assertDontSee('inspection-create-form');
            $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        }
        $this->assertCount(0, $this->records());
    }

    private function hiddenInspector(): User
    {
        $company = Company::create(['code' => 'TPI-OBS', 'name_en' => 'Inspection company', 'name_fa' => 'شرکت بازرس', 'tenant_type' => 'TPI', 'plan_id' => Plan::where('code', 'full')->firstOrFail()->id]);
        app(TenantContext::class)->runAs($this->owner->company_id, fn () => DB::table('project_company')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $company->id, 'role' => 'TPI', 'created_at' => now(), 'updated_at' => now(),
        ]), $this->project->id);
        $person = app(TenantContext::class)->forIdentityLookup(fn () => User::create([
            'company_id' => $company->id, 'name' => 'TPI recorder', 'email' => 'tpi@example.test', 'password' => 'test-only', 'is_active' => true,
        ]));
        app(TenantContext::class)->runAs($company->id, fn () => DB::table('project_memberships')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'user_id' => $person->id, 'role_codes' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]), $this->project->id);

        return $person;
    }

    public function test_company_scoped_role_grant_and_fresh_role_discipline_plan_and_active_checks(): void
    {
        $membership = $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->firstOrFail());
        $policy = $this->policy();
        $policy['selectors'] = ['role:'.$this->writer->company_id.':'.$membership->role_codes[0]];
        $this->nominate($policy);
        $this->assertTrue(app(InspectionAccessService::class)->allows($this->project, $this->writer, 'PIPING'));
        $this->bound(fn () => ProjectMembership::whereKey($membership->id)->update(['role_codes' => []]));
        $this->actingAs($this->writer)->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        $this->bound(fn () => ProjectMembership::whereKey($membership->id)->update(['role_codes' => $membership->role_codes, 'discipline_scope' => ['CIVIL']]));
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        $this->bound(fn () => ProjectMembership::whereKey($membership->id)->update(['discipline_scope' => null]));
        $starter = Plan::where('code', 'starter')->firstOrFail()->id;
        $full = Plan::where('code', 'full')->firstOrFail()->id;
        $this->bound(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        $this->bound(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        $this->bound(fn () => DB::table('users')->where('id', $this->writer->id)->update(['is_active' => false]));
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        $this->bound(fn () => DB::table('users')->where('id', $this->writer->id)->update(['is_active' => true]));
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_suspension_and_archive_after_display_refuse_new_entry(): void
    {
        $this->nominate();
        $this->actingAs($this->writer)->get($this->detail())->assertOk()->assertSee('inspection-create-form');
        $policy = $this->policy(1);
        $policy['suspend'] = true;
        $this->nominate($policy);
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertForbidden();
        $this->nominate($this->policy(2));
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only after inspection form');
        $this->get($this->detail())->assertOk()->assertDontSee('inspection-create-form');
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertUnprocessable()->assertJsonValidationErrors('project');
        $this->assertCount(0, $this->records());
    }

    public function test_invalid_payloads_and_identity_source_spoofing_create_no_records(): void
    {
        $this->nominate();
        foreach (['result' => 'ACCEPTED', 'stage' => 'UNKNOWN', 'inspection_date' => '2026-02-30', 'findings_fa' => ['nested'], 'purchase_order_id' => (string) Str::uuid(), 'inspected_by' => $this->owner->id, 'entry_policy_id' => (string) Str::uuid()] as $key => $value) {
            $input = $this->input();
            $input[$key] = $value;
            $this->actingAs($this->writer)->postJson($this->endpoint(), ['inspection' => $input])->assertUnprocessable();
        }
        $this->assertCount(0, $this->records());
    }

    public function test_fail_and_conditional_require_findings_but_pass_does_not_and_none_updates_stock(): void
    {
        $this->nominate();
        $data = $this->input();
        $data['findings_en'] = $data['findings_fa'] = '';
        foreach (['FAIL', 'CONDITIONAL'] as $result) {
            $data['result'] = $result;
            $this->actingAs($this->writer)->postJson($this->endpoint(), ['inspection' => $data])->assertUnprocessable()->assertJsonValidationErrors('inspection.findings_en');
        }
        $data['result'] = 'PASS';
        $this->postJson($this->endpoint(), ['inspection' => $data])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(1, $this->records());
        $this->assertSame('PASS', $this->records()[0]->result);
    }

    public function test_validation_returns_to_same_po_with_safe_old_input_and_no_implicit_pass(): void
    {
        $this->nominate();
        $input = $this->input();
        $input['inspection_date'] = '';
        $this->actingAs($this->writer)->from($this->detail())->post($this->endpoint(), ['inspection' => $input])->assertSessionHasErrors();
        $this->get($this->detail())->assertOk()->assertSee('value="FAIL" selected', false)->assertSee('&lt;script&gt;observation()&lt;/script&gt;', false)->assertDontSee('<script>observation()</script>', false);
        $this->assertCount(0, $this->records());
    }

    public function test_controlled_records_are_immutable_and_no_legacy_policy_backfill(): void
    {
        $this->nominate();
        $record = app(InspectionEntryService::class)->create($this->project, $this->writer, $this->order->id, $this->input());
        foreach (['update', 'delete'] as $action) {
            try {
                $this->bound(fn () => DB::transaction(fn () => $action === 'update' ? DB::table('material_inspections')->where('id', $record->id)->update(['result' => 'PASS']) : DB::table('material_inspections')->where('id', $record->id)->delete()));
                $this->fail('Expected immutable inspection');
            } catch (QueryException $e) {
                $this->assertStringContainsString('INSPECTION_ENTRY_IMMUTABLE', $e->getMessage());
            }
        }
        $legacy = $this->legacy();
        try {
            $this->bound(fn () => DB::transaction(fn () => DB::table('material_inspections')->where('id', $legacy->id)->update(['entry_policy_id' => $record->entry_policy_id])));
            $this->fail('Expected no attribution backfill');
        } catch (QueryException $e) {
            $this->assertStringContainsString('INSPECTION_NO_LEGACY_BACKFILL', $e->getMessage());
        }
        $this->assertSame('FAIL', $this->records()[0]->result);
    }

    public function test_sql_provenance_refuses_hidden_po_and_stale_policy_even_for_known_ids(): void
    {
        $other = $this->hiddenInspector();
        $policy = $this->policy();
        $policy['selectors'][] = 'user:'.$other->id;
        $this->nominate($policy);
        $id = $this->bound(fn () => DB::table('inspection_author_policies')->value('id'));
        foreach (['hidden', 'stale'] as $case) {
            $actor = $case === 'hidden' ? $other : $this->writer;
            if ($case === 'stale') {
                $suspend = $this->policy(1);
                $suspend['suspend'] = true;
                $this->nominate($suspend);
            }
            try {
                app(TenantContext::class)->runAs($actor->company_id, fn () => DB::transaction(fn () => DB::table('material_inspections')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $actor->company_id,
                    'purchase_order_id' => $this->order->id, 'number' => 'SQL-FORGED-'.$case, 'sequence' => 8000, 'result' => 'PASS', 'stage' => 'WORKS',
                    'inspected_by' => $actor->id, 'inspected_at' => now(), 'entry_policy_id' => $id,
                ])), $this->project->id);
                $this->fail('Expected provenance refusal');
            } catch (QueryException $e) {
                $this->assertStringContainsString($case === 'hidden' ? 'INSPECTION_PO_NOT_VISIBLE' : 'INSPECTION_AUTHOR_NOT_NOMINATED', $e->getMessage());
            }
        }
        $this->assertCount(0, $this->records());
    }

    private function legacy(): MaterialInspection
    {
        return $this->bound(fn () => MaterialInspection::create(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
            'purchase_order_id' => $this->order->id, 'number' => 'LEGACY-INSP', 'sequence' => 9000, 'result' => 'PASS', 'stage' => 'WORKS', 'inspected_by' => $this->writer->id, 'inspected_at' => now()]));
    }

    public function test_numbering_uses_visible_legacy_max_and_late_failure_rolls_back(): void
    {
        $this->nominate();
        $this->legacy();
        $this->bound(fn () => DB::unprepared("CREATE FUNCTION inspection_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'INSPECTION_TEST_LATE'; END $$; CREATE TRIGGER zz_inspection_test_fail AFTER INSERT ON material_inspections FOR EACH ROW EXECUTE FUNCTION inspection_test_fail();"));
        $this->actingAs($this->writer)->postJson($this->endpoint(), ['inspection' => $this->input()])->assertUnprocessable();
        $this->assertCount(1, $this->records());
        $this->bound(fn () => DB::unprepared('DROP TRIGGER zz_inspection_test_fail ON material_inspections; DROP FUNCTION inspection_test_fail();'));
        $this->postJson($this->endpoint(), ['inspection' => $this->input()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('PH-14-IRN-9001', $this->records()[1]->number);
    }

    public function test_forward_migration_preserves_populated_archived_legacy_records_without_nominees(): void
    {
        $legacy = $this->legacy();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Populated inspection upgrade');
        $before = $this->bound(fn () => (array) DB::table('material_inspections')->where('id', $legacy->id)->first());
        DB::unprepared('DROP TRIGGER inspection_entry_guard ON material_inspections; DROP FUNCTION epcc_guard_inspection_entry();');
        Schema::table('material_inspections', function ($t): void {
            $t->dropForeign('inspection_entry_policy_fk');
            $t->dropColumn(['entry_policy_id', 'recorded_actor_name']);
        });
        Schema::drop('inspection_author_policies');
        $migration = require database_path('migrations/2026_10_19_190000_add_independent_inspection_entry.php');
        $migration->up();
        $after = $this->bound(fn () => (array) DB::table('material_inspections')->where('id', $legacy->id)->first());
        $this->assertEquals($before, $after);
        $this->assertNull($after['entry_policy_id']);
        $this->assertSame(0, $this->bound(fn () => DB::table('inspection_author_policies')->count()));
    }
}
