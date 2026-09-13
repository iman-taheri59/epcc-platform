<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentCertificate;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `migrate:fresh --seed` is the documented way to get a working system
 * (REBUILD.md). It silently produced an EMPTY database for a while because
 * DatabaseSeeder was still the Laravel stock file and never called
 * EpccDemoSeeder - the feature tests all call EpccDemoSeeder directly, so
 * nothing noticed. This test exercises the documented path itself.
 */
final class SeedPathTest extends TestCase
{
    public function test_the_default_seeder_produces_the_working_demo(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        // 8: the six commercial tenants, MC-KARAN (the management contractor /
        // مدیریت پیمان that journey 5 needs as a verifier which is neither the
        // contractor nor the owner), and VEN-AZAR - an equipment supplier, so the vendor
        // transmittal stream has an originator to name. Vendors have no users:
        // the EPC's document controller records their letters.
        $this->assertSame(8, Company::count(), 'companies');
        $this->assertSame(3, Project::count(), 'projects');

        // payment_certificates is RLS-protected, so count it without a tenant
        // bound would return 0 by policy. Verify via the table owner instead.
        $count = (int) DB::selectOne(
            'select count(*) c from payment_certificates'
        )->c;

        // Unbound reads are fail-closed: that is the policy working, and it is
        // exactly what made this bug hard to see from the outside.
        $this->assertSame(0, $count, 'unbound read must be fail-closed');

        // Bind a participant and the rows are there.
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $rows = app(\App\Support\TenantContext::class)->runAs(
            $pars->id,
            fn () => PaymentCertificate::orderBy('number')->pluck('number')->all(),
        );

        $this->assertSame(['BB2-SV-0001', 'PH14-SV-0007'], $rows);
    }

    public function test_seeder_does_not_suppress_model_events(): void
    {
        // HasUuid generates primary keys on the `creating` event. If
        // DatabaseSeeder ever re-adds WithoutModelEvents, every insert fails
        // with a not-null violation on `id`.
        $seeder = new \Database\Seeders\DatabaseSeeder();

        $this->assertNotContains(
            \Illuminate\Database\Console\Seeds\WithoutModelEvents::class,
            class_uses_recursive($seeder),
            'DatabaseSeeder must not suppress model events - HasUuid needs them.',
        );
    }
}
