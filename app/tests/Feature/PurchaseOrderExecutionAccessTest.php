<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseOrderExecutionAccessTest extends TestCase
{
    private $owner;

    private $writer;

    private $finance;

    private $issuer;

    private Project $project;

    private string $buyer;

    private PurchaseOrderExecutionAccessService $access;

    private string $url = '/projects/PH-14/procurement/order-execution-access?lang=en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->finance = $this->userByEmail('tehrani@pmc.example');
        $this->issuer = $this->userByEmail('sadeghi@pars.example');
        $this->buyer = $this->writer->company_id;
        $this->access = app(PurchaseOrderExecutionAccessService::class);
    }

    private function bound(callable $fn, $actor = null): mixed
    {
        return app(TenantContext::class)->runAs(($actor ?? $this->owner)->company_id, $fn, $this->project->id);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['buyer_company_id' => $this->buyer, 'version' => 0, 'finance_selectors' => ['user:'.$this->finance->id],
            'issue_selectors' => ['user:'.$this->issuer->id], 'suspend' => false, 'reason' => 'Explicit target-buyer financial and issue nominations'], $changes);
    }

    private function configure(array $changes = []): void
    {
        $this->access->configure($this->project, $this->owner, $this->payload($changes));
    }

    private function nominee($actor, string $purpose, ?string $buyer = null, ?string $disc = 'PIPING'): ?string
    {
        return $this->access->nominationId($this->project, $actor, $buyer ?? $this->buyer, $purpose, $disc);
    }

    public function test_no_default_or_draft_inherited_financial_or_issue_nomination(): void
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Draft only']);
        $this->assertNotNull(app(PurchaseOrderAccessService::class)->policyId($this->project, $this->writer, 'PIPING'));
        foreach ([$this->writer, $this->owner, $this->finance, $this->issuer] as $person) {
            foreach (['finance', 'issue'] as $purpose) {
                $this->assertNull($this->nominee($person, $purpose));
            }
        }
    }

    public function test_owner_can_nominate_cross_company_finance_and_independent_issuer_for_one_buyer_only(): void
    {
        $this->actingAs($this->owner)->post($this->url, ['execution_access' => $this->payload()])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($this->nominee($this->finance, 'finance'));
        $this->assertNotNull($this->nominee($this->issuer, 'issue'));
        $this->assertNull($this->nominee($this->finance, 'issue'));
        $this->assertNull($this->nominee($this->issuer, 'finance'));
        $other = $this->bound(fn () => Company::where('code', 'EPC-SAHAND')->firstOrFail()->id);
        $this->assertNull($this->nominee($this->finance, 'finance', $other));
        $this->assertNull($this->nominee($this->writer, 'finance'));
        $this->assertNull($this->nominee($this->issuer, 'invalid'));
    }

    public function test_guests_nonowner_and_nominees_cannot_configure(): void
    {
        $this->post($this->url, ['execution_access' => $this->payload()])->assertRedirect('/login');
        foreach ([$this->writer, $this->finance, $this->issuer] as $person) {
            $this->actingAs($person)->postJson($this->url, ['execution_access' => $this->payload()])->assertForbidden();
        }
        $this->bound(fn () => $this->assertSame(0, DB::table('po_execution_policies')->count()));
    }

    public function test_versions_are_per_buyer_stale_submission_is_rejected_and_history_is_retained(): void
    {
        $this->configure();
        $this->actingAs($this->owner)->postJson($this->url, ['execution_access' => $this->payload()])->assertUnprocessable();
        $other = $this->bound(fn () => Company::where('code', 'EPC-SAHAND')->firstOrFail()->id);
        $this->configure(['buyer_company_id' => $other]);
        $this->configure(['version' => 1, 'issue_selectors' => []]);
        $this->assertNull($this->nominee($this->issuer, 'issue'));
        $this->assertNotNull($this->nominee($this->finance, 'finance'));
        $this->assertNotNull($this->nominee($this->issuer, 'issue', $other));
        $this->bound(fn () => $this->assertSame([1, 2], DB::table('po_execution_policies')->where('buyer_company_id', $this->buyer)->orderBy('version')->pluck('version')->all()));
    }

    public function test_explicit_suspension_clears_both_purposes_without_deleting_old_records(): void
    {
        $this->configure();
        $this->configure(['version' => 1, 'suspend' => true]);
        $this->assertNull($this->nominee($this->finance, 'finance'));
        $this->assertNull($this->nominee($this->issuer, 'issue'));
        $this->bound(fn () => $this->assertSame(2, DB::table('po_execution_policies')->count()));
    }

    public function test_foreign_or_nonbuyer_targets_invalid_people_and_ambiguous_empty_config_are_rejected(): void
    {
        $invalid = $this->bound(fn () => [Company::where('code', 'VEN-AZAR')->firstOrFail()->id, Company::where('code', 'EPC-ARVAND')->firstOrFail()->id]);
        $this->actingAs($this->owner);
        foreach ($invalid as $buyer) {
            $this->postJson($this->url, ['execution_access' => $this->payload(['buyer_company_id' => $buyer])])->assertUnprocessable();
        }
        foreach ([
            ['finance_selectors' => ['user:'.$this->userByEmail('ahmadi@nigc.example')->id]],
            ['finance_selectors' => ['user:'.$this->userByEmail('former@pars.example')->id]],
            ['finance_selectors' => ['role:'.$this->buyer.':consultant']],
            ['finance_selectors' => [], 'issue_selectors' => []], ['reason' => ' '], ['created_by' => $this->owner->id],
            ['finance_selectors' => [['nested']]], ['buyer_company_id' => ['bad']],
        ] as $change) {
            $this->postJson($this->url, ['execution_access' => $this->payload($change)])->assertUnprocessable();
        }
        $this->bound(fn () => $this->assertSame(0, DB::table('po_execution_policies')->count()));
    }

    public function test_company_scoped_role_tracks_current_membership_and_discipline(): void
    {
        $this->configure(['finance_selectors' => ['role:'.$this->finance->company_id.':consultant']]);
        $this->assertNotNull($this->nominee($this->finance, 'finance'));
        $this->assertNull($this->nominee($this->writer, 'finance'));
        app(TenantContext::class)->forIdentityLookup(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->assertNull($this->nominee($this->finance, 'finance'));
        $this->assertNotNull($this->nominee($this->finance, 'finance', null, 'CIVIL'));
        app(TenantContext::class)->forIdentityLookup(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->update(['role_codes' => []]));
        $this->assertNull($this->nominee($this->finance, 'finance', null, 'CIVIL'));
    }

    public function test_user_revocation_and_actor_or_buyer_entitlement_loss_remove_nomination_eligibility(): void
    {
        $this->configure();
        $starter = Plan::where('code', 'starter')->firstOrFail()->id;
        $this->bound(fn () => Company::whereKey($this->buyer)->update(['plan_id' => $starter]));
        $this->assertNull($this->nominee($this->finance, 'finance'));
        $full = Plan::where('code', 'full')->firstOrFail()->id;
        $this->bound(fn () => Company::whereKey($this->buyer)->update(['plan_id' => $full]));
        $this->assertNotNull($this->nominee($this->finance, 'finance'));
        $this->bound(fn () => Company::whereKey($this->finance->company_id)->update(['plan_id' => $starter]));
        $this->assertNull($this->nominee($this->finance, 'finance'));
        $this->bound(fn () => Company::whereKey($this->finance->company_id)->update(['plan_id' => $full]));
        app(TenantContext::class)->forIdentityLookup(fn () => $this->finance->update(['is_active' => false]));
        $this->assertNull($this->nominee($this->finance, 'finance'));
    }

    public function test_archive_blocks_configuration_and_nomination_but_retains_policy_read_history(): void
    {
        $this->configure();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic execution archive');
        $this->assertNull($this->nominee($this->finance, 'finance'));
        $this->actingAs($this->owner)->postJson($this->url, ['execution_access' => $this->payload(['version' => 1])])->assertForbidden();
        $this->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('Explicit target-buyer financial and issue nominations')->assertDontSee('name="execution_access[buyer_company_id]"', false);
    }

    public function test_sql_policy_is_append_only_project_scoped_and_rejects_bad_version_and_selector(): void
    {
        $this->configure();
        $record = $this->bound(fn () => DB::table('po_execution_policies')->first());
        $this->bound(function (): void {
            $this->assertSame(0, DB::table('po_execution_policies')->update(['reason' => 'rewrite']));
            $this->assertSame(0, DB::table('po_execution_policies')->delete());
        });
        app(TenantContext::class)->runAs($this->owner->company_id, fn () => $this->assertSame(0, DB::table('po_execution_policies')->count()));
        $other = Project::where('code', 'BB-2')->firstOrFail();
        app(TenantContext::class)->runAs($this->writer->company_id, fn () => $this->assertSame(0, DB::table('po_execution_policies')->count()), $other->id);
        foreach (['version', 'selector'] as $bad) {
            $data = (array) $record;
            $data['id'] = (string) Str::uuid();
            if ($bad === 'selector') {
                $data['version'] = 2;
                $data['finance_selectors'] = json_encode([['selector' => 'user:'.Str::uuid(), 'label' => 'forged']]);
            }
            try {
                $this->bound(fn () => DB::table('po_execution_policies')->insert($data));
                $this->fail('Expected SQL guard');
            } catch (QueryException $e) {
                $this->assertStringContainsString($bad === 'version' ? 'PO_EXECUTION_POLICY_STALE' : 'PO_EXECUTION_SELECTOR_INVALID', $e->getMessage());
            }
        }
    }

    public function test_bilingual_safe_ui_preserves_buyer_scope_and_is_explicitly_nomination_only(): void
    {
        $this->configure(['reason' => '<script>alert("x")</script>']);
        $this->actingAs($this->owner)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('PO financial / issuance nominations')
            ->assertSee('nomination-only')->assertSee('execution_access[finance_selectors][]', false)->assertSee('execution_access[issue_selectors][]', false)
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert("x")</script>', false);
        $this->get('/projects/PH-14/procurement?lang=fa')->assertOk()->assertSee('شرکت خریدار هدف')->assertSee('سه شخص متفاوت');
        $this->withSession(['_old_input' => ['execution_access' => ['buyer_company_id' => $this->buyer, 'version' => ['bad'], 'finance_selectors' => 'bad', 'reason' => ['bad']]]])->get('/projects/PH-14/procurement?lang=en')->assertOk();
    }

    public function test_nomination_does_not_approve_issue_or_delegate_order_read_access(): void
    {
        $counterparty = $this->userByEmail('mousavi@sahand.example');
        app(TenantContext::class)->forIdentityLookup(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $counterparty->id)->update(['discipline_scope' => null]));
        $this->configure(['finance_selectors' => ['user:'.$counterparty->id]]);
        $this->assertNotNull($this->nominee($counterparty, 'finance'));
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Draft-only source']);
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, ['title_en' => 'No execution', 'title_fa' => 'بدون اجرا', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor,
            'lines' => [['item_code' => 'PIPE', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '1.000']]]);
        $this->actingAs($counterparty)->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=en')->assertNotFound();
        $this->post('/projects/PH-14/procurement/orders/'.$po->id.'/issue')->assertNotFound();
        $this->bound(function () use ($po): void {
            $fresh = $po->fresh();
            $this->assertSame('DRAFT', $fresh->state);
            $this->assertNull($fresh->issued_by);
            $this->assertNull($fresh->issued_at);
        }, $this->writer);
    }

    public function test_owner_without_current_configure_capability_cannot_change_policy(): void
    {
        app(TenantContext::class)->forIdentityLookup(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->owner->id)->update(['role_codes' => ['qc_inspector']]));
        $this->actingAs($this->owner)->postJson($this->url, ['execution_access' => $this->payload()])->assertForbidden();
        $this->bound(fn () => $this->assertSame(0, DB::table('po_execution_policies')->count()));
    }
}
