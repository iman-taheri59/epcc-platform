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
 * PgBouncer transaction-pooling compatibility (Spike §10).
 *
 * In transaction pooling a backend is returned to the pool at COMMIT, so a
 * single PostgreSQL backend serves many tenants and many *client connections*.
 * Verified live against PgBouncer 1.24.1 with default_pool_size=2: SET LOCAL
 * is cleared at COMMIT and never leaked, while a plain SET leaked a tenant's
 * identity to a completely separate client connection, which then read that
 * tenant's certificates.
 *
 * These tests run through whatever port phpunit.xml points at, so they pass
 * both direct and pooled. They exist to stop anyone introducing session-scoped
 * state that silently breaks under a pooler.
 */
final class PoolerCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
    }

    public function test_tenant_state_is_transaction_scoped_not_session_scoped(): void
    {
        // The single property that makes transaction pooling safe.
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $tenant = app(TenantContext::class);

        $tenant->runAs($pars->id, fn () => PaymentCertificate::count());

        // After COMMIT the backend may be handed to another tenant. It must
        // carry nothing forward.
        $this->assertNull($tenant->currentGuc());
        $this->assertNull($tenant->currentProjectGuc());
    }

    public function test_rapid_tenant_switching_stays_isolated(): void
    {
        // Each runAs() is its own transaction, i.e. potentially its own
        // backend under a pooler. Isolation must not depend on connection
        // affinity.
        $tenant = app(TenantContext::class);

        $cases = [
            ['EPC-PARS', ['BB2-SV-0001', 'PH14-SV-0007']],
            ['EPC-SAHAND', ['PH14-SV-0003']],
            ['EPC-ARVAND', ['KISH-SV-0001']],
        ];

        for ($pass = 0; $pass < 10; $pass++) {
            foreach ($cases as [$code, $want]) {
                $id = Company::where('code', $code)->firstOrFail()->id;

                $got = $tenant->runAs(
                    $id,
                    fn () => PaymentCertificate::orderBy('number')->pluck('number')->all(),
                );

                $this->assertSame($want, $got, "$code pass $pass");
                $this->assertSame(0, PaymentCertificate::count(), 'between transactions');
            }
        }
    }

    public function test_no_code_path_uses_session_scoped_postgres_features(): void
    {
        // Transaction pooling breaks LISTEN/NOTIFY, session advisory locks and
        // session-level SET. Guard the source tree so these cannot creep in
        // without a deliberate decision.
        $roots = [app_path(), base_path('database')];
        $banned = [
            'pg_advisory_lock(' => 'session advisory lock - use pg_advisory_xact_lock()',
            'LISTEN ' => 'LISTEN requires a session-pooled connection',
            'NOTIFY ' => 'NOTIFY requires a session-pooled connection',
        ];

        $offenders = [];

        foreach ($roots as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $src = (string) file_get_contents($file->getPathname());

                foreach ($banned as $needle => $why) {
                    if (str_contains($src, $needle)) {
                        $offenders[] = basename($file->getPathname()).": $needle ($why)";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Session-scoped PostgreSQL usage found:\n".implode("\n", $offenders));
    }

    public function test_set_local_requires_a_transaction_so_it_cannot_leak(): void
    {
        // Belt and braces: outside a transaction PostgreSQL silently discards
        // SET LOCAL, which would fail OPEN. TenantContext refuses instead.
        $this->expectException(\RuntimeException::class);

        app(TenantContext::class)->bind(
            Company::where('code', 'EPC-PARS')->firstOrFail()->id
        );
    }

    public function test_transactions_actually_commit_through_the_pooler(): void
    {
        // A write must survive its transaction (guards against a pooler
        // misconfiguration silently rolling work back).
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $ph14 = \App\Models\Project::where('code', 'PH-14')->firstOrFail();
        $tenant = app(TenantContext::class);

        $tenant->runAs($pars->id, function () use ($ph14, $pars): void {
            PaymentCertificate::create([
                'project_id' => $ph14->id, 'company_id' => $pars->id,
                'number' => 'PH14-SV-0099', 'serial' => 99, 'state' => 'DRAFT',
                'gross_amount' => 1, 'net_amount' => 1,
                'period_start' => '2026-06-22', 'period_end' => '2026-09-22',
            ]);
        }, $ph14->id);

        $found = $tenant->runAs(
            $pars->id,
            fn () => PaymentCertificate::where('number', 'PH14-SV-0099')->exists(),
            $ph14->id,
        );

        $this->assertTrue($found, 'write did not survive its transaction');
    }
}
