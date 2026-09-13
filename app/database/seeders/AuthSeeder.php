<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Plan;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\PunchCategory;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Plans, roles and users for the demo tenants.
 *
 * Deliberately includes cases that must FAIL authorization, because a fixture
 * where everyone can do everything proves nothing:
 *   - Arvand is on the Starter plan (ISO only, no cost module).
 *   - Sahand's site engineer has a discipline scope that excludes PIPING.
 *   - Pars's QC inspector may view certificates but not approve them.
 *   - Pars's PM has an authority limit below the PH14-SV-0007 amount.
 */
final class AuthSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = app(TenantContext::class);

        // ---------------- Plans (SRS BR-02) ----------------
        $starter = Plan::create([
            'code' => 'starter', 'name_en' => 'Starter', 'name_fa' => 'پایه',
            'max_projects' => 1, 'max_users' => 25,
            'modules' => ['core', 'doc'],
            'standards_packs' => ['iso'],
        ]);

        $full = Plan::create([
            'code' => 'full', 'name_en' => 'Full', 'name_fa' => 'کامل',
            'max_projects' => null, 'max_users' => null,
            'modules' => ['core', 'doc', 'cost', 'qaqc', 'awp', 'proc'],
            'standards_packs' => ['*'],
        ]);

        $special = Plan::create([
            'code' => 'special', 'name_en' => 'Special / Enterprise', 'name_fa' => 'ویژه / سازمانی',
            'max_projects' => null, 'max_users' => null,
            'modules' => ['*'],
            'standards_packs' => ['*'],
        ]);

        // ---------------- Roles (gate 4) ----------------
        $roles = [
            // doc.review is the reviewer's capability: raise comments on
            // someone else's drawing. It is deliberately NOT part of doc.* for
            // the originator - answering comments is doc.issue.
            ['employer_rep', 'Employer Representative', 'نمایندهٔ کارفرما',
                ['cert.view', 'cert.approve', 'project.*', 'doc.view', 'doc.review', 'punch.*']],
            ['project_manager', 'Project Manager', 'مدیر پروژه',
                ['cert.view', 'cert.submit', 'project.view', 'doc.*', 'punch.*']],
            ['qc_inspector', 'QC Inspector', 'بازرس کنترل کیفیت',
                ['cert.view', 'punch.*', 'project.view']],
            ['site_engineer', 'Site Engineer', 'مهندس اجرا',
                ['cert.view', 'project.view', 'doc.view']],
            // The SRS visibility matrix (4.1) gives PMC "Verify" on punch
            // items, so the consultant needs punch.* to reach the register at
            // all. Which ACTIONS it may take is decided by PunchListService,
            // not by withholding the capability.
            ['consultant', 'Consultant / supervisor', 'مشاور / ناظر',
                ['cert.view', 'cert.verify', 'project.view', 'doc.view', 'doc.review', 'punch.*']],
            // The MANAGEMENT CONTRACTOR (مدیریت پیمان) administers the contract
            // for the employer: it walks systems down, checks what the
            // contractor claims, and signs off completion on the employer's
            // behalf. punch.* covers raise/verify; punch.close stays with the
            // employer by a rule in PunchListService, not by withholding the
            // capability, because the SRS matrix gives MC "Raise/Verify" and
            // OWN "Approve close".
            ['mc_supervisor', 'Management contractor supervisor', 'ناظر مدیریت پیمان',
                ['punch.*', 'project.view', 'doc.view']],
        ];

        foreach ($roles as [$code, $en, $fa, $caps]) {
            Role::create([
                'code' => $code, 'name_en' => $en, 'name_fa' => $fa,
                'capabilities' => $caps,
            ]);
        }

        // ---------------- Assign plans to companies ----------------
        $planFor = [
            'NIOC-AFF' => $special, 'NIGC-REF' => $special,
            'EPC-PARS' => $full, 'EPC-SAHAND' => $full,
            'EPC-ARVAND' => $starter,   // deliberately limited
            'PMC-TEHRAN' => $full,
            'MC-KARAN' => $full,
        ];

        foreach ($planFor as $code => $plan) {
            Company::where('code', $code)->update(['plan_id' => $plan->id]);
        }

        // ---------------- Users ----------------
        // Provisioning identities is the one job that legitimately writes user
        // rows for many different tenants in a single pass, so it runs inside
        // the identity-lookup hatch. Under the `users` RLS policy a write with
        // no tenant bound is refused by WITH CHECK - which is the policy doing
        // its job, not a bug to work around elsewhere.
        $mk = fn (string $companyCode, string $email, string $name, string $locale = 'fa'): User => $tenant->forIdentityLookup(fn (): User => User::create([
            'company_id' => Company::where('code', $companyCode)->firstOrFail()->id,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'locale' => $locale,
            'is_active' => true,
        ]));

        $niocRep = $mk('NIOC-AFF', 'karimi@nioc.example', 'مهندس کریمی');
        $nigcRep = $mk('NIGC-REF', 'ahmadi@nigc.example', 'مهندس احمدی');
        $parsPm = $mk('EPC-PARS', 'rezaei@pars.example', 'مهندس رضایی');
        $parsQc = $mk('EPC-PARS', 'sadeghi@pars.example', 'مهندس صادقی');
        $sahandEng = $mk('EPC-SAHAND', 'mousavi@sahand.example', 'مهندس موسوی');
        $arvandPm = $mk('EPC-ARVAND', 'hosseini@arvand.example', 'مهندس حسینی');
        $pmc = $mk('PMC-TEHRAN', 'tehrani@pmc.example', 'مهندس تهرانی');
        $mcSupervisor = $mk('MC-KARAN', 'jafari@karan.example', 'مهندس جعفری');

        // A disabled account, to prove gate 1 refuses it.
        $tenant->forIdentityLookup(
            fn () => $mk('EPC-PARS', 'former@pars.example', 'کارمند سابق')->update(['is_active' => false])
        );

        // ---------------- Memberships (gate 3) ----------------
        $p = fn (string $code): Project => Project::where('code', $code)->firstOrFail();

        $join = function (User $u, Project $pr, array $roleCodes, ?array $scope = null, ?float $limit = null) use ($tenant): void {
            $tenant->forIdentityLookup(fn () => ProjectMembership::create([
                'user_id' => $u->id,
                'project_id' => $pr->id,
                'role_codes' => $roleCodes,
                'discipline_scope' => $scope,
                'authority_limit' => $limit,
            ]));
        };

        $join($niocRep, $p('PH-14'), ['employer_rep']);
        $join($niocRep, $p('KISH-1'), ['employer_rep']);
        $join($nigcRep, $p('BB-2'), ['employer_rep']);

        // PM can submit but his authority limit is below the 43.2B certificate.
        $join($parsPm, $p('PH-14'), ['project_manager'], null, 40_000_000_000);
        $join($parsPm, $p('BB-2'), ['project_manager'], null, 40_000_000_000);

        // QC inspector: may view certificates, may not approve them.
        $join($parsQc, $p('PH-14'), ['qc_inspector']);

        // Site engineer restricted to CIVIL - PIPING work must be refused.
        $join($sahandEng, $p('PH-14'), ['site_engineer'], ['CIVIL']);

        // Arvand's PM is on KISH-1, but the Starter plan lacks the cost module.
        $join($arvandPm, $p('KISH-1'), ['project_manager']);

        $join($pmc, $p('PH-14'), ['consultant']);
        $join($pmc, $p('BB-2'), ['consultant']);

        // The MC body's inspector: raises and verifies punch items, and issues
        // the MC certificate. Deliberately has NO cost capability.
        $join($mcSupervisor, $p('PH-14'), ['mc_supervisor']);

        $this->seedDocuments($p('PH-14'), $parsPm, $p('KISH-1'), $arvandPm);
        $this->seedReviewComments($p('PH-14'));
        $this->seedTransmittals($p('PH-14'));
        $this->seedPunchList($p('PH-14'));
    }

    /**
     * A small document register, so the CDE screen has something real to show
     * and every state is represented.
     *
     * Written directly rather than through DocumentRegister because seeding
     * runs outside a tenant scope, and the service is deliberately scope-aware.
     */
    private function seedDocuments(Project $ph14, User $parsPm, Project $kish, User $arvandPm): void
    {
        $rows = [
            // [project, author, discipline, type, seq, EN, FA, rev, state, suitability]
            // The last element lists demo attachments as [filename, ext].
            // Only WIP/SHARED revisions get them: files cannot be added to a
            // published revision, so seeding one there would create a state
            // the application itself refuses to produce.
            [$ph14, $parsPm, 'PIPING', 'PID', 1, 'P&ID - Compressor Suction Header', 'نقشهٔ پی‌اند‌آی‌دی هدر مکش کمپرسور', 'C', 'PUBLISHED', 'A1', []],
            [$ph14, $parsPm, 'PIPING', 'DWG', 1, 'Isometric - Line 14-P-1201', 'ایزومتریک خط ۱۴-P-۱۲۰۱', 'B', 'SHARED', 'S2', ['14-P-1201-iso.pdf', '14-P-1201.dwg']],
            [$ph14, $parsPm, 'CIVIL', 'DWG', 1, 'Foundation Plan - Compressor House', 'پلان فونداسیون سالن کمپرسور', 'A', 'WIP', null, ['foundation-plan-revA.pdf']],
            [$ph14, $parsPm, 'CIVIL', 'CALC', 1, 'Structural Calculation - Pipe Rack', 'محاسبات سازهٔ پایپ‌رک', 'A', 'SHARED', 'S1', ['pipe-rack-calc.xlsx']],
            [$ph14, $parsPm, 'ELEC', 'SPEC', 1, 'Specification - LV Switchgear', 'مشخصات فنی تابلو فشار ضعیف', 'A', 'ARCHIVED', 'A2', []],
            [$kish, $arvandPm, 'PROCESS', 'DS', 1, 'Data Sheet - Separator V-101', 'دیتاشیت جداکنندهٔ V-۱۰۱', 'A', 'WIP', null, ['V-101-datasheet.pdf']],
        ];

        $tenant = app(TenantContext::class);

        foreach ($rows as [$project, $author, $disc, $type, $seq, $en, $fa, $rev, $state, $suit, $files]) {
            // RLS is FORCEd on documents, so an insert with no bound tenant is
            // refused outright - correct behaviour, and the reason every seeded
            // write runs inside runAs() just like the certificates do.
            $tenant->runAs($author->company_id, function () use (
                $project, $author, $disc, $type, $seq, $en, $fa, $rev, $state, $suit, $files
            ): void {
            $doc = Document::create([
                'project_id' => $project->id,
                'company_id' => $author->company_id,
                'number' => sprintf('%s-%s-%s-%04d', $project->code, $disc, $type, $seq),
                'discipline' => $disc,
                'doc_type' => $type,
                'sequence' => $seq,
                'title_en' => $en,
                'title_fa' => $fa,
            ]);

            $revision = DocumentRevision::create([
                'document_id' => $doc->id,
                'project_id' => $project->id,
                'company_id' => $author->company_id,
                'revision' => $rev,
                'ordinal' => 1,
                'cde_state' => $state,
                'suitability' => $suit,
                'issued_by' => $author->id,
                'issued_at' => now(),
            ]);

            $doc->update(['current_revision_id' => $revision->id]);

            $this->seedFiles($doc, $revision, $author, $files);
            }, $project->id);
        }
    }

    /**
     * Seed a review cycle in mid-flight (journey 3).
     *
     * Deliberately left PART-ANSWERED: a demo where every comment is closed
     * shows none of the interesting behaviour, and one where none are answered
     * cannot show a disposition. The SHARED isometric ends up with an open
     * Code 2 - so it is visibly blocked from publication, which is the whole
     * point of the module.
     */
    private function seedReviewComments(Project $ph14): void
    {
        $tenant = app(TenantContext::class);
        $cycle = app(\App\Services\ReviewCycle::class);

        $pmc = Company::where('code', 'PMC-TEHRAN')->first();
        $nioc = Company::where('code', 'NIOC-AFF')->first();
        $tehrani = $tenant->forIdentityLookup(fn () => User::where('email', 'tehrani@pmc.example')->first());
        $karimi = $tenant->forIdentityLookup(fn () => User::where('email', 'karimi@nioc.example')->first());
        $rezaei = $tenant->forIdentityLookup(fn () => User::where('email', 'rezaei@pars.example')->first());

        if (! $pmc || ! $tehrani || ! $rezaei || ! $karimi) {
            return;
        }

        // The SHARED isometric is the one under review.
        $doc = $tenant->runAs(
            $rezaei->company_id,
            fn () => Document::with('currentRevision')
                ->where('number', 'PH-14-PIPING-DWG-0001')->first(),
            $ph14->id,
        );

        if ($doc === null || $doc->currentRevision === null) {
            return;
        }

        // [reviewer user, category, reference, body]
        $raise = [
            [$tehrani, 'CODE2', 'Sheet 2', 'Support SP-14 clashes with the cable tray at EL +4200; relocate or provide a trapeze.'],
            [$tehrani, 'CODE3', 'Sheet 1', 'Weld numbering does not follow the project WPS register; renumber W-01..W-14.'],
            [$karimi,  'INFO',  null,      'Note that line 14-P-1201 is on the Nowruz shutdown critical path.'],
            [$karimi,  'CODE1', 'Sheet 3', 'Material class for the 6" branch is inconsistent with the P&ID; confirm before fabrication.'],
        ];

        $made = [];

        foreach ($raise as [$user, $cat, $ref, $body]) {
            // Each reviewer writes as ITSELF: the comment is attributed to the
            // reviewing company, which is what the RLS policy keys writes on.
            $made[] = $tenant->runAs(
                $user->company_id,
                fn () => $cycle->comment($doc->fresh('currentRevision'), $user, $cat, $body, $ref),
                $ph14->id,
            );
        }

        // The originator has answered two of the four. The open Code 1 and
        // Code 2 keep the drawing blocked, which is the state worth demoing.
        $answers = [
            [$made[1], 'ACCEPTED', 'Renumbered on Rev B per the WPS register.'],
            [$made[2], 'NOTED',    null],
        ];

        foreach ($answers as [$comment, $disposition, $note]) {
            $tenant->runAs(
                $rezaei->company_id,
                fn () => $cycle->dispose($comment->fresh(), $rezaei, $disposition, $note),
                $ph14->id,
            );
        }
    }

    /**
     * Seed transmittals in three different states (FR-ENG-05).
     *
     * One acknowledged, one deliberately OVERDUE, and one still a draft. A
     * demo where everything is acknowledged shows none of the tracking that
     * the module exists for; the overdue row is backdated so the derived
     * overdue flag has something real to compute.
     */
    private function seedTransmittals(Project $ph14): void
    {
        $tenant = app(TenantContext::class);
        $svc = app(\App\Services\TransmittalService::class);

        $rezaei = $tenant->forIdentityLookup(fn () => User::where('email', 'rezaei@pars.example')->first());
        $tehrani = $tenant->forIdentityLookup(fn () => User::where('email', 'tehrani@pmc.example')->first());
        $pmc = Company::where('code', 'PMC-TEHRAN')->first();
        $nioc = Company::where('code', 'NIOC-AFF')->first();

        if (! $rezaei || ! $tehrani || ! $pmc || ! $nioc) {
            return;
        }

        $pick = fn (string $number) => Document::with('currentRevision')->where('number', $number)->first();

        // 1) Issued, acknowledged by the PMC.
        $tenant->runAs($rezaei->company_id, function () use ($svc, $ph14, $rezaei, $pmc, $nioc, $pick): void {
            $tr = $svc->draft($ph14, $rezaei, 'P&ID package for construction', 'بستهٔ پی‌اند‌آی‌دی جهت اجرا', 'FOR_CONSTRUCTION');
            $svc->addItem($tr, $pick('PH-14-PIPING-PID-0001'));
            $svc->addRecipient($tr, $pmc);
            $svc->addRecipient($tr, $nioc);
            $svc->issue($tr, $rezaei);
        }, $ph14->id);

        $first = $tenant->runAs(
            $rezaei->company_id,
            fn () => \App\Models\Transmittal::where('sequence', 1)->first(),
            $ph14->id,
        );

        if ($first !== null) {
            $tenant->runAs(
                $tehrani->company_id,
                fn () => $svc->acknowledge($first, $tehrani, 'Received and logged.'),
                $ph14->id,
            );
        }

        // 2) Issued for review, NOT acknowledged, backdated so it is overdue.
        $tenant->runAs($rezaei->company_id, function () use ($svc, $ph14, $rezaei, $pmc, $pick): void {
            $tr = $svc->draft($ph14, $rezaei, 'Isometrics rev B for review', 'ایزومتریک‌ها بازنگری B جهت بررسی', 'FOR_REVIEW');
            $svc->addItem($tr, $pick('PH-14-PIPING-DWG-0001'));
            $svc->addRecipient($tr, $pmc);
            $svc->issue($tr, $rezaei);

            // Backdate past the deadline. Written directly because the service
            // deliberately offers no way to alter an issued letter.
            $tr->forceFill([
                'issued_at' => now()->subDays(21),
                'due_at' => now()->subDays(7),
            ])->save();
        }, $ph14->id);

        // 3) Still a draft: nothing has been sent, so nothing is tracked.
        $tenant->runAs($rezaei->company_id, function () use ($svc, $ph14, $rezaei, $nioc, $pick): void {
            $tr = $svc->draft($ph14, $rezaei, 'Structural calculations', 'محاسبات سازه', 'FOR_APPROVAL');
            $svc->addItem($tr, $pick('PH-14-CIVIL-CALC-0001'));
            $svc->addRecipient($tr, $nioc);
        }, $ph14->id);

        /*
         * 4 and 5) The VENDOR stream (FR-ENG-05b). Two letters, not one: a
         * register with a single row demonstrates nothing about sorting or
         * filtering, and the browser harness will not exercise a table that
         * cannot be reordered.
         *
         * The EPC records these - the vendor does not log in - so the sender
         * is still EPC-PARS while VEN-AZAR is named as the originator.
         */
        $vendor = Company::where('code', 'VEN-AZAR')->first();

        if ($vendor === null) {
            return;
        }

        $tenant->runAs($rezaei->company_id, function () use ($svc, $ph14, $rezaei, $pmc, $vendor, $pick): void {
            $issued = $svc->draft(
                $ph14, $rezaei,
                'Pump general arrangement drawings', 'نقشه‌های کلی پمپ',
                'FOR_REVIEW', null, 'fa',
                \App\Models\Transmittal::VENDOR, $vendor, 'AZ-2026-114',
            );
            $svc->addItem($issued, $pick('PH-14-PIPING-DWG-0001'));
            $svc->addRecipient($issued, $pmc);
            $svc->issue($issued, $rezaei);

            // A second one left as a draft, so the vendor register shows both
            // states exactly as the contractor register does.
            $draft = $svc->draft(
                $ph14, $rezaei,
                'Mechanical seal datasheets', 'دیتاشیت مکانیکال سیل',
                'FOR_INFORMATION', null, 'fa',
                \App\Models\Transmittal::VENDOR, $vendor, 'AZ-2026-131',
            );
            $svc->addItem($draft, $pick('PH-14-ELEC-SPEC-0001'));
            $svc->addRecipient($draft, $pmc);
        }, $ph14->id);
    }

    /**
     * Attach demo files, going through the real store so the seeded rows are
     * indistinguishable from uploaded ones - same hashing, same content-
     * addressed paths, same allow-list. A seeder that writes its own rows
     * directly is a seeder that drifts away from the code under test.
     *
     * @param  list<string>  $files
     */
    private function seedFiles(Document $doc, DocumentRevision $revision, User $author, array $files): void
    {
        if ($files === []) {
            return;
        }

        $store = app(\App\Services\DocumentFileStore::class);
        $doc->setRelation('currentRevision', $revision);

        foreach ($files as $name) {
            $tmp = tempnam(sys_get_temp_dir(), 'seed');
            // Placeholder bytes, made unique per file so each gets its own
            // hash rather than collapsing into one shared blob.
            file_put_contents($tmp, sprintf(
                "%%PDF-1.4 EPCC demo placeholder for %s (%s)\n",
                $name,
                $doc->number,
            ));

            $store->attach($doc, $author, new \Illuminate\Http\UploadedFile(
                $tmp,
                $name,
                null,
                null,
                true,
            ));
        }
    }

    /**
     * Journey 5 fixture: one subsystem walked down by the MC body, carrying a
     * mix of punch items that exercises the gate from both sides.
     *
     * Deliberately shaped so the MC certificate is BLOCKED as seeded: two open
     * A-items. A fixture where the gate already passes proves nothing, and a
     * demo that opens on a green screen never shows the feature that was sold.
     */
    private function seedPunchList(Project $ph14): void
    {
        $tenant = app(TenantContext::class);
        $punch = app(\App\Services\PunchListService::class);

        $jafari = $tenant->forIdentityLookup(fn () => User::where('email', 'jafari@karan.example')->first());
        $rezaei = $tenant->forIdentityLookup(fn () => User::where('email', 'rezaei@pars.example')->first());
        $karimi = $tenant->forIdentityLookup(fn () => User::where('email', 'karimi@nioc.example')->first());
        $mcBody = Company::where('code', 'MC-KARAN')->first();
        $pars = Company::where('code', 'EPC-PARS')->first();

        if (! $jafari || ! $rezaei || ! $karimi || ! $mcBody || ! $pars) {
            return;
        }

        // The subsystem belongs to the contractor that has to deliver it.
        $subsystem = $tenant->runAs($pars->id, fn () => Subsystem::create([
            'project_id' => $ph14->id,
            'company_id' => $pars->id,
            'code' => 'PH-14-SS-2201',
            'name_en' => 'Cooling water pump set P-2201 A/B',
            'name_fa' => 'مجموعه پمپ آب خنک‌کننده P-2201 A/B',
            'discipline' => 'MECHANICAL',
        ]), $ph14->id);

        // The MC body walks the system down and raises the items.
        $raised = $tenant->runAs($mcBody->id, function () use ($punch, $subsystem, $jafari) {
            $items = [];

            $items['a1'] = $punch->raise(
                $subsystem, $jafari, PunchCategory::A,
                'Pump P-2201A coupling guard missing.',
                'حفاظ کوپلینگ پمپ P-2201A نصب نشده است.',
                'Unit 220 / pump bay', 'en',
            );

            $items['a2'] = $punch->raise(
                $subsystem, $jafari, PunchCategory::A,
                'Earthing conductor on motor M-2201B not terminated.',
                'اتصال زمین موتور M-2201B بسته نشده است.',
                'Unit 220 / MCC', 'en',
            );

            $items['a3'] = $punch->raise(
                $subsystem, $jafari, PunchCategory::A,
                'Relief valve PSV-2201 tag plate illegible.',
                'پلاک شناسایی شیر اطمینان PSV-2201 ناخوانا است.',
                'Unit 220 / discharge header', 'en',
            );

            $items['b1'] = $punch->raise(
                $subsystem, $jafari, PunchCategory::B,
                'Insulation cladding dented on suction line.',
                'روکش عایق خط مکش فرورفتگی دارد.',
                'Unit 220 / suction line', 'en',
            );

            $items['c1'] = $punch->raise(
                $subsystem, $jafari, PunchCategory::C,
                'Touch-up painting required on pump baseplate.',
                'رنگ‌آمیزی ترمیمی صفحهٔ زیر پمپ لازم است.',
                'Unit 220 / pump bay', 'en',
            );

            return $items;
        }, $ph14->id);

        // All of them land on the contractor.
        $tenant->runAs($mcBody->id, function () use ($punch, $raised, $pars) {
            foreach ($raised as $item) {
                $punch->assign($item, $pars, 'en');
            }
        }, $ph14->id);

        // The contractor fixes the tag plate and the cosmetic item.
        $tenant->runAs($pars->id, function () use ($punch, $raised, $rezaei) {
            $punch->markFixed($raised['a3']->refresh(), $rezaei, 'New engraved tag plate fitted and photographed.', 'en');
            $punch->markFixed($raised['c1']->refresh(), $rezaei, 'Baseplate repainted to spec.', 'en');
        }, $ph14->id);

        // The MC body verifies both.
        $tenant->runAs($mcBody->id, function () use ($punch, $raised, $jafari) {
            $punch->verify($raised['a3']->refresh(), $jafari, true, 'Tag legible, matches P&ID.', 'en');
            $punch->verify($raised['c1']->refresh(), $jafari, true, 'Finish acceptable.', 'en');
        }, $ph14->id);

        // The employer closes them. Note only the OWNER can do this, so the
        // A-item count drops to two - still blocking.
        $tenant->runAs($karimi->company_id, function () use ($punch, $raised, $karimi) {
            $punch->close($raised['a3']->refresh(), $karimi, 'en');
            $punch->close($raised['c1']->refresh(), $karimi, 'en');
        }, $ph14->id);

        // A draft certificate exists and is BLOCKED by the two remaining
        // A-items. Issuing it is the demo.
        $tenant->runAs($mcBody->id, function () use ($subsystem, $jafari) {
            app(\App\Services\McCertificateService::class)->draft($subsystem, $jafari, 'en');
        }, $ph14->id);

        $this->seedAdditionalSubsystems($ph14, $pars, $punch, $jafari, $rezaei, $karimi, $mcBody, $tenant);
    }

    /**
     * Two more subsystems, so the register is a register rather than a row.
     *
     * WHY THE FIXTURE NEEDED THIS. With a single subsystem, every state the MC
     * gate can be in looks identical on screen: one row, blocked. A reviewer
     * cannot tell a working "blocked" badge from a hard-coded one, sorting and
     * filtering have nothing to act on, and the jsdom harnesses skip tables
     * with fewer than two rows outright. The three rows now cover the three
     * outcomes that matter: BLOCKED by open A-items, CLEAR with everything
     * closed, and CLEAR because nothing was ever raised.
     *
     * Appended AFTER the original so that the nine existing tests calling
     * `Subsystem::first()` - which pass no ordering - keep getting
     * PH-14-SS-2201. That is a fragile thing for them to rely on and it is
     * flagged in HANDOFF; changing them is a separate job from this one.
     */
    private function seedAdditionalSubsystems(
        Project $ph14,
        Company $pars,
        \App\Services\PunchListService $punch,
        User $jafari,
        User $rezaei,
        User $karimi,
        Company $mcBody,
        TenantContext $tenant,
    ): void {
        // 1. Electrical: punch items raised and ALL closed -> gate is clear.
        $electrical = $tenant->runAs($pars->id, fn () => Subsystem::create([
            'project_id' => $ph14->id,
            'company_id' => $pars->id,
            'code' => 'PH-14-SS-3301',
            'name_en' => 'Switchgear room SG-3301',
            'name_fa' => 'اتاق تابلو برق SG-3301',
            'discipline' => 'ELECTRICAL',
        ]), $ph14->id);

        $raised = $tenant->runAs($mcBody->id, function () use ($punch, $electrical, $jafari) {
            return [
                'a' => $punch->raise(
                    $electrical, $jafari, PunchCategory::A,
                    'Earthing bond missing on panel SG-3301-02.',
                    'اتصال زمین روی تابلو SG-3301-02 نصب نشده است.',
                ),
                'b' => $punch->raise(
                    $electrical, $jafari, PunchCategory::B,
                    'Cable tray labels not applied.',
                    'برچسب سینی کابل نصب نشده است.',
                ),
            ];
        }, $ph14->id);

        // Walk both items the full RAISED -> ASSIGNED -> FIXED -> VERIFIED ->
        // CLOSED route, each step performed by the party entitled to it: the
        // contractor fixes, the MC body verifies, only the employer closes.
        $tenant->runAs($mcBody->id, function () use ($punch, $raised, $pars) {
            $punch->assign($raised['a']->refresh(), $pars, 'en');
            $punch->assign($raised['b']->refresh(), $pars, 'en');
        }, $ph14->id);

        $tenant->runAs($pars->id, function () use ($punch, $raised, $rezaei) {
            $punch->markFixed($raised['a']->refresh(), $rezaei, 'Bond installed and tested.', 'en');
            $punch->markFixed($raised['b']->refresh(), $rezaei, 'Labels applied.', 'en');
        }, $ph14->id);

        $tenant->runAs($mcBody->id, function () use ($punch, $raised, $jafari) {
            $punch->verify($raised['a']->refresh(), $jafari, true, '', 'en');
            $punch->verify($raised['b']->refresh(), $jafari, true, '', 'en');
        }, $ph14->id);

        $tenant->runAs($karimi->company_id, function () use ($punch, $raised, $karimi) {
            $punch->close($raised['a']->refresh(), $karimi, 'en');
            $punch->close($raised['b']->refresh(), $karimi, 'en');
        }, $ph14->id);

        // 2. Instrument: one category-A item walked to VERIFIED and STOPPED
        //    there. This is the state that separates the inspector's opinion
        //    from the owner's record: the MC body has verified the fix, but
        //    only the employer closes, and until they do the item is still
        //    open and the MC gate is still shut. Without a row in this state
        //    a register that treats VERIFIED as cleared looks correct on
        //    every other row in the fixture.
        $instrument = $tenant->runAs($pars->id, fn () => Subsystem::create([
            'project_id' => $ph14->id,
            'company_id' => $pars->id,
            'code' => 'PH-14-SS-5501',
            'name_en' => 'Metering skid MS-5501',
            'name_fa' => 'اسکید اندازه‌گیری MS-5501',
            'discipline' => 'INSTRUMENT',
        ]), $ph14->id);

        $pending = $tenant->runAs($mcBody->id, fn () => $punch->raise(
            $instrument, $jafari, PunchCategory::A,
            'Flow transmitter FT-5501 not calibrated.',
            'ترانسمیتر جریان FT-5501 کالیبره نشده است.',
        ), $ph14->id);

        $tenant->runAs($mcBody->id, function () use ($punch, $pending, $pars) {
            $punch->assign($pending->refresh(), $pars, 'en');
        }, $ph14->id);

        $tenant->runAs($pars->id, function () use ($punch, $pending, $rezaei) {
            $punch->markFixed($pending->refresh(), $rezaei, 'Calibrated against reference standard.', 'en');
        }, $ph14->id);

        $tenant->runAs($mcBody->id, function () use ($punch, $pending, $jafari) {
            $punch->verify($pending->refresh(), $jafari, true, 'Calibration certificate sighted.', 'en');
        }, $ph14->id);
        // Deliberately NOT closed - the employer has not accepted it yet.

        // 3. Piping: one OPEN category-B item and nothing else. Category B
        //    is punched work that does not stop Mechanical Completion - it is
        //    carried into the handover as an outstanding item. A register that
        //    blocks on any open punch item rather than on category A alone
        //    would hold up a subsystem that is contractually ready, and there
        //    is no other row in the fixture that can tell the two rules apart.
        $piping = $tenant->runAs($pars->id, fn () => Subsystem::create([
            'project_id' => $ph14->id,
            'company_id' => $pars->id,
            'code' => 'PH-14-SS-6601',
            'name_en' => 'Condensate line 6"-CD-6601',
            'name_fa' => 'خط میعانات ۶ اینچ CD-6601',
            'discipline' => 'PIPING',
        ]), $ph14->id);

        $minor = $tenant->runAs($mcBody->id, fn () => $punch->raise(
            $piping, $jafari, PunchCategory::B,
            'Insulation cladding dented near support S-12.',
            'روکش عایق نزدیک ساپورت S-12 فرورفتگی دارد.',
        ), $ph14->id);

        $tenant->runAs($mcBody->id, function () use ($punch, $minor, $pars) {
            $punch->assign($minor->refresh(), $pars, 'en');
        }, $ph14->id);
        // Left open on purpose: open, but not blocking.

        // 4. Civil: never walked down, so no punch items at all. closurePercent()
        //    returns 100 for an empty list, which is the case worth showing -
        //    "100%" here means "nothing inspected yet", not "finished", and a
        //    register that cannot tell those apart is misleading on handover.
        $tenant->runAs($pars->id, fn () => Subsystem::create([
            'project_id' => $ph14->id,
            'company_id' => $pars->id,
            'code' => 'PH-14-SS-4401',
            'name_en' => 'Firewater tank foundation TK-4401',
            'name_fa' => 'فونداسیون مخزن آب آتش‌نشانی TK-4401',
            'discipline' => 'CIVIL',
        ]), $ph14->id);
    }
}
