<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentCertificate;
use App\Models\Project;
use App\Support\TenantContext;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The ARC §5.1 tenant-isolation backstop, and the Annex A §3.4 spike:
 * does Eloquent + session-scoped RLS actually hold?
 */
final class RowLevelSecurityTest extends TestCase
{
    private Company $pars;
    private Company $sahand;
    private Company $employer;
    private Company $consultant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);

        $this->pars       = Company::where('code', 'EPC-PARS')->firstOrFail();
        $this->sahand     = Company::where('code', 'EPC-SAHAND')->firstOrFail();
        $this->employer   = Company::where('code', 'NIOC-AFF')->firstOrFail();
        $this->consultant = Company::where('code', 'PMC-TEHRAN')->firstOrFail();
    }

    public function test_with_no_tenant_bound_nothing_is_visible(): void
    {
        // Fail closed. An unset GUC must mean "see nothing", never "see all".
        // This is the single most important property of the whole scheme.
        $this->assertSame(0, PaymentCertificate::count());
    }

    public function test_a_contractor_sees_only_its_own_certificate(): void
    {
        // Pars now runs two projects, so scope to one to assert on a single
        // row. Its cross-project total is covered by MultiProjectTest.
        $rows = app(TenantContext::class)->runAs(
            $this->pars->id,
            fn () => PaymentCertificate::pluck('number')->all(),
            Project::where('code', 'PH-14')->firstOrFail()->id,
        );

        $this->assertSame(['PH14-SV-0007'], $rows);
    }

    public function test_a_contractor_cannot_see_a_rival_on_the_same_project(): void
    {
        // The commercial firewall (SRS). Two EPCs on one project must never
        // see each other's صورت‌وضعیت - that is competitively sensitive.
        $rows = app(TenantContext::class)->runAs(
            $this->pars->id,
            fn () => PaymentCertificate::pluck('number')->all(),
        );

        $this->assertNotContains('PH14-SV-0003', $rows);
    }

    public function test_the_rival_sees_only_its_own(): void
    {
        $rows = app(TenantContext::class)->runAs(
            $this->sahand->id,
            fn () => PaymentCertificate::pluck('number')->all(),
        );

        $this->assertSame(['PH14-SV-0003'], $rows);
    }

    public function test_the_employer_sees_every_participant_on_its_project(): void
    {
        $rows = app(TenantContext::class)->runAs(
            $this->employer->id,
            fn () => PaymentCertificate::orderBy('serial')->pluck('number')->all(),
            Project::where('code', 'PH-14')->firstOrFail()->id,
        );

        $this->assertSame(['PH14-SV-0003', 'PH14-SV-0007'], $rows);
    }

    public function test_a_consultant_with_no_rows_sees_nothing(): void
    {
        // The consultant participates but owns no certificates, and is not the
        // project owner - so the employer predicate must not fire for it.
        $rows = app(TenantContext::class)->runAs(
            $this->consultant->id,
            fn () => PaymentCertificate::pluck('number')->all(),
        );

        $this->assertSame([], $rows);
    }

    public function test_raw_unscoped_sql_is_also_filtered(): void
    {
        // RLS must protect paths that bypass Eloquent entirely - raw queries,
        // reports, a careless Filament table. That is the whole point of a
        // database-level backstop.
        $rows = app(TenantContext::class)->runAs(
            $this->pars->id,
            fn () => DB::select('select number from payment_certificates'),
            Project::where('code', 'PH-14')->firstOrFail()->id,
        );

        $this->assertCount(1, $rows);
        $this->assertSame('PH14-SV-0007', $rows[0]->number);
    }

    public function test_a_tenant_cannot_write_a_row_belonging_to_another(): void
    {
        // WITH CHECK: forging company_id must be refused by the database.
        $this->expectException(\Illuminate\Database\QueryException::class);

        app(TenantContext::class)->runAs($this->pars->id, function (): void {
            PaymentCertificate::create([
                'project_id' => Project::firstOrFail()->id,
                'company_id' => $this->sahand->id, // not us
                'number' => 'FORGED-001', 'serial' => 99, 'state' => 'DRAFT',
                'gross_amount' => 1, 'net_amount' => 1,
            ]);
        });
    }

    public function test_binding_outside_a_transaction_is_refused(): void
    {
        // SET LOCAL outside a transaction is silently discarded by PostgreSQL,
        // which would fail OPEN. TenantContext must refuse rather than allow it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/requires an open transaction/');

        app(TenantContext::class)->bind($this->pars->id);
    }

    public function test_context_does_not_leak_between_transactions(): void
    {
        // The Octane risk from Annex A §3.4: a long-lived process reusing a
        // connection must not carry tenant A's context into tenant B's request.
        $tenant = app(TenantContext::class);

        $ph14 = Project::where('code', 'PH-14')->firstOrFail()->id;

        $first = $tenant->runAs($this->pars->id, fn () => PaymentCertificate::pluck('number')->all(), $ph14);
        $this->assertSame(['PH14-SV-0007'], $first);

        // Same connection, new transaction, no bind: must be empty again.
        $this->assertSame(0, PaymentCertificate::count());

        $second = $tenant->runAs($this->sahand->id, fn () => PaymentCertificate::pluck('number')->all(), $ph14);
        $this->assertSame(['PH14-SV-0003'], $second);
    }
}
