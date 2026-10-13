<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Document;
use App\Models\MaterialLot;
use App\Models\Project;
use App\Models\Subsystem;
use App\Services\ProcurementService;
use App\Services\WarehouseService;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * A worked example of the whole chain on PH-14, built so that every state a
 * reviewer needs to see actually exists somewhere in the data.
 *
 * The fixture is deliberately UNEVEN. A register where every row is healthy
 * proves nothing: it cannot show a shortage, a failed inspection, or an
 * un-traceable lot, and those are exactly the rows a buyer opens the screen
 * to find. So:
 *
 *   MR-0001  fully approved and ordered      the happy path
 *   MR-0002  technically approved only       waiting on commercial sign-off
 *   MR-0003  rejected with a reason          so the rejected branch is visible
 *
 *   PO-0001  valves, received, inspected PASS, delivered to site
 *   PO-0002  pipe, received in two heats, one FAILED inspection, part-issued
 *   PO-0003  issued, nothing received yet, forecast slipping - expediting
 *
 * The pipe lot with a failed inspection is the important one: under the
 * advisory rule the user chose, it is sitting in the warehouse, counted as
 * stock, with a warning on it. That is the exact condition the module has to
 * make visible rather than block.
 */
final class ProcurementSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class);
        $proc = app(ProcurementService::class);
        $wh = app(WarehouseService::class);

        $ph14 = $tenant->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
        $pars = $tenant->forIdentityLookup(fn () => Company::where('code', 'EPC-PARS')->firstOrFail());
        $azar = $tenant->forIdentityLookup(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail());
        $nioc = $tenant->forIdentityLookup(fn () => Company::where('code', 'NIOC-AFF')->firstOrFail());
        $karan = $tenant->forIdentityLookup(fn () => Company::where('code', 'MC-KARAN')->firstOrFail());

        $u = fn (string $email) => $tenant->forIdentityLookup(
            fn () => \App\Models\User::where('email', $email)->firstOrFail()
        );

        $rezaei = $u('rezaei@pars.example');    // EPC PM - raises
        $sadeghi = $u('sadeghi@pars.example');  // EPC engineer - technical
        $karimi = $u('karimi@nioc.example');    // employer - commercial
        $jafari = $u('jafari@karan.example');   // MC - inspects

        /*
         * Subsystems are under RLS, so they need a tenant binding - not
         * forIdentityLookup(), which only opens the identity tables. Reading
         * them as PARS works because PARS is the delivering contractor.
         */
        $mech = $tenant->runAs($pars->id, fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(), $ph14->id);
        $piping = $tenant->runAs($pars->id, fn () => Subsystem::where('code', 'PH-14-SS-6601')->firstOrFail(), $ph14->id);

        // The MTC will be attached to a real document in the CDE, not a file
        // path - traceability that lives outside the register is traceability
        // nobody can find during a handover audit.
        $mtc = $tenant->runAs($pars->id, fn () => Document::where('project_id', $ph14->id)
            ->where('discipline', 'PIPING')->first(), $ph14->id);

        // ------------------------------------------------------------------
        // MR-0001: valves. Raised, both approvals, ordered.
        // ------------------------------------------------------------------
        $mr1 = $tenant->runAs($pars->id, fn () => $proc->raiseRequisition(
            $ph14, $rezaei,
            'Gate valves for pump set P-2201',
            'شیرهای دروازه‌ای برای مجموعه پمپ P-2201',
            'MECHANICAL',
            [[
                'item_code' => 'VLV-GT-6-150',
                'description_en' => '6" gate valve, ASME 150, carbon steel',
                'description_fa' => 'شیر دروازه‌ای ۶ اینچ، کلاس ۱۵۰، فولاد کربنی',
                'unit' => 'EA',
                'quantity' => 4,
                'specification' => 'API 600 / ASME B16.34',
                'subsystem_id' => $mech->id,
            ]],
            'fa',
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $proc->submitRequisition($mr1, $rezaei), $ph14->id);
        $tenant->runAs($pars->id, fn () => $proc->approveTechnically($mr1->refresh(), $sadeghi), $ph14->id);
        $tenant->runAs($nioc->id, fn () => $proc->approveCommercially($mr1->refresh(), $karimi), $ph14->id);

        // ------------------------------------------------------------------
        // MR-0002: stuck at technical approval - waiting on the employer.
        // ------------------------------------------------------------------
        $mr2 = $tenant->runAs($pars->id, fn () => $proc->raiseRequisition(
            $ph14, $rezaei,
            'Instrument tubing and fittings',
            'لوله‌کشی و اتصالات ابزار دقیق',
            'INSTRUMENT',
            [[
                'item_code' => 'TUB-SS-12',
                'description_en' => '1/2" SS316 instrument tubing',
                'description_fa' => 'تیوب ابزار دقیق ۱/۲ اینچ، فولاد ضدزنگ ۳۱۶',
                'unit' => 'M',
                'quantity' => 250,
                'specification' => 'ASTM A269 TP316',
                'subsystem_id' => null,
            ]],
            'fa',
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $proc->submitRequisition($mr2, $rezaei), $ph14->id);
        $tenant->runAs($pars->id, fn () => $proc->approveTechnically($mr2->refresh(), $sadeghi), $ph14->id);

        // ------------------------------------------------------------------
        // MR-0003: rejected, with a reason a buyer can act on.
        // ------------------------------------------------------------------
        $mr3 = $tenant->runAs($pars->id, fn () => $proc->raiseRequisition(
            $ph14, $rezaei,
            'Structural steel for pipe rack extension',
            'سازهٔ فلزی برای توسعهٔ رک لوله',
            'CIVIL',
            [[
                'item_code' => 'STL-IPE-300',
                'description_en' => 'IPE 300 structural beam, St37',
                'description_fa' => 'تیرآهن IPE 300، فولاد St37',
                'unit' => 'KG',
                'quantity' => 12_000,
                'specification' => 'DIN 1025-5',
                'subsystem_id' => null,
            ]],
            'fa',
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $proc->submitRequisition($mr3, $rezaei), $ph14->id);
        $tenant->runAs($nioc->id, fn () => $proc->rejectRequisition(
            $mr3->refresh(), $karimi,
            'تناژ با آخرین نقشهٔ سازه مغایرت دارد؛ پس از تأیید نقشهٔ Rev C دوباره ارسال شود.',
        ), $ph14->id);

        // ------------------------------------------------------------------
        // PO-0001: the valves. Ordered, inspected PASS, received, to site.
        // ------------------------------------------------------------------
        $po1 = $tenant->runAs($pars->id, fn () => $proc->createPurchaseOrder(
            $ph14, $rezaei, $azar,
            'Gate valves - pump set P-2201',
            'شیرهای دروازه‌ای - مجموعه پمپ P-2201',
            [[
                'item_code' => 'VLV-GT-6-150',
                'description_en' => '6" gate valve, ASME 150, carbon steel',
                'description_fa' => 'شیر دروازه‌ای ۶ اینچ، کلاس ۱۵۰، فولاد کربنی',
                'unit' => 'EA',
                'quantity' => 4,
                'unit_price' => 185_000_000,
                'subsystem_id' => $mech->id,
            ]],
            $mr1->refresh(),
            'IRR',
            '2026-08-20',
            'fa',
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $proc->issuePurchaseOrder($po1, $rezaei), $ph14->id);

        // The MC body inspects at the vendor's works and passes it.
        $insp1 = $tenant->runAs($karan->id, fn () => $wh->inspect(
            $ph14, $jafari,
            ProcurementState::INSPECTION_PASS,
            ProcurementState::STAGE_WORKS,
            $po1->refresh(),
            'Hydrostatic test witnessed. Body and seat tight. Certificates reviewed.',
            'تست هیدرواستاتیک شاهد شد. بدنه و نشیمنگاه آب‌بند است. گواهی‌ها بررسی شد.',
        ), $ph14->id);

        $lot1 = $tenant->runAs($pars->id, fn () => $wh->receive(
            $ph14, $rezaei, $po1->refresh(),
            'VLV-GT-6-150',
            '6" gate valve, ASME 150, carbon steel',
            'شیر دروازه‌ای ۶ اینچ، کلاس ۱۵۰، فولاد کربنی',
            'EA', 4,
            heatNumber: null,
            serialNumber: 'AZ-2026-5510',
            location: 'WH-A RACK 14',
            inspection: $insp1,
            mtcDocumentId: $mtc?->id,
            subsystem: $mech,
        ), $ph14->id);

        // Out of the warehouse and onto the plant.
        $tenant->runAs($pars->id, fn () => $wh->issue(
            $ph14, $rezaei, $lot1->refresh(), 4, $mech, 'LAYDOWN-2', 'برای نصب روی P-2201'
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $wh->deliverToSite(
            $ph14, $rezaei, $lot1->refresh(), $mech, 4, 'تحویل به کارگاه نصب'
        ), $ph14->id);

        // ------------------------------------------------------------------
        // PO-0002: pipe in two heats. One heat FAILS inspection and is sitting
        // in the warehouse with a warning - the advisory case.
        // ------------------------------------------------------------------
        $po2 = $tenant->runAs($pars->id, fn () => $proc->createPurchaseOrder(
            $ph14, $rezaei, $azar,
            'Line pipe 6" for flare header',
            'لولهٔ ۶ اینچ برای هدر فلر',
            [[
                'item_code' => 'PIP-CS-6-SCH40',
                'description_en' => '6" CS pipe, SCH 40, API 5L X60 PSL2',
                'description_fa' => 'لولهٔ فولادی ۶ اینچ، رده ۴۰، API 5L X60 PSL2',
                'unit' => 'M',
                'quantity' => 300,
                'unit_price' => 4_200_000,
                'subsystem_id' => $piping->id,
            ]],
            null,
            'IRR',
            '2026-09-05',
            'fa',
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $proc->issuePurchaseOrder($po2, $rezaei), $ph14->id);

        // Heat A-4471: good.
        $inspGood = $tenant->runAs($karan->id, fn () => $wh->inspect(
            $ph14, $jafari,
            ProcurementState::INSPECTION_PASS,
            ProcurementState::STAGE_ON_ARRIVAL,
            $po2->refresh(),
            'Dimensional check and MTC review satisfactory for heat A-4471.',
            'کنترل ابعادی و بررسی گواهی مواد برای ذوب A-4471 رضایت‌بخش است.',
        ), $ph14->id);

        $lotGood = $tenant->runAs($pars->id, fn () => $wh->receive(
            $ph14, $rezaei, $po2->refresh(),
            'PIP-CS-6-SCH40',
            '6" CS pipe, SCH 40, API 5L X60 PSL2',
            'لولهٔ فولادی ۶ اینچ، رده ۴۰، API 5L X60 PSL2',
            'M', 120,
            heatNumber: 'A-4471',
            serialNumber: null,
            location: 'YARD-1 BAY 3',
            inspection: $inspGood,
            mtcDocumentId: $mtc?->id,
            subsystem: $piping,
        ), $ph14->id);

        /*
         * Heat A-4472: FAILED. Received anyway - that is the advisory rule in
         * action. It counts as stock, carries a warning, and appears in
         * uninspectedLots(). Blocking it here is what the user explicitly did
         * NOT want.
         */
        $inspBad = $tenant->runAs($karan->id, fn () => $wh->inspect(
            $ph14, $jafari,
            ProcurementState::INSPECTION_FAIL,
            ProcurementState::STAGE_ON_ARRIVAL,
            $po2->refresh(),
            'Heat A-4472: wall thickness below tolerance on 3 joints; MTC not supplied.',
            'ذوب A-4472: ضخامت جداره در ۳ شاخه زیر رواداری است؛ گواهی مواد ارائه نشده.',
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $wh->receive(
            $ph14, $rezaei, $po2->refresh(),
            'PIP-CS-6-SCH40',
            '6" CS pipe, SCH 40, API 5L X60 PSL2',
            'لولهٔ فولادی ۶ اینچ، رده ۴۰، API 5L X60 PSL2',
            'M', 180,
            heatNumber: 'A-4472',
            serialNumber: null,
            location: 'YARD-1 BAY 4',
            inspection: $inspBad,
            // No MTC - this lot is not fully traceable, and the register
            // should say so rather than imply the paperwork exists.
            mtcDocumentId: null,
            subsystem: $piping,
        ), $ph14->id);

        // Part of the good heat goes out, but only part of it reaches site -
        // so "in transit" is a non-zero number somebody has to chase.
        $tenant->runAs($pars->id, fn () => $wh->issue(
            $ph14, $rezaei, $lotGood->refresh(), 80, $piping, 'LAYDOWN-2', 'پیش‌ساخت اسپول'
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $wh->deliverToSite(
            $ph14, $rezaei, $lotGood->refresh(), $piping, 50, 'بخش اول تحویل شد'
        ), $ph14->id);

        // ------------------------------------------------------------------
        // PO-0003: nothing received, vendor forecasting late. Expediting.
        // ------------------------------------------------------------------
        $po3 = $tenant->runAs($pars->id, fn () => $proc->createPurchaseOrder(
            $ph14, $rezaei, $azar,
            'Pressure gauges and thermowells',
            'گیج فشار و ترموول',
            [[
                'item_code' => 'PG-100-25',
                'description_en' => 'Pressure gauge 100mm, 0-25 barg, SS316',
                'description_fa' => 'گیج فشار ۱۰۰ میلی‌متر، ۰ تا ۲۵ بار، ۳۱۶',
                'unit' => 'EA',
                'quantity' => 18,
                'unit_price' => 9_500_000,
                'subsystem_id' => null,
            ]],
            null,
            'IRR',
            '2026-09-10',
            'fa',
        ), $ph14->id);

        $tenant->runAs($pars->id, fn () => $proc->issuePurchaseOrder($po3, $rezaei), $ph14->id);
        // 24 days late, and the register must show it.
        $tenant->runAs($pars->id, fn () => $proc->forecast($po3->refresh(), '2026-10-04'), $ph14->id);
    }
}
