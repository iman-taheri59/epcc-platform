<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PaymentCertificate;
use App\Models\Project;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/** One or many projects: isolation, scoping, and per-project pack selection. */
final class MultiProjectTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function company(string $code): Company
    {
        return Company::where('code', $code)->firstOrFail();
    }

    private function project(string $code): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    // ---------- portfolio ----------

    public function test_contractor_on_two_projects_sees_both(): void
    {
        $r = $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/');
        $r->assertOk();
        $r->assertSee('PH-14');
        $r->assertSee('BB-2');
        $r->assertDontSee('KISH-1'); // not a participant
    }

    public function test_contractor_on_one_project_sees_only_that_one(): void
    {
        $r = $this->actingAs($this->userByEmail('hosseini@arvand.example'))->get('/');
        $r->assertOk();
        $r->assertSee('KISH-1');
        $r->assertDontSee('PH-14');
        $r->assertDontSee('BB-2');
    }

    public function test_each_employer_sees_only_its_own_projects(): void
    {
        $this->actingAs($this->userByEmail('ahmadi@nigc.example'))->get('/')
            ->assertOk()
            ->assertSee('BB-2')
            ->assertDontSee('PH-14');

        $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/')
            ->assertOk()
            ->assertSee('PH-14')
            ->assertSee('KISH-1')
            ->assertDontSee('BB-2');
    }

    // ---------- per-project drill-down ----------

    public function test_reaching_a_project_you_do_not_participate_in_is_404(): void
    {
        $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/KISH-1')->assertNotFound();
        $this->actingAs($this->userByEmail('hosseini@arvand.example'))->get('/projects/PH-14')->assertNotFound();
        $this->actingAs($this->userByEmail('mousavi@sahand.example'))->get('/projects/BB-2')->assertNotFound();
    }

    public function test_project_scope_narrows_rows_to_that_project(): void
    {
        $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/PH-14')
            ->assertOk()
            ->assertSee('PH14-SV-0007')
            ->assertDontSee('BB2-SV-0001'); // other project, same company

        $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/BB-2')
            ->assertOk()
            ->assertSee('BB2-SV-0001')
            ->assertDontSee('PH14-SV-0007');
    }

    public function test_commercial_firewall_still_holds_within_a_project(): void
    {
        $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/PH-14')
            ->assertOk()
            ->assertDontSee('PH14-SV-0003');
    }

    // ---------- RLS enforced independently of the controller ----------

    public function test_rls_refuses_a_foreign_project_even_when_scoped_directly(): void
    {
        // Bypass the controller entirely: the database must still refuse.
        $rows = app(TenantContext::class)->runAs(
            $this->company('EPC-PARS')->id,
            fn () => PaymentCertificate::pluck('number')->all(),
            $this->project('KISH-1')->id,
        );

        $this->assertSame([], $rows);
    }

    public function test_unscoped_shows_all_permitted_projects_only(): void
    {
        $rows = app(TenantContext::class)->runAs(
            $this->company('EPC-PARS')->id,
            fn () => PaymentCertificate::orderBy('number')->pluck('number')->all(),
        );

        $this->assertSame(['BB2-SV-0001', 'PH14-SV-0007'], $rows);
    }

    public function test_cannot_write_into_a_project_you_do_not_participate_in(): void
    {
        $this->expectException(QueryException::class);

        app(TenantContext::class)->runAs($this->company('EPC-PARS')->id, function (): void {
            PaymentCertificate::create([
                'project_id' => $this->project('KISH-1')->id,
                'company_id' => $this->company('EPC-PARS')->id,
                'number' => 'HACK-1', 'serial' => 99, 'state' => 'DRAFT',
                'gross_amount' => 1, 'net_amount' => 1,
            ]);
        });
    }

    // ---------- per-project configuration ----------

    public function test_standards_pack_is_selected_per_project(): void
    {
        $this->assertSame('ips-iran', $this->project('PH-14')->standards_pack);
        $this->assertSame('iso', $this->project('KISH-1')->standards_pack);

        // And the UI reflects it.
        $this->actingAs($this->userByEmail('hosseini@arvand.example'))->get('/projects/KISH-1?lang=en')
            ->assertOk()
            ->assertSee('iso');
    }

    public function test_serial_numbers_are_unique_per_project_not_globally(): void
    {
        // BB-2 and KISH-1 both have a certificate with serial 1.
        $bb = app(TenantContext::class)->runAs(
            $this->company('EPC-PARS')->id,
            fn () => PaymentCertificate::where('serial', 1)->pluck('number')->all(),
            $this->project('BB-2')->id,
        );

        $kish = app(TenantContext::class)->runAs(
            $this->company('EPC-ARVAND')->id,
            fn () => PaymentCertificate::where('serial', 1)->pluck('number')->all(),
            $this->project('KISH-1')->id,
        );

        $this->assertSame(['BB2-SV-0001'], $bb);
        $this->assertSame(['KISH-SV-0001'], $kish);
    }

    public function test_project_scope_is_cleared_between_transactions(): void
    {
        $tenant = app(TenantContext::class);
        $pars = $this->company('EPC-PARS')->id;

        $scoped = $tenant->runAs($pars, fn () => PaymentCertificate::count(), $this->project('BB-2')->id);
        $this->assertSame(1, $scoped);

        // Next transaction with no project scope must widen again, not stay stuck.
        $unscoped = $tenant->runAs($pars, fn () => PaymentCertificate::count());
        $this->assertSame(2, $unscoped);
    }

    public function test_nested_run_as_restores_the_callers_context(): void
    {
        // SET LOCAL is transaction-scoped, not savepoint-scoped: a nested
        // runAs() must not leave its scope applied to the caller.
        $tenant = app(TenantContext::class);
        $pars = $this->company('EPC-PARS')->id;
        $nioc = $this->company('NIOC-AFF')->id;
        $bb2 = $this->project('BB-2')->id;

        DB::transaction(function () use ($tenant, $pars, $nioc, $bb2): void {
            $tenant->bind($pars);
            $this->assertSame(2, PaymentCertificate::count());

            $tenant->runAs($nioc, fn () => null, $bb2);

            $this->assertSame($pars, $tenant->currentGuc());
            $this->assertNull($tenant->currentProjectGuc());
            $this->assertSame(2, PaymentCertificate::count());

            // ...and the restore must survive an exception in the callback.
            try {
                $tenant->runAs($nioc, fn () => throw new RuntimeException('boom'), $bb2);
            } catch (RuntimeException) {
                // expected
            }

            $this->assertSame($pars, $tenant->currentGuc());
            $this->assertNull($tenant->currentProjectGuc());
        });
    }

    public function test_gate_verdicts_are_computed_per_project_pack(): void
    {
        // KISH-1 runs the ISO pack, which defines no interim-payment gate.
        // The certificate therefore passes - but must be shown as ungated,
        // never as "Cleared", which would overstate the assurance behind it.
        $this->actingAs($this->userByEmail('hosseini@arvand.example'))->get('/projects/KISH-1?lang=en')
            ->assertOk()
            ->assertSee('Not gated')
            ->assertDontSee('>Cleared<', false);

        // PH-14 runs IPS. Pars's certificate is complete in every respect,
        // but the viewer's delegated authority now drives the budget check:
        // the PM's limit is 40B and the certificate is 43.2B, so the gate
        // blocks on budgetWithinAuthority. The employer's representative,
        // whose limit is 100B, sees the same certificate cleared.
        $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/PH-14?lang=en')
            ->assertOk()
            ->assertSee('Blocked')
            ->assertSee('budgetWithinAuthority');

        $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/projects/PH-14?lang=en')
            ->assertOk()
            ->assertSee('Cleared');
    }
}
