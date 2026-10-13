<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentCertificate;
use App\Support\TenantContext;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Octane hazard from ADR-003 Annex A §3.4, pinned as a test.
 *
 * Under Octane a worker process and its database connection are reused across
 * requests. Verified live against FrankenPHP with 2 workers: a plain `SET`
 * leaked one tenant's identity into eight subsequent unrelated requests, and
 * an unbound read on the poisoned connection returned that tenant's real
 * certificates. `SET LOCAL` inside a transaction did not leak at all.
 *
 * These tests encode WHY the rule exists, so nobody "simplifies" it later.
 */
final class ConnectionReuseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
    }

    public function test_set_local_does_not_survive_its_transaction(): void
    {
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $tenant = app(TenantContext::class);

        $tenant->runAs($pars->id, fn () => PaymentCertificate::count());

        // Same connection, transaction committed: the GUC must be gone.
        // This is what makes worker reuse safe.
        $this->assertNull($tenant->currentGuc());
        $this->assertNull($tenant->currentProjectGuc());
        $this->assertSame(0, PaymentCertificate::count(), 'unbound read must be fail-closed');
    }

    public function test_a_plain_set_would_leak_which_is_why_it_is_banned(): void
    {
        // Demonstrates the forbidden pattern actually is dangerous. If this
        // ever stops leaking, the reasoning behind the rule has changed and
        // the rule should be re-derived, not quietly dropped.
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();

        DB::statement("SET epcc.company_id = '".$pars->id."'");

        $leaked = app(TenantContext::class)->currentGuc();
        $this->assertSame($pars->id, $leaked, 'plain SET persists outside any transaction');

        // ...and it changes what an unbound reader can see.
        $rows = PaymentCertificate::orderBy('number')->pluck('number')->all();
        $this->assertSame(['BB2-SV-0001', 'PH14-SV-0007'], $rows);

        // Clean up so we do not poison the rest of the suite.
        DB::statement("SET epcc.company_id = ''");
        $this->assertNull(app(TenantContext::class)->currentGuc());
    }

    public function test_interleaved_tenants_on_one_connection_never_bleed(): void
    {
        // Simulates the Octane request sequence on a single reused connection.
        $tenant = app(TenantContext::class);
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $sahand = Company::where('code', 'EPC-SAHAND')->firstOrFail();
        $arvand = Company::where('code', 'EPC-ARVAND')->firstOrFail();

        $expected = [
            [$pars->id, ['BB2-SV-0001', 'PH14-SV-0007']],
            [$sahand->id, ['PH14-SV-0003']],
            [$arvand->id, ['KISH-SV-0001']],
        ];

        // Repeat: a leak often only appears on the second pass.
        for ($i = 0; $i < 5; $i++) {
            foreach ($expected as [$companyId, $want]) {
                $got = $tenant->runAs(
                    $companyId,
                    fn () => PaymentCertificate::orderBy('number')->pluck('number')->all(),
                );

                $this->assertSame($want, $got, "pass $i");

                // Between every request the connection must be neutral again.
                $this->assertNull($tenant->currentGuc());
            }
        }
    }

    public function test_the_singleton_property_is_never_trusted_for_authorization(): void
    {
        // TenantContext is a singleton, so its PHP properties survive between
        // requests under Octane. RLS reads the DATABASE session, not the
        // property - so a stale property cannot grant access.
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $tenant = app(TenantContext::class);

        $tenant->runAs($pars->id, fn () => null);

        // Property may or may not still hold a value; the DB session is what
        // matters, and it is clean.
        $this->assertNull($tenant->currentGuc());
        $this->assertSame(0, PaymentCertificate::count());
    }
}
