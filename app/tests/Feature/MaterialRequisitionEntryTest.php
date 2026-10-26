<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\PmsAccessService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaterialRequisitionEntryTest extends TestCase
{
    private Project $project;

    private User $writer;

    private User $owner;

    private string $createUrl = '/projects/PH-14/procurement/requisitions?lang=en';

    private string $accessUrl = '/projects/PH-14/procurement/author-access?lang=en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
    }

    private function bound(callable $fn, ?User $actor = null): mixed
    {
        return app(TenantContext::class)->runAs(($actor ?? $this->writer)->company_id, $fn, $this->project->id);
    }

    private function policy(array $changes = []): array
    {
        return array_replace(['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Explicit synthetic MR authorization'], $changes);
    }

    private function nominate(array $changes = []): void
    {
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, $this->policy($changes));
    }

    private function payload(array $changes = []): array
    {
        return array_replace([
            'title_en' => 'Valve request', 'title_fa' => 'درخواست شیرآلات', 'discipline' => 'PIPING', 'material_category' => 'VALVE',
            'lines' => [['item_code' => 'V-1', 'description_en' => 'Gate valve', 'description_fa' => 'شیر دروازه‌ای', 'quantity' => '0002.10', 'unit' => 'EA', 'specification' => 'Project Spec §2']],
        ], $changes);
    }

    private function assertNoDrafts(): void
    {
        $this->bound(function (): void {
            $this->assertSame(0, MaterialRequisition::count());
            $this->assertSame(0, MaterialRequisitionItem::count());
        }, $this->owner);
    }

    public function test_guests_cannot_create_or_configure(): void
    {
        $this->post($this->createUrl, ['mr' => $this->payload()])->assertRedirect('/login');
        $this->post($this->accessUrl, ['access' => $this->policy()])->assertRedirect('/login');
        $this->assertNoDrafts();
    }

    public function test_no_default_author_or_inherited_pms_nomination(): void
    {
        app(PmsAccessService::class)->configure($this->project, $this->owner, $this->policy());
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload()])->assertForbidden();
        $this->actingAs($this->owner)->postJson($this->createUrl, ['mr' => $this->payload()])->assertForbidden();
        $this->get('/projects/PH-14/procurement?lang=fa')->assertOk()->assertSee('mr-access-form')->assertSee('data-mr-access="unconfigured"', false)->assertDontSee('id="mr-create-form"', false);
        $this->assertNoDrafts();
    }

    public function test_owner_configures_current_members_and_history_is_append_only(): void
    {
        $this->actingAs($this->owner)->post($this->accessUrl, ['access' => $this->policy()])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->writer)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('mr-create-form')->assertDontSee('id="mr-access-form"', false);
        $this->actingAs($this->owner)->postJson($this->accessUrl, ['access' => $this->policy()])->assertUnprocessable()->assertJsonValidationErrors('access');
        $this->nominate(['version' => 1, 'suspend' => true]);
        $this->bound(function (): void {
            $this->assertSame(2, DB::table('mr_author_policies')->count());
            $this->assertSame(0, DB::table('mr_author_policies')->update(['reason' => 'rewrite']));
            $this->assertSame(0, DB::table('mr_author_policies')->delete());
        }, $this->owner);
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload()])->assertForbidden();
        $this->assertNoDrafts();
    }

    public function test_configuration_requires_owner_company_and_live_configure_capability(): void
    {
        $this->actingAs($this->writer)->postJson($this->accessUrl, ['access' => $this->policy()])->assertForbidden();
        $this->bound(fn () => ProjectMembership::where('user_id', $this->owner->id)->where('project_id', $this->project->id)->update(['role_codes' => ['site_engineer']]), $this->owner);
        $this->actingAs($this->owner)->postJson($this->accessUrl, ['access' => $this->policy()])->assertForbidden();
        $this->bound(fn () => $this->assertSame(0, DB::table('mr_author_policies')->count()), $this->owner);
    }

    public function test_invalid_disabled_foreign_and_empty_nominees_are_rejected(): void
    {
        $disabled = $this->userByEmail('former@pars.example');
        $foreign = $this->userByEmail('ahmadi@nigc.example');
        $consultant = $this->userByEmail('tehrani@pmc.example');
        foreach ([[], ['user:'.$disabled->id], ['user:'.$foreign->id], ['user:'.$consultant->id], ['role:'.$this->writer->company_id.':missing'], ['user:'.$this->writer->id, 'user:'.$this->writer->id]] as $selectors) {
            $this->actingAs($this->owner)->postJson($this->accessUrl, ['access' => $this->policy(['selectors' => $selectors])])->assertUnprocessable();
        }
        $this->actingAs($this->owner)->postJson($this->accessUrl, ['access' => $this->policy(['reason' => ' '])])->assertUnprocessable();
        $this->bound(fn () => $this->assertSame(0, DB::table('mr_author_policies')->count()), $this->owner);
    }

    public function test_multiline_draft_preserves_source_and_own_company_policy_without_approval(): void
    {
        $this->nominate();
        $data = $this->payload();
        $data['lines'][] = array_fill_keys(['item_code', 'description_en', 'description_fa', 'quantity', 'unit', 'specification', 'subsystem_id'], '');
        $data['lines'][] = array_replace($data['lines'][0], ['item_code' => 'V-2', 'quantity' => '999999999999999.999']);
        $data['lines'][0]['subsystem_id'] = $this->bound(fn () => Subsystem::where('project_id', $this->project->id)->value('id'));
        $this->actingAs($this->writer)->post($this->createUrl, ['mr' => $data])->assertRedirect()->assertSessionHasNoErrors();
        $this->bound(function () use ($data): void {
            $mr = MaterialRequisition::with('items')->sole();
            $this->assertSame('DRAFT', $mr->state);
            $this->assertSame('PH-14-MR-0001', $mr->number);
            $this->assertSame($this->writer->id, $mr->raised_by);
            $this->assertSame($this->writer->company_id, $mr->company_id);
            $this->assertSame($this->project->id, $mr->project_id);
            $this->assertSame(DB::table('mr_author_policies')->value('id'), $mr->author_policy_id);
            $this->assertNull($mr->technical_approved_by);
            $this->assertNull($mr->commercial_approved_by);
            $items = $mr->items->sortBy('line_no')->values();
            $this->assertCount(2, $items);
            $this->assertSame('0002.10', $items[0]->source_quantity);
            $this->assertSame('2.100', $items[0]->quantity);
            $this->assertSame('999999999999999.999', $items[1]->quantity);
            $this->assertSame($data['lines'][0]['subsystem_id'], $items[0]->subsystem_id);
            $this->assertSame('EA', $items[0]->unit);
            $this->assertSame(0, DB::table('purchase_orders')->count());
        });
        foreach (['en', 'fa'] as $lang) {
            $this->get('/projects/PH-14/procurement?lang='.$lang)->assertOk()->assertSee('0002.10')->assertSee('999999999999999.999')->assertSee('PH-14-MR-0001');
        }
    }

    public function test_role_nomination_is_company_scoped_and_revokes_with_membership(): void
    {
        $this->nominate(['selectors' => ['role:'.$this->writer->company_id.':project_manager']]);
        $this->assertTrue(app(MaterialRequisitionAccessService::class)->allows($this->project, $this->writer));
        $this->assertFalse(app(MaterialRequisitionAccessService::class)->allows($this->project, $this->owner));
        $this->bound(fn () => ProjectMembership::where('user_id', $this->writer->id)->where('project_id', $this->project->id)->update(['role_codes' => ['site_engineer']]));
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload()])->assertForbidden();
        $this->assertNoDrafts();
    }

    public function test_discipline_scope_and_disabled_user_block_live_nominee(): void
    {
        $this->nominate();
        $this->bound(fn () => ProjectMembership::where('user_id', $this->writer->id)->where('project_id', $this->project->id)->update(['discipline_scope' => ['ELECTRICAL']]));
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload()])->assertForbidden();
        $this->bound(fn () => User::whereKey($this->writer->id)->update(['is_active' => false]));
        $this->assertFalse(app(MaterialRequisitionAccessService::class)->allows($this->project, $this->writer));
        $this->assertNoDrafts();
    }

    public function test_entitlement_and_pack_are_rechecked_despite_nomination(): void
    {
        $this->nominate();
        $this->bound(fn () => $this->writer->company->plan->update(['modules' => ['core']]));
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload()])->assertForbidden();
        $this->bound(fn () => $this->writer->company->plan->update(['modules' => ['*'], 'standards_packs' => ['unrelated']]));
        $this->postJson($this->createUrl, ['mr' => $this->payload()])->assertForbidden();
        $this->assertNoDrafts();
    }

    public function test_quantity_boundaries_reject_rounding_exponents_zero_and_non_strings(): void
    {
        $this->nominate();
        foreach (['0', '0.000', '-1', '1.0001', '1000000000000000', '1e2', '1/2', '.5', '1,000', ' 1', '۱', [], 1.2] as $quantity) {
            $data = $this->payload();
            $data['lines'][0]['quantity'] = $quantity;
            $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $data])->assertUnprocessable()->assertJsonValidationErrors('lines.0.quantity');
        }
        $this->assertNoDrafts();
        $data = $this->payload();
        $data['lines'][0]['quantity'] = '0.001';
        $this->post($this->createUrl, ['mr' => $data])->assertRedirect()->assertSessionHasNoErrors();
        $this->bound(fn () => $this->assertSame('0.001', MaterialRequisitionItem::sole()->quantity));
    }

    public function test_bounded_fields_bad_shapes_and_empty_lines_are_atomic_validation_errors(): void
    {
        $this->nominate();
        foreach ([['title_en' => str_repeat('x', 256)], ['title_fa' => ' '], ['discipline' => str_repeat('A', 33)], ['lines' => []], ['lines' => 'bad'], ['lines' => array_fill(0, 21, $this->payload()['lines'][0])], ['lines' => ['bad']], ['lines' => [[]]]] as $change) {
            $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload($change)])->assertUnprocessable();
        }
        foreach (['item_code' => 65, 'unit' => 17, 'description_en' => 4001, 'description_fa' => 4001, 'specification' => 256] as $field => $length) {
            $data = $this->payload();
            $data['lines'][0][$field] = str_repeat('x', $length);
            $this->postJson($this->createUrl, ['mr' => $data])->assertUnprocessable()->assertJsonValidationErrors('lines.0.'.$field);
        }
        $this->assertNoDrafts();
    }

    public function test_authority_fields_cannot_be_spoofed(): void
    {
        $this->nominate();
        foreach (['company_id' => $this->owner->company_id, 'project_id' => $this->project->id, 'raised_by' => $this->owner->id, 'state' => 'APPROVED', 'sequence' => 9, 'number' => 'FAKE', 'author_policy_id' => (string) Str::uuid()] as $key => $value) {
            $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload([$key => $value])])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->assertNoDrafts();
    }

    public function test_other_project_subsystem_and_nonmember_project_are_rejected(): void
    {
        $this->nominate();
        $foreignProject = Project::where('code', 'BB-2')->firstOrFail();
        $foreignSubsystem = app(TenantContext::class)->runAs($this->writer->company_id, function () use ($foreignProject): string {
            $base = ['project_id' => $foreignProject->id, 'company_id' => $this->writer->company_id,
                'code' => 'FOREIGN-MR', 'name_en' => 'Synthetic foreign breakdown', 'name_fa' => 'آزمون پروژه دیگر'];
            $facility = Facility::create($base + ['kind' => 'PLANT']);

            return Subsystem::create($base + ['facility_id' => $facility->id, 'discipline' => 'PIPING'])->id;
        }, $foreignProject->id);
        $this->assertNotNull($foreignSubsystem);
        $data = $this->payload();
        $data['lines'][0]['subsystem_id'] = $foreignSubsystem;
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $data])->assertUnprocessable()->assertJsonValidationErrors('lines.0.subsystem_id');
        $this->actingAs($this->userByEmail('ahmadi@nigc.example'))->postJson($this->createUrl, ['mr' => $this->payload()])->assertNotFound();
        $this->assertNoDrafts();
    }

    public function test_archived_project_remains_readable_but_cannot_create_or_configure(): void
    {
        $this->nominate();
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic closeout'), $this->owner);
        $this->actingAs($this->writer)->get('/projects/PH-14/procurement?lang=fa')->assertOk()->assertDontSee('id="mr-create-form"', false);
        $this->postJson($this->createUrl, ['mr' => $this->payload()])->assertUnprocessable();
        $this->actingAs($this->owner)->postJson($this->accessUrl, ['access' => $this->policy(['version' => 1])])->assertForbidden();
        $this->assertNoDrafts();
    }

    public function test_late_item_failure_rolls_back_header_items_and_number(): void
    {
        $this->nominate();
        $data = $this->payload();
        $data['lines'][] = array_replace($data['lines'][0], ['item_code' => 'ROLLBACK']);
        DB::statement("ALTER TABLE material_requisition_items ADD CONSTRAINT synthetic_failure CHECK (item_code <> 'ROLLBACK')");
        try {
            app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, $data, 'en');
            $this->fail('Expected late database refusal');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic_failure', $e->getMessage());
        }
        $this->assertNoDrafts();
        $this->assertSame(0, (int) $this->project->fresh()->material_requisition_sequence);
    }

    public function test_number_allocation_is_monotone_across_owner_and_hidden_epc_rows(): void
    {
        $this->nominate(['selectors' => ['user:'.$this->writer->id, 'user:'.$this->owner->id]]);
        $entry = app(MaterialRequisitionEntryService::class);
        $a = $entry->create($this->project, $this->writer, $this->payload());
        $b = $entry->create($this->project, $this->owner, $this->payload());
        $c = $entry->create($this->project, $this->writer, $this->payload());
        $this->assertSame('PH-14-MR-0001', $a->number);
        $this->assertSame('PH-14-MR-0002', $b->number);
        $this->assertSame('PH-14-MR-0003', $c->number);
        $this->bound(fn () => $this->assertNull(MaterialRequisition::find($b->id)));
        $this->bound(fn () => MaterialRequisition::whereKey($c->id)->delete());
        $d = $entry->create($this->project, $this->writer, $this->payload());
        $this->assertSame('PH-14-MR-0004', $d->number);
    }

    public function test_policy_attribution_cannot_be_erased_after_creation(): void
    {
        $this->nominate();
        $mr = app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, $this->payload());
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('MR_ENTRY_BASIS_IMMUTABLE');
        $this->bound(fn () => DB::table('material_requisitions')->where('id', $mr->id)->update(['author_policy_id' => null]));
    }

    public function test_html_and_invalid_old_input_are_safe_and_preserved(): void
    {
        $this->nominate();
        $data = $this->payload(['title_en' => '<script>alert("mr")</script>']);
        $data['lines'][0]['quantity'] = '1.0001';
        $this->actingAs($this->writer)->from('/projects/PH-14/procurement?lang=en')->post($this->createUrl, ['mr' => $data])->assertRedirect()->assertSessionHasErrors('lines.0.quantity');
        $this->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('1.0001')->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("mr")</script>', false);
        $this->withSession(['_old_input' => ['mr' => ['title_en' => ['bad'], 'lines' => 'malformed'], 'access' => ['selectors' => 'invalid', 'version' => ['bad']]]])->get('/projects/PH-14/procurement?lang=fa')->assertOk();
    }

    public function test_twenty_lines_are_accepted_and_unknown_line_fields_are_rejected(): void
    {
        $this->nominate();
        $data = $this->payload();
        $data['lines'][0]['company_id'] = $this->owner->company_id;
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $data])->assertUnprocessable()->assertJsonValidationErrors('lines.0');
        $this->assertNoDrafts();
        $this->post($this->createUrl, ['mr' => $this->payload(['lines' => array_fill(0, 20, $this->payload()['lines'][0])])])->assertRedirect()->assertSessionHasNoErrors();
        $this->bound(fn () => $this->assertSame(20, MaterialRequisitionItem::count()));
    }

    public function test_persisted_sources_and_policy_reasons_are_html_escaped(): void
    {
        $text = '<script>alert("source")</script>';
        $this->nominate(['reason' => $text]);
        $data = $this->payload(['title_en' => $text]);
        $data['lines'][0]['description_en'] = $text;
        $data['lines'][0]['specification'] = $text;
        app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, $data);
        $this->actingAs($this->writer)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee($text, false);
    }

    public function test_person_nomination_does_not_survive_project_membership_removal(): void
    {
        $this->nominate();
        $this->bound(fn () => ProjectMembership::where('user_id', $this->writer->id)->where('project_id', $this->project->id)->delete());
        $this->actingAs($this->writer)->postJson($this->createUrl, ['mr' => $this->payload()])->assertNotFound();
        $this->get('/projects/PH-14/procurement?lang=en')->assertNotFound();
        $this->assertNoDrafts();
    }

    public function test_policy_history_is_not_visible_in_another_project_or_without_scope(): void
    {
        $this->nominate();
        $other = Project::where('code', 'BB-2')->firstOrFail();
        app(TenantContext::class)->runAs($this->writer->company_id, fn () => $this->assertSame(0, DB::table('mr_author_policies')->count()), $other->id);
        app(TenantContext::class)->runAs($this->writer->company_id, fn () => $this->assertSame(0, DB::table('mr_author_policies')->count()));
        $this->bound(fn () => $this->assertSame(1, DB::table('mr_author_policies')->count()));
    }

    public function test_sql_backstops_refuse_false_source_quantity_and_forged_author_basis(): void
    {
        $this->nominate();
        $mr = app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, $this->payload());
        try {
            $this->bound(fn () => DB::table('material_requisition_items')->where('requisition_id', $mr->id)->update(['source_quantity' => '999']));
            $this->fail('Expected source quantity constraint');
        } catch (QueryException $e) {
            $this->assertStringContainsString('mr_source_quantity_matches', $e->getMessage());
        }
        $this->nominate(['version' => 1, 'suspend' => true]);
        try {
            $this->bound(fn () => DB::table('material_requisitions')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $this->writer->company_id, 'raised_by' => $this->writer->id,
                'raised_at' => now(), 'number' => 'PH-14-MR-0099', 'sequence' => 99, 'state' => 'DRAFT', 'title_en' => 'Forged', 'title_fa' => 'آزمون',
                'discipline' => 'PIPING', 'material_category' => 'VALVE', 'author_policy_id' => $mr->author_policy_id,
            ]));
            $this->fail('Expected current nomination guard');
        } catch (QueryException $e) {
            $this->assertStringContainsString('MR_AUTHOR_NOT_NOMINATED', $e->getMessage());
        }
        $this->bound(function () use ($mr): void {
            $this->assertSame(1, MaterialRequisition::count());
            $this->assertSame($mr->author_policy_id, $mr->fresh()->author_policy_id);
            $this->assertSame('0002.10', MaterialRequisitionItem::sole()->source_quantity);
        });
    }

    public function test_authoring_nomination_does_not_bypass_self_approval_rule(): void
    {
        $this->nominate();
        $mr = app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, $this->payload());
        $this->actingAs($this->writer)->post('/projects/PH-14/procurement/requisition?lang=en', ['requisition_id' => $mr->id, 'action' => 'submit'])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/projects/PH-14/procurement/requisition?lang=en', ['requisition_id' => $mr->id, 'action' => 'technical'])->assertUnprocessable();
        $this->bound(fn () => $this->assertSame('SUBMITTED', $mr->fresh()->state));
    }
}
