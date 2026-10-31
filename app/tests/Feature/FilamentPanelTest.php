<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Filament\Resources\Documents\DocumentResource;
use App\Filament\Resources\PunchItems\PunchItemResource;
use App\Filament\Resources\Transmittals\TransmittalResource;
use App\Filament\Resources\Subsystems\SubsystemResource;
use App\Filament\Resources\Users\UserResource;
use App\Services\DocumentRegister;
use App\Support\TenantContext;
use Livewire\Livewire;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Support\LocalisedNumberTranslator;

/**
 * The Filament admin panel, and specifically whether it is TENANT-SAFE.
 *
 * WHY THIS TEST EXISTS AND WHY IT CAME FIRST
 * ------------------------------------------
 * Standing rule 1 (docs/Spike-RLS-Results.md): `SET LOCAL` outside a
 * transaction is silently discarded by PostgreSQL, so tenancy fails OPEN.
 * Our own screens are safe because the `tenant` middleware wraps every request
 * in a transaction and binds the company.
 *
 * A stock Filament panel does NOT have that middleware. Filament builds its
 * own middleware stack in the panel provider, and the generated stack contains
 * EncryptCookies / StartSession / Authenticate and nothing about tenancy. So
 * before building any admin CRUD on top of it, the question that decides
 * whether the panel is usable at all is: does a panel request see other
 * companies' rows?
 *
 * These tests were written BEFORE the panel was wired up, and the isolation
 * test failed exactly as predicted - the panel listed every document in the
 * database regardless of the signed-in user's company. See §5i in HANDOFF.md.
 */
final class FilamentPanelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
    }

    private function user(string $email): User
    {
        return $this->userByEmail($email);
    }

    public function test_the_panel_requires_authentication(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_an_authenticated_user_reaches_the_dashboard(): void
    {
        $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin')
            ->assertSuccessful();
    }

    /**
     * A deactivated account must not open the admin shell.
     *
     * Gate 1 of the authorization pipeline already refuses `former@pars` a
     * LOGIN. This is the separate question of whether an account that is
     * deactivated while holding a live session keeps the panel - and Filament
     * asks a different question (`canAccessPanel`) than our login path does,
     * so the answer has to be given twice.
     *
     * Found by mutation M79: replacing the body of canAccessPanel() with
     * `return true` left the entire suite green. An admin panel that opens for
     * a dismissed employee is exactly the finding an on-prem security review
     * would lead with.
     */
    public function test_a_deactivated_account_cannot_open_the_panel(): void
    {
        $this->actingAs($this->user('former@pars.example'))
            ->get('/admin')
            ->assertForbidden();
    }

    /**
     * THE LOAD-BEARING TEST.
     *
     * EPC-PARS and EPC-SAHAND are both contractors on PH-14 but are different
     * tenants. A document owned by SAHAND must not appear in a register that
     * PARS is reading, and vice versa - that is what RLS is for, and the whole
     * multi-tenant claim of the product rests on it holding on EVERY surface,
     * not just the ones we hand-wrote.
     */
    public function test_the_document_list_does_not_leak_across_tenants(): void
    {
        $tenant = app(TenantContext::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $sahand = Company::where('code', 'EPC-SAHAND')->firstOrFail();

        // A document that belongs to SAHAND, created as SAHAND through the
        // real register service - so the row is exactly what the application
        // would produce, numbering rules included.
        $secret = $tenant->runAs(
            $sahand->id,
            fn () => app(DocumentRegister::class)->register(
                $project,
                $this->user('mousavi@sahand.example'),
                [
                    'discipline' => 'PROCESS',
                    'doc_type' => 'SPEC',
                    'title_en' => 'Sahand confidential specification',
                    'title_fa' => 'مشخصات محرمانه سهند',
                ],
            ),
            $project->id,
        );

        // Read the panel as a PARS user.
        $response = $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin/documents');

        $response->assertSuccessful();
        $response->assertDontSee($secret->number);
        $response->assertDontSee('Sahand confidential specification');
    }

    /**
     * Every column must actually render a value.
     *
     * Found by LOOKING at the panel, not by a test: the state column was
     * bound to `currentRevision.state`, but the column is `cde_state`, so
     * Filament resolved null and rendered an empty cell for every row. The
     * page returned 200, the tenancy tests passed, and the register silently
     * lost the field that says whether a drawing may be built from.
     *
     * A status-code assertion cannot see this. Assert on CONTENT for any
     * column whose value comes through a relation, because a renamed or
     * mistyped attribute fails silently rather than loudly.
     */
    public function test_the_state_column_renders_a_real_value(): void
    {
        $html = $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin/documents')
            ->assertSuccessful()
            ->getContent();

        // The fixture has a published, a shared and an archived document, so
        // at least one translated state label must appear on the page.
        $this->assertTrue(
            str_contains($html, 'منتشر شده')
            || str_contains($html, 'ارسال برای بررسی')
            || str_contains($html, 'بایگانی شده'),
            'No CDE state label rendered in the admin document table.',
        );
    }

    /**
     * AIR-GAP: the panel must not reach for anything on the public internet.
     *
     * AG-01..AG-06 in the Iran-first addendum are not preferences. A customer
     * datacentre has no outbound route, so a stylesheet from Google Fonts does
     * not degrade - it hangs and then renders the panel in a fallback font
     * with the wrong metrics, on the machine of a customer who has already
     * paid. Filament's ->font() helper uses Google by DEFAULT, so this is a
     * live foot-gun rather than a hypothetical one.
     */
    public function test_the_panel_loads_nothing_from_the_public_internet(): void
    {
        $html = $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin')
            ->assertSuccessful()
            ->getContent();

        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'fonts.bunny.net', 'cdn.jsdelivr.net', 'unpkg.com'] as $host) {
            $this->assertStringNotContainsString(
                $host,
                $html,
                "The admin panel references {$host}, which breaks the air-gap requirement.",
            );
        }
    }

    /**
     * The admin form must not expose the controlled identity fields.
     *
     * `DocumentRegister::register()` DERIVES number, sequence and company_id -
     * the number from the project's discipline/type counter, the company from
     * the acting user. The generated scaffold offered all three as free inputs,
     * which would let an administrator type PH-14-CIVIL-DWG-0001 over an
     * existing drawing's number, or re-assign a document to another company
     * and move it out of its own tenant's sight.
     *
     * The register is a CONTROLLED document register; its numbering is
     * contractual. A field that can be retyped is not controlled.
     */
    public function test_the_form_does_not_expose_controlled_identity_fields(): void
    {
        $source = file_get_contents(
            base_path('app/Filament/Resources/Documents/Schemas/DocumentForm.php')
        );
        $this->assertIsString($source);

        foreach (['number', 'sequence', 'company_id', 'current_revision_id'] as $field) {
            $this->assertStringNotContainsString(
                "make('{$field}')",
                $source,
                "The admin form exposes '{$field}', which DocumentRegister derives.",
            );
        }
    }

    /**
     * Nothing in the admin panel may delete or create a controlled document.
     *
     * Revisions supersede and states archive - that is ISO 19650 and the
     * reason DocumentRegister archives rather than overwrites. The scaffold
     * shipped a bulk delete on the table, a per-record DeleteAction on the
     * edit page, and a create page; removing only one leaves the capability
     * intact one click deeper.
     *
     * Asserted through the RESOURCE'S OWN AUTHORIZATION, not by grepping the
     * source. The first version of this test searched the files for the string
     * "DeleteAction" and failed on the code comment explaining why there is no
     * DeleteAction - a grep cannot tell code from prose, and would equally
     * have missed a delete introduced under a different name.
     */
    public function test_the_document_resource_cannot_create_or_delete(): void
    {
        // Fetch inside a bound tenant: with no GUC set, RLS correctly returns
        // nothing and firstOrFail() throws (standing rule 1 doing its job).
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $document = app(TenantContext::class)->runAs(
            $pars->id,
            fn () => \App\Models\Document::query()->firstOrFail(),
        );

        $this->assertFalse(
            DocumentResource::canCreate(),
            'The admin panel offers document creation, bypassing DocumentRegister.',
        );
        $this->assertFalse(
            DocumentResource::canDelete($document),
            'The admin panel can delete a controlled document.',
        );
        $this->assertFalse(
            DocumentResource::canDeleteAny(),
            'The admin panel can bulk-delete controlled documents.',
        );
    }

    /** The create ROUTE must not exist either - canCreate() alone still leaves it registered. */
    public function test_the_document_create_page_is_not_routable(): void
    {
        $this->assertArrayNotHasKey(
            'create',
            DocumentResource::getPages(),
            'A create page is still routed for the document resource.',
        );
    }

    /**
     * The mirror: the panel must still show the tenant its OWN rows. An
     * isolation fix that shows nothing to anybody would pass the test above
     * while making the panel useless.
     */
    public function test_the_document_list_still_shows_the_users_own_rows(): void
    {
        $tenant = app(TenantContext::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();

        $mine = $tenant->runAs(
            $pars->id,
            fn () => app(DocumentRegister::class)->register(
                $project,
                $this->user('rezaei@pars.example'),
                [
                    'discipline' => 'PROCESS',
                    'doc_type' => 'SPEC',
                    'title_en' => 'Pars own specification',
                    'title_fa' => 'مشخصات پارس',
                ],
            ),
            $project->id,
        );

        $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin/documents')
            ->assertSuccessful()
            ->assertSee($mine->number);
    }

    /**
     * The punch register is READ-ONLY in the admin panel, and more strictly
     * than the document register.
     *
     * A punch item's state is not a column, it is a position in a workflow
     * with rules about who may move it: the responsible contractor fixes,
     * anyone BUT the responsible company verifies, only the employer closes.
     * A Filament form writing `state` directly walks past all of them - an
     * admin could mark an item VERIFIED with nobody having verified it, on a
     * register whose whole purpose is to prove somebody did.
     *
     * The MC gate reads this table, so a forged state also unblocks a
     * Mechanical Completion certificate.
     */
    public function test_the_punch_resource_is_entirely_read_only(): void
    {
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $item = app(TenantContext::class)->runAs(
            $pars->id,
            fn () => \App\Models\PunchItem::query()->firstOrFail(),
        );

        $this->assertFalse(PunchItemResource::canCreate(), 'Admin can raise punch items outside the walkdown workflow.');
        $this->assertFalse(PunchItemResource::canEdit($item), 'Admin can edit a punch item, bypassing PunchState transitions.');
        $this->assertFalse(PunchItemResource::canDelete($item), 'Admin can delete a punch item.');
        $this->assertFalse(PunchItemResource::canDeleteAny(), 'Admin can bulk-delete punch items.');

        $pages = PunchItemResource::getPages();
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_the_punch_register_renders_with_its_workflow_vocabulary(): void
    {
        $html = $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin/punch-items')
            ->assertSuccessful()
            ->getContent();

        // A seeded item exists and its state label is translated, not raw.
        $this->assertMatchesRegularExpression('/ثبت‌شده|ارجاع‌شده|رفع‌شده|تأیید بازرسی|بسته‌شده|رد شده/u', $html);
    }

    /**
     * The punch table must be tenant-scoped like everything else. Punch READS
     * are deliberately open to every project participant (a walkdown is a
     * shared activity), so the thing to prove here is the project/participant
     * boundary, not company ownership: a non-participant sees nothing.
     */
    public function test_a_non_participant_sees_no_punch_items(): void
    {
        // hosseini@arvand belongs to EPC-ARVAND, which is not on PH-14.
        $html = $this->actingAs($this->user('hosseini@arvand.example'))
            ->get('/admin/punch-items')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringNotContainsString('PH-14-PL-', $html);
    }

    /**
     * Transmittals are read-only in the panel, for a sharper reason than the
     * punch list: an ISSUED transmittal is IMMUTABLE. It evidences what was
     * sent, to whom, on what date. TransmittalService::issue() freezes it and
     * the register refuses edits; a Filament form would write straight past
     * that with a plain Eloquent update.
     *
     * The number also comes from a per-stream counter (TR / VTR) taken under a
     * row lock. Retyping it collides with the unique index and silently
     * perforates a contractual series - a gap at PH-14-TR-0007 is the first
     * thing an auditor asks about.
     */
    public function test_the_transmittal_resource_is_entirely_read_only(): void
    {
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $letter = app(TenantContext::class)->runAs(
            $pars->id,
            fn () => \App\Models\Transmittal::query()->firstOrFail(),
        );

        $this->assertFalse(TransmittalResource::canCreate());
        $this->assertFalse(TransmittalResource::canEdit($letter));
        $this->assertFalse(TransmittalResource::canDelete($letter));
        $this->assertFalse(TransmittalResource::canDeleteAny());

        $pages = TransmittalResource::getPages();
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    /**
     * ONE admin resource shows BOTH streams.
     *
     * The hand-written UI splits them deliberately - a document controller
     * working the contractual log does not want supply-chain letters in their
     * numbering. The admin view has the opposite job: every letter on the
     * project, with `kind` as a filter. This test pins that difference,
     * because "the vendor letters vanished from the admin list" and "the
     * vendor letters leaked into the contractual register" are both regressions
     * and they look identical from a status code.
     */
    public function test_the_admin_list_shows_both_streams_together(): void
    {
        $html = $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin/transmittals')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('PH-14-TR-', $html, 'Contractor letters missing from the admin list.');
        $this->assertStringContainsString('PH-14-VTR-', $html, 'Vendor letters missing from the admin list.');
    }

    /**
     * The employer must NOT see vendor supply-chain letters here either.
     *
     * The RLS policy was narrowed to `kind = CONTRACTOR` for the employer's
     * blanket visibility clause (migration 2026_09_11_130000). That fix lives
     * in PostgreSQL, so it should hold on a surface written months later
     * without anyone remembering it - which is exactly the claim worth testing.
     */
    public function test_the_employer_sees_no_vendor_letters_in_the_admin_list(): void
    {
        $html = $this->actingAs($this->user('karimi@nioc.example'))
            ->get('/admin/transmittals')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringNotContainsString('PH-14-VTR-', $html);
    }

    /**
     * Dates in the panel are JALALI, like everywhere else in the product.
     *
     * Filament's ->dateTime() renders Gregorian. The transmittal list shipped
     * with it and showed "سپتامبر 12, 2026" on a page whose every other string
     * was Persian. On a submission log that is not cosmetic: the response
     * deadline is contractual, and a reader who misreads the calendar misreads
     * whether a letter is late.
     *
     * Caught by looking at a screenshot. Pinned here because the fix is a
     * per-column formatter that is easy to forget on the next column added.
     */
    public function test_panel_dates_are_jalali_not_gregorian(): void
    {
        $html = $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/admin/transmittals')
            ->assertSuccessful()
            ->getContent();

        // Gregorian month names are what Filament emits by default.
        foreach (['ژانویه', 'سپتامبر', 'اکتبر', 'دسامبر', 'January', 'September'] as $month) {
            $this->assertStringNotContainsString($month, $html, "A Gregorian month name leaked into the panel.");
        }

        // A Jalali year in Persian digits, e.g. ۱۴۰۵/۰۶/۲۱.
        $this->assertMatchesRegularExpression('/[۱۴][۰-۹]{3}\/[۰-۹]{2}\/[۰-۹]{2}/u', $html);
    }

    // ---------------- Users resource ----------------

    public function test_the_user_resource_is_entirely_read_only(): void
    {
        $user = $this->userByEmail('rezaei@pars.example');

        // is_active IS gate 1 of the pipeline and is also what
        // canAccessPanel() reads. An editable toggle here would let anyone who
        // can open /admin re-enable a terminated account - every door in the
        // product - without passing any of the six gates.
        $this->assertFalse(UserResource::canCreate(), 'An admin can provision accounts outside the team screen.');
        $this->assertFalse(UserResource::canEdit($user), 'An admin can edit a user, bypassing gate 1 and the audit trail.');
        $this->assertFalse(UserResource::canDelete($user), 'An admin can delete a user.');
        $this->assertFalse(UserResource::canDeleteAny(), 'An admin can bulk-delete users.');

        $pages = UserResource::getPages();
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_the_user_list_shows_colleagues_and_project_counterparties(): void
    {
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/users')
            ->assertSuccessful()
            ->getContent();

        // Own colleague.
        $this->assertStringContainsString('sadeghi@pars.example', $html);

        // Counterparties on PH-14: the employer, the consultant, the MC body.
        // These must be visible or "acknowledged by" and "reviewed by" render
        // blank names on working pages.
        $this->assertStringContainsString('karimi@nioc.example', $html);
        $this->assertStringContainsString('tehrani@pmc.example', $html);
    }

    public function test_the_user_list_does_not_leak_a_rival_contractors_staff(): void
    {
        // EPC-ARVAND shares no project with EPC-PARS. Its staff directory is
        // exactly what a competitor would want, and this is enforced in
        // PostgreSQL (migration 2026_09_12_100000), not in the resource -
        // mutation M91 proves it by removing the policy clause, not the view.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/users')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringNotContainsString('hosseini@arvand.example', $html,
            'A rival contractor\'s staff are listed in the admin panel.');
    }

    public function test_the_user_list_states_the_company_type_in_words(): void
    {
        // MC means Management Contracting / مدیریت پیمان. Reading it as
        // "Mechanical Completion" has already caused one round of wrong work
        // on this project, so the screen spells the type out.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/users?lang=fa')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('مدیریت پیمان', $html);
        $this->assertStringNotContainsString('تکمیل مکانیکی', $html);
    }

    // ---------------- Subsystems resource ----------------

    public function test_the_subsystem_resource_is_entirely_read_only(): void
    {
        $pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        $subsystem = app(TenantContext::class)->runAs(
            $pars->id,
            fn () => \App\Models\Subsystem::query()->firstOrFail(),
        );

        $this->assertFalse(SubsystemResource::canCreate(), 'Subsystems can be hand-typed instead of imported.');
        $this->assertFalse(SubsystemResource::canEdit($subsystem), 'The handover join key is editable.');
        $this->assertFalse(SubsystemResource::canDelete($subsystem), 'A subsystem with punch items can be deleted.');
        $this->assertFalse(SubsystemResource::canDeleteAny());

        $pages = SubsystemResource::getPages();
        $this->assertArrayNotHasKey('create', $pages);
        $this->assertArrayNotHasKey('edit', $pages);
    }

    public function test_the_register_shows_the_mc_gate_state_per_subsystem(): void
    {
        // The fixture deliberately carries all three outcomes: SS-2201 has two
        // open A-items, SS-3301 had items and closed them all, SS-4401 was
        // never walked down. A register that renders them identically is
        // useless at a handover meeting, which is the only place it is read.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/subsystems?lang=en')
            ->assertSuccessful()
            ->getContent();

        foreach (['PH-14-SS-2201', 'PH-14-SS-3301', 'PH-14-SS-4401'] as $code) {
            $this->assertStringContainsString($code, $html);
        }

        $this->assertStringContainsString('Blocked', $html, 'No subsystem is shown as blocked.');
        $this->assertStringContainsString('Clear', $html, 'No subsystem is shown as clear.');
    }

    public function test_an_uninspected_subsystem_does_not_claim_to_be_complete(): void
    {
        // closurePercent() returns 100 for an empty punch list. On a handover
        // register "100%" beside a subsystem nobody has inspected reads as
        // finished, when it means the opposite.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/subsystems?lang=en')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('Not walked down', $html,
            'An un-inspected subsystem is not distinguished from a completed one.');
    }

    public function test_the_gate_counts_verified_items_as_still_blocking(): void
    {
        // VERIFIED is the inspector's opinion; CLOSED is the owner's record.
        // Only the employer closes, so a verified A-item still shuts the gate.
        //
        // This asserts on the RENDERED ROW, not on PunchState::isOpen(). An
        // earlier version of this test checked the model helper and passed
        // happily while the table itself listed only RAISED/ASSIGNED/FIXED as
        // open - the table can disagree with the domain, and that disagreement
        // is the whole defect. PH-14-SS-5501 exists for exactly this: its one
        // A-item is verified and not yet closed.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/subsystems?lang=en')
            ->assertSuccessful()
            ->getContent();

        $row = $this->subsystemRow($html, 'PH-14-SS-5501');

        $this->assertStringContainsString('Blocked', $row,
            'A subsystem whose only A-item is VERIFIED but not CLOSED is shown as ready for MC.');
        $this->assertStringNotContainsString('Clear', $row);
    }

    /** Isolate one subsystem's row so a badge from another row cannot satisfy the assertion. */
    private function subsystemRow(string $html, string $code): string
    {
        $start = strpos($html, $code);
        $this->assertNotFalse($start, "Subsystem {$code} is missing from the register.");

        $end = strpos($html, '</tr>', $start);

        return substr($html, $start, ($end === false ? strlen($html) : $end) - $start);
    }

    public function test_a_non_blocking_punch_item_does_not_shut_the_gate(): void
    {
        // Only category A blocks Mechanical Completion. A category-B item is
        // carried into handover as outstanding work, so PH-14-SS-6601 - which
        // has exactly one open B-item and nothing else - must read as clear.
        // Blocking on "any open punch item" would hold up a subsystem that is
        // contractually ready to hand over.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/subsystems?lang=en')
            ->assertSuccessful()
            ->getContent();

        $row = $this->subsystemRow($html, 'PH-14-SS-6601');

        $this->assertStringContainsString('Clear', $row,
            'An open category-B item is blocking Mechanical Completion.');
        $this->assertStringNotContainsString('Blocked', $row);
    }

    public function test_a_draft_certificate_is_not_displayed_as_a_neutral_fact(): void
    {
        // PH-14-MCC-0001 is a DRAFT. Draft and issued are not the same claim -
        // one is a proposal, the other is the evidence a handover happened -
        // so the badge has to carry a state colour. Reading the column as
        // `status` (it is `state`) yields NULL and renders a grey badge that
        // silently equates the two. Same defect class as M81.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/subsystems?lang=en')
            ->assertSuccessful()
            ->getContent();

        $row = $this->subsystemRow($html, 'PH-14-SS-2201');

        $this->assertStringContainsString('PH-14-MCC-0001', $row);
        $this->assertStringContainsString('fi-color-warning', $row,
            'A DRAFT certificate is rendered without its state colour.');
    }

    /** @return list<array{0: string}> */
    public static function panelResourceProvider(): array
    {
        return [['documents'], ['punch-items'], ['transmittals'], ['users'], ['subsystems']];
    }

    #[DataProvider('panelResourceProvider')]
    public function test_persian_pages_do_not_mix_digit_systems(string $resource): void
    {
        // Found by screenshot, not by a test: our columns rendered ۵ and ۴۰٪
        // while Filament's own pagination rendered "نمایش 1 به 5 از 5 نتیجه".
        // Latin numerals inside a Persian sentence are immediately visible to
        // a reader and invisible to an assertion that only checks the page
        // loaded. Every resource is covered, because the defect was panel-wide
        // and a per-table fix would let the next table reintroduce it.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get("/admin/{$resource}?lang=fa")
            ->assertSuccessful()
            ->getContent();

        $summary = [];
        preg_match('/نمایش[^<]*/u', $html, $summary);

        $this->assertNotEmpty($summary, "no pagination summary rendered for {$resource}");
        $this->assertDoesNotMatchRegularExpression('/[0-9]/', $summary[0],
            "the Persian pagination summary for {$resource} contains Latin digits: {$summary[0]}");
    }

    public function test_the_translator_converts_counts_but_not_identifiers(): void
    {
        // Tested against the translator directly, not through a page.
        //
        // The ctype_digit() guard is what stops PH-14-SS-2201 becoming
        // PH-۱۴-SS-۲۲۰۱ when a tag is passed as a :parameter. No string
        // currently rendered by the panel exercises it - a mutation removing
        // the guard leaves every page byte-identical - so an HTTP test for it
        // would pass whether the guard existed or not. This asserts the class
        // contract instead, which is the thing that actually holds the line
        // the first time someone writes :code or :tag into a message.
        $translator = app('translator');

        $this->assertInstanceOf(LocalisedNumberTranslator::class, $translator,
            'the digit-localising translator is not the bound one');

        $translator->setLocale('fa');
        $translator->addLines(['probe.count' => 'تعداد :n مورد', 'probe.tag' => 'شناسه :code'], 'fa');

        $this->assertSame('تعداد ۱۲ مورد', $translator->get('probe.count', ['n' => 12]),
            'a numeric parameter was not localised');
        $this->assertSame('شناسه PH-14-SS-2201', $translator->get('probe.tag', ['code' => 'PH-14-SS-2201']),
            'an identifier was mangled into Persian digits');

        $translator->setLocale('en');
        $translator->addLines(['probe.count' => ':n items'], 'en');
        $this->assertSame('12 items', $translator->get('probe.count', ['n' => 12]),
            'English output picked up Persian digits');
    }

    public function test_localising_digits_does_not_corrupt_identifiers(): void
    {
        // The counterweight to the test above. Equipment tags and document
        // numbers are read off drawings and typed into search boxes in Latin;
        // rendering PH-۱۴-SS-۲۲۰۱ would make them unsearchable and wrong. Only
        // wholly-numeric translation parameters are converted.
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/subsystems?lang=fa')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringContainsString('PH-14-SS-2201', $html);
        $this->assertStringNotContainsString('PH-۱۴-SS-۲۲۰۱', $html);
    }

    public function test_english_pages_keep_latin_digits(): void
    {
        $html = $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->get('/admin/subsystems?lang=en')
            ->assertSuccessful()
            ->getContent();

        // Six subsystems since the ADR-004 amendment: the sixth is the
        // firewater pump house, which exists so the demo can show a system
        // spanning two facilities. The subject here is the digits.
        $this->assertMatchesRegularExpression('/Showing\s+1\s+to\s+6/', $html,
            'English pagination picked up Persian digits.');
    }

    public function test_the_subsystem_register_does_not_leak_across_tenants(): void
    {
        // hosseini@arvand is on KISH-1 and shares no project with PARS.
        $html = $this->actingAs($this->userByEmail('hosseini@arvand.example'))
            ->get('/admin/subsystems')
            ->assertSuccessful()
            ->getContent();

        $this->assertStringNotContainsString('PH-14-SS-2201', $html,
            'A non-participant can read another project\'s handover register.');
    }
}
