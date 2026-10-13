<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** End-to-end: PostgreSQL + RLS -> engine -> bilingual screen. */
final class CertificateScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    public function test_tenant_context_is_a_singleton(): void
    {
        // Regression: a non-singleton produced a second instance whose
        // companyId() was null, so audit rows were stamped with the wrong
        // company and RLS WITH CHECK rejected the insert (a 500 for employers).
        $this->assertSame(app(TenantContext::class), app(TenantContext::class));
    }

    public function test_contractor_sees_only_its_own_row_on_screen(): void
    {
        $r = $this->actingAs($this->userByEmail('rezaei@pars.example'))->get('/projects/PH-14');
        $r->assertOk();
        $r->assertSee('PH14-SV-0007');
        $r->assertDontSee('PH14-SV-0003'); // commercial firewall
    }

    public function test_rival_contractor_sees_only_its_own(): void
    {
        $r = $this->actingAs($this->userByEmail('mousavi@sahand.example'))->get('/projects/PH-14');
        $r->assertOk();
        $r->assertSee('PH14-SV-0003');
        $r->assertDontSee('PH14-SV-0007');
    }

    public function test_employer_sees_both_and_page_does_not_error(): void
    {
        // Regression for the audit-actor bug: reading another company's
        // certificate must not attempt to write an audit row owned by them.
        $r = $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/projects/PH-14');
        $r->assertOk();
        $r->assertSee('PH14-SV-0003');
        $r->assertSee('PH14-SV-0007');
    }

    public function test_consultant_sees_an_empty_state_not_an_error(): void
    {
        $r = $this->actingAs($this->userByEmail('tehrani@pmc.example'))->get('/projects/PH-14');
        $r->assertOk();
        $r->assertDontSee('PH14-SV-0007');
        $r->assertSee('RLS'); // the explanatory empty-state copy
    }

    public function test_incomplete_certificate_is_hard_blocked_with_persian_reasons(): void
    {
        $r = $this->actingAs($this->userByEmail('mousavi@sahand.example'))->get('/projects/PH-14?lang=fa');
        $r->assertOk();
        $r->assertSee('measurementSheetsAttached');
        $r->assertSee('برگه‌های متره باید به صورت‌وضعیت پیوست شود.');
    }

    public function test_audit_row_records_actor_and_subject_separately(): void
    {
        $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/projects/PH-14')->assertOk();

        $employer = DB::table('companies')->where('code', 'NIOC-AFF')->value('id');
        $sahand   = DB::table('companies')->where('code', 'EPC-SAHAND')->value('id');

        // gate_evaluations is itself RLS-protected, so the assertion has to
        // read it AS the employer. Querying unbound correctly returns nothing -
        // which is the policy working, not a missing row.
        $row = app(TenantContext::class)->runAs(
            $employer,
            fn () => DB::table('gate_evaluations')
                ->where('actor_company_id', $employer)
                ->where('subject_company_id', $sahand)
                ->first(),
        );

        $this->assertNotNull($row, 'Employer evaluating a contractor certificate must be auditable.');
        $this->assertSame('ips.iran', $row->pack_id);
        $this->assertSame('1.0.0', $row->pack_version);
    }

    public function test_english_renders_ltr(): void
    {
        $r = $this->actingAs($this->userByEmail('karimi@nioc.example'))->get('/projects/PH-14?lang=en');
        $r->assertOk();
        $r->assertSee('dir="ltr"', false);
        $r->assertSee('Interim Payment Certificates');
    }
}
