<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\PaymentCertificate;
use App\Models\Project;
use App\Support\TenantContext;
use App\Support\TenantType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Multi-project demo data.
 *
 * Deliberately shaped to make the interesting cases testable:
 *
 *   - THREE projects, two different employers.
 *   - Two projects on the IPS (Iranian) pack, one on ISO — proving the
 *     standards framework is selected per project, not per installation.
 *   - Pars works on TWO projects (the portfolio case).
 *   - Sahand competes with Pars on ONE project (commercial firewall).
 *   - Arvand participates in a project Pars is NOT on (cross-project leakage).
 *   - Both projects have a صورت‌وضعیت numbered 1 (per-project numbering).
 */
final class EpccDemoSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------- Companies ----------------
        $nioc = Company::create([
            'code' => 'NIOC-AFF', 'tenant_type' => 'OWN',
            'name_en' => 'NIOC Affiliate Company', 'name_fa' => 'شرکت وابسته به شرکت ملی نفت',
        ]);

        $nigc = Company::create([
            'code' => 'NIGC-REF', 'tenant_type' => 'OWN',
            'name_en' => 'NIGC Refinery Division', 'name_fa' => 'شرکت ملی گاز - پالایش',
        ]);

        $pars = Company::create([
            'code' => 'EPC-PARS', 'tenant_type' => 'EPC',
            'name_en' => 'Pars Construction & Engineering', 'name_fa' => 'مهندسی و ساخت پارس',
        ]);

        $sahand = Company::create([
            'code' => 'EPC-SAHAND', 'tenant_type' => 'EPC',
            'name_en' => 'Sahand Industrial Group', 'name_fa' => 'گروه صنعتی سهند',
        ]);

        $arvand = Company::create([
            'code' => 'EPC-ARVAND', 'tenant_type' => 'EPC',
            'name_en' => 'Arvand Petro Construction', 'name_fa' => 'پترو ساخت اروند',
        ]);

        $pmc = Company::create([
            'code' => 'PMC-TEHRAN', 'tenant_type' => 'PMC',
            'name_en' => 'Tehran Consulting Engineers', 'name_fa' => 'مهندسان مشاور تهران',
        ]);

        /*
         * The MANAGEMENT CONTRACTOR (مدیریت پیمان) - a firm the EMPLOYER
         * engages to administer the contract with the contractor on its behalf:
         * supervising execution, checking progress and quality, and verifying
         * what the contractor claims before the employer accepts it.
         *
         * A separate tenant on purpose. Journey 5 turns on the verifier being
         * neither the contractor that did the work nor the owner that accepts
         * it. With only EPC and OWN in the fixture, "the contractor cannot
         * verify its own fix" is untestable, because there is nobody else who
         * could.
         *
         * Do not read MC as "Mechanical Completion" - that is the milestone
         * this firm signs off, not the kind of company it is. See
         * App\Support\TenantType::MC.
         */
        $mcBody = Company::create([
            'code' => 'MC-KARAN', 'tenant_type' => TenantType::MC,
            'name_en' => 'Karan Contract Management', 'name_fa' => 'مدیریت پیمان کاران',
        ]);

        /*
         * A third-party inspection body.
         *
         * Added with the ITP work, because an ITP without a TPI is not an ITP:
         * the whole party grid turns on there being an inspector who is
         * neither the contractor doing the work nor the employer paying for
         * it. Without this company, "the TPI holds this point" was untestable
         * and the corresponding test silently skipped - which is worse than a
         * failure, because it looks like a pass.
         */
        $tpi = Company::create([
            'code' => 'TPI-BAZRASI', 'tenant_type' => TenantType::TPI,
            'name_en' => 'Fanavaran Bazrasi Inspection Co.',
            'name_fa' => 'شرکت بازرسی فناوران',
        ]);

        // An equipment supplier to EPC-PARS. Vendors do not log in (the EPC's
        // document controller records their letters), but they must exist as
        // companies so a vendor transmittal can name its originator and so the
        // separation between the two streams is demonstrable in the fixture.
        $vendor = Company::create([
            'code' => 'VEN-AZAR', 'tenant_type' => 'VEN',
            'name_en' => 'Azar Pump Manufacturing', 'name_fa' => 'پمپ‌سازی آذر',
        ]);

        // ---------------- Projects ----------------
        $ph14 = Project::create([
            'code' => 'PH-14', 'standards_pack' => 'ips-iran',
            'name_en' => 'South Pars Phase 14 - Utilities',
            'name_fa' => 'پارس جنوبی فاز ۱۴ - یوتیلیتی',
            'owner_company_id' => $nioc->id,
        ]);

        $bidBoland = Project::create([
            'code' => 'BB-2', 'standards_pack' => 'ips-iran',
            'name_en' => 'Bid Boland Gas Refinery - Unit 200',
            'name_fa' => 'پالایشگاه گاز بیدبلند - واحد ۲۰۰',
            'owner_company_id' => $nigc->id,
        ]);

        // Deliberately on the ISO pack: proves pack selection is per project.
        $kish = Project::create([
            'code' => 'KISH-1', 'standards_pack' => 'iso',
            'name_en' => 'Kish Island LNG - FEED Package',
            'name_fa' => 'ال‌ان‌جی جزیره کیش - بستهٔ FEED',
            'owner_company_id' => $nioc->id,
        ]);

        // ---------------- Participation ----------------
        $join = function (Project $p, Company $c, string $role): void {
            DB::table('project_company')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $p->id, 'company_id' => $c->id, 'role' => $role,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        // PH-14: Pars vs Sahand, NIOC employer, Tehran consulting.
        $join($ph14, $nioc, 'EMPLOYER');
        $join($ph14, $pars, 'CONTRACTOR');
        $join($ph14, $sahand, 'CONTRACTOR');
        $join($ph14, $pmc, 'CONSULTANT');
        $join($ph14, $mcBody, 'MC');
        $join($ph14, $tpi, 'TPI');
        // The vendor participates so its letters can be attributed, but it has
        // no users: the EPC records what the vendor sent.
        $join($ph14, $vendor, 'VENDOR');

        // BB-2: Pars again (portfolio), different employer.
        $join($bidBoland, $nigc, 'EMPLOYER');
        $join($bidBoland, $pars, 'CONTRACTOR');
        $join($bidBoland, $pmc, 'CONSULTANT');

        // KISH-1: Arvand only. Pars is NOT here.
        $join($kish, $nioc, 'EMPLOYER');
        $join($kish, $arvand, 'CONTRACTOR');

        // ---------------- Payment certificates ----------------
        // RLS is FORCEd, so even seeding must bind a tenant AND the company
        // must actually participate in the project. No unscoped write path.
        $tenant = app(TenantContext::class);

        // PH-14 / Pars - complete, clears the gate.
        $tenant->runAs($pars->id, fn () => PaymentCertificate::create([
            'project_id' => $ph14->id, 'company_id' => $pars->id,
            'number' => 'PH14-SV-0007', 'serial' => 7, 'state' => 'SUBMITTED',
            'gross_amount' => 48_250_000_000,
            'price_adjustment' => 6_110_000_000,
            'retention' => 4_825_000_000,
            'advance_recovery' => 9_650_000_000,
            'tax' => 4_341_450_000,
            'net_amount' => 43_234_500_000,
            'consultant_verified' => true,
            'measurement_sheets' => ['MS-0071', 'MS-0072', 'MS-0073'],
            'period_start' => '2026-06-22', 'period_end' => '2026-09-22',
        ]));

        // PH-14 / Sahand - incomplete, hard-blocked. Must stay invisible to Pars.
        $tenant->runAs($sahand->id, fn () => PaymentCertificate::create([
            'project_id' => $ph14->id, 'company_id' => $sahand->id,
            'number' => 'PH14-SV-0003', 'serial' => 3, 'state' => 'DRAFT',
            'gross_amount' => 12_400_000_000,
            'price_adjustment' => null, 'retention' => 1_240_000_000,
            'advance_recovery' => null, 'tax' => null,
            'net_amount' => 11_160_000_000,
            'consultant_verified' => false,
            'measurement_sheets' => [],
            'period_start' => '2026-06-22', 'period_end' => '2026-09-22',
        ]));

        // BB-2 / Pars - serial 1. Same contractor, different project.
        $tenant->runAs($pars->id, fn () => PaymentCertificate::create([
            'project_id' => $bidBoland->id, 'company_id' => $pars->id,
            'number' => 'BB2-SV-0001', 'serial' => 1, 'state' => 'SUBMITTED',
            'gross_amount' => 22_800_000_000,
            'price_adjustment' => 1_950_000_000,
            'retention' => 2_280_000_000,
            'advance_recovery' => 4_560_000_000,
            'tax' => 1_710_000_000,
            'net_amount' => 19_620_000_000,
            'consultant_verified' => true,
            'measurement_sheets' => ['MS-BB-011'],
            'period_start' => '2026-06-22', 'period_end' => '2026-09-22',
        ]));

        // KISH-1 / Arvand - ALSO serial 1, proving per-project numbering.
        // Missing consultant verification, so it blocks under the ISO pack.
        $tenant->runAs($arvand->id, fn () => PaymentCertificate::create([
            'project_id' => $kish->id, 'company_id' => $arvand->id,
            'number' => 'KISH-SV-0001', 'serial' => 1, 'state' => 'DRAFT',
            'gross_amount' => 8_900_000_000,
            'price_adjustment' => 0,
            'retention' => 890_000_000,
            'advance_recovery' => 0,
            'tax' => 801_000_000,
            'net_amount' => 7_209_000_000,
            'consultant_verified' => false,
            'measurement_sheets' => ['MS-K-001'],
            'period_start' => '2026-06-22', 'period_end' => '2026-09-22',
        ]));
    }
}
