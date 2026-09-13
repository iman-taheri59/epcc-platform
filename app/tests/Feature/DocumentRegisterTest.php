<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Support\CdeState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Document register / CDE (SRS FR-ENG-01/02/03/04).
 *
 * Three things are being protected here, in order of how expensive they are to
 * get wrong:
 *
 *   1. TENANT ISOLATION. A contractor must never see a rival's deliverables on
 *      a shared project, and the employer must see everyone's. Enforced by
 *      PostgreSQL RLS, so it is tested against PostgreSQL - never SQLite.
 *   2. THE STATE MACHINE. WIP -> PUBLISHED skips review entirely; a drawing
 *      reaching construction unchecked is the failure ISO 19650 exists to
 *      prevent. Enforced server-side, not merely hidden in the UI.
 *   3. ENTITLEMENT. `doc` is in the Starter plan, so it must work there - and
 *      a viewer must not be handed write controls.
 */
final class DocumentRegisterTest extends TestCase
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

    private function project(string $code): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    // ---- numbering (FR-ENG-02) -------------------------------------------

    public function test_document_numbers_are_segment_based_and_sequential(): void
    {
        $pars = $this->user('rezaei@pars.example');
        $ph14 = $this->project('PH-14');

        $doc = app(TenantContext::class)->runAs(
            $pars->company_id,
            fn () => app(DocumentRegister::class)->register($ph14, $pars, [
                'discipline' => 'MECH',
                'doc_type' => 'DWG',
                'title_en' => 'GA Drawing - Pump Skid',
                'title_fa' => 'نقشهٔ عمومی اسکید پمپ',
            ]),
            $ph14->id,
        );

        $this->assertSame('PH-14-MECH-DWG-0001', $doc->number);
    }

    public function test_the_sequence_restarts_per_discipline_and_type(): void
    {
        // Engineers expect CIVIL-DWG-0001 and PIPING-DWG-0001 to coexist; a
        // single global counter would produce numbers nobody can predict.
        $pars = $this->user('rezaei@pars.example');
        $ph14 = $this->project('PH-14');
        $register = app(DocumentRegister::class);

        app(TenantContext::class)->runAs($pars->company_id, function () use ($register, $ph14, $pars): void {
            // PIPING/DWG already has 0001 from the seeder.
            $this->assertSame(2, $register->nextSequence($ph14, 'PIPING', 'DWG'));
            // MECH/DWG has none.
            $this->assertSame(1, $register->nextSequence($ph14, 'MECH', 'DWG'));
        }, $ph14->id);
    }

    // ---- the CDE state machine (FR-ENG-03) -------------------------------

    public function test_a_new_document_starts_in_wip_with_no_suitability(): void
    {
        // A work-in-progress container is not suitable for anything yet, and
        // saying otherwise misleads whoever receives it.
        $pars = $this->user('rezaei@pars.example');
        $ph14 = $this->project('PH-14');

        $doc = app(TenantContext::class)->runAs(
            $pars->company_id,
            fn () => app(DocumentRegister::class)->register($ph14, $pars, [
                'discipline' => 'HVAC', 'doc_type' => 'CALC',
                'title_en' => 'Load calc', 'title_fa' => 'محاسبات بار',
            ]),
            $ph14->id,
        );

        $this->assertSame(CdeState::WIP, $doc->currentRevision->cde_state);
        $this->assertNull($doc->currentRevision->suitability);
    }

    public function test_wip_cannot_jump_straight_to_published(): void
    {
        // THE important one: skipping SHARED means skipping review.
        $this->assertFalse(CdeState::canTransition(CdeState::WIP, CdeState::PUBLISHED));
        $this->assertTrue(CdeState::canTransition(CdeState::WIP, CdeState::SHARED));
        $this->assertTrue(CdeState::canTransition(CdeState::SHARED, CdeState::PUBLISHED));
    }

    public function test_rejected_work_may_go_back_from_shared_to_wip(): void
    {
        // Review rejecting a drawing is normal, not an error state.
        $this->assertTrue(CdeState::canTransition(CdeState::SHARED, CdeState::WIP));
    }

    public function test_archived_is_terminal(): void
    {
        // Re-opening an archived revision rewrites history; issue a new
        // revision instead.
        $this->assertTrue(CdeState::isTerminal(CdeState::ARCHIVED));
        $this->assertSame([], CdeState::nextFrom(CdeState::ARCHIVED));
    }

    public function test_an_illegal_transition_is_refused_by_the_service(): void
    {
        $pars = $this->user('rezaei@pars.example');
        $ph14 = $this->project('PH-14');

        app(TenantContext::class)->runAs($pars->company_id, function () use ($ph14, $pars): void {
            $doc = Document::where('number', 'PH-14-CIVIL-DWG-0001')->firstOrFail();

            $this->expectException(ValidationException::class);

            app(DocumentRegister::class)->transition($doc, $pars, CdeState::PUBLISHED, 'A1', 'en');
        }, $ph14->id);
    }

    public function test_a_suitability_code_must_match_the_target_state(): void
    {
        // A1 is a PUBLISHED-family code; offering it on SHARED misrepresents
        // what the recipient is allowed to build from.
        $this->assertFalse(CdeState::allowsSuitability(CdeState::SHARED, 'A1'));
        $this->assertTrue(CdeState::allowsSuitability(CdeState::SHARED, 'S2'));
        // Anything past WIP must say what it is suitable for.
        $this->assertFalse(CdeState::allowsSuitability(CdeState::SHARED, null));
        $this->assertTrue(CdeState::allowsSuitability(CdeState::WIP, null));
    }

    public function test_the_http_endpoint_refuses_an_illegal_transition(): void
    {
        // The UI only offers legal transitions, but the UI is not a control.
        $pars = $this->user('rezaei@pars.example');
        $ph14 = $this->project('PH-14');

        $doc = app(TenantContext::class)->runAs(
            $pars->company_id,
            fn () => Document::where('number', 'PH-14-CIVIL-DWG-0001')->firstOrFail(),
            $ph14->id,
        );

        $this->actingAs($pars)
            ->post("/projects/PH-14/documents/{$doc->id}?lang=en", [
                'cde_state' => CdeState::PUBLISHED,
                'suitability' => 'A1',
            ])
            ->assertSessionHasErrors('state');

        $fresh = app(TenantContext::class)->runAs(
            $pars->company_id,
            fn () => Document::with('currentRevision')->find($doc->id),
            $ph14->id,
        );

        $this->assertSame(CdeState::WIP, $fresh->currentRevision->cde_state);
    }

    // ---- revisions (FR-ENG-04) -------------------------------------------

    public function test_a_new_revision_archives_the_previous_one(): void
    {
        // Superseding is a record, not an erasure.
        $pars = $this->user('rezaei@pars.example');
        $ph14 = $this->project('PH-14');

        app(TenantContext::class)->runAs($pars->company_id, function () use ($ph14, $pars): void {
            $doc = Document::with('currentRevision')
                ->where('number', 'PH-14-PIPING-PID-0001')->firstOrFail(); // PUBLISHED rev C

            $old = $doc->currentRevision;

            $new = app(DocumentRegister::class)->issueRevision($doc, $pars, 'D', null, 'en');

            $this->assertSame(CdeState::ARCHIVED, $old->refresh()->cde_state);
            $this->assertSame(CdeState::WIP, $new->cde_state);
            $this->assertSame('D', $doc->refresh()->currentRevision->revision);
            // History is kept, not overwritten.
            $this->assertSame(2, $doc->revisions()->count());
        }, $ph14->id);
    }

    public function test_a_revision_cannot_supersede_unpublished_work(): void
    {
        // The in-flight review would be silently discarded.
        $pars = $this->user('rezaei@pars.example');
        $ph14 = $this->project('PH-14');

        app(TenantContext::class)->runAs($pars->company_id, function () use ($ph14, $pars): void {
            $doc = Document::with('currentRevision')
                ->where('number', 'PH-14-CIVIL-DWG-0001')->firstOrFail(); // WIP

            $this->expectException(ValidationException::class);

            app(DocumentRegister::class)->issueRevision($doc, $pars, 'B', null, 'en');
        }, $ph14->id);
    }

    // ---- tenancy (ARC §5.1) ----------------------------------------------

    public function test_a_contractor_sees_shared_work_but_not_a_rivals_wip(): void
    {
        // This test originally asserted that Sahand saw NOTHING on PH-14,
        // which encoded the wrong model: it treated the register like private
        // commercial data. A common data environment is common by definition,
        // and the CDE STATE is the sharing boundary - not company ownership.
        // See migration 130000.
        $sahand = $this->user('mousavi@sahand.example');
        $ph14 = $this->project('PH-14');

        $docs = app(TenantContext::class)->runAs(
            $sahand->company_id,
            fn () => Document::with('currentRevision')->get(),
            $ph14->id,
        );

        // Four of the five seeded PH-14 documents are past WIP.
        $this->assertSame(4, $docs->count(), 'a participant must see the shared register');

        // But Pars' work-in-progress stays private to Pars.
        $this->assertSame(
            [],
            $docs->filter(fn ($d) => $d->currentRevision?->cde_state === CdeState::WIP)
                ->pluck('number')->all(),
            "a rival contractor's WIP leaked",
        );
    }

    public function test_the_employer_sees_every_participants_deliverables(): void
    {
        // SRS cross-company visibility matrix: the owner sees its whole project.
        $nioc = $this->user('karimi@nioc.example');
        $ph14 = $this->project('PH-14');

        $count = app(TenantContext::class)->runAs(
            $nioc->company_id,
            fn () => Document::count(),
            $ph14->id,
        );

        // Five seeded PH-14 documents from three disciplines, authored by
        // Pars - all of which the owner must see.
        $this->assertSame(5, $count);
    }

    public function test_the_project_scope_does_not_leak_across_projects(): void
    {
        // Found by this suite: the first policy filtered on company_id alone,
        // so NIOC - scoped to PH-14 - still saw its own KISH-1 documents,
        // because those rows are also "its own". Company identity is not a
        // project scope (standing rule 7).
        $nioc = $this->user('karimi@nioc.example');
        $tenant = app(TenantContext::class);

        $inPh14 = $tenant->runAs(
            $nioc->company_id,
            fn () => Document::pluck('number')->all(),
            $this->project('PH-14')->id,
        );

        foreach ($inPh14 as $number) {
            $this->assertStringStartsWith('PH-14-', $number, 'a foreign project leaked into the scope');
        }

        // NIOC owns KISH-1 too, so scoping there must show that one instead.
        $inKish = $tenant->runAs(
            $nioc->company_id,
            fn () => Document::pluck('number')->all(),
            $this->project('KISH-1')->id,
        );

        $this->assertNotEmpty($inKish);

        foreach ($inKish as $number) {
            $this->assertStringStartsWith('KISH-1-', $number);
        }
    }

    public function test_a_foreign_project_register_is_404_not_403(): void
    {
        // A 403 would confirm the project exists.
        $this->actingAs($this->user('hosseini@arvand.example'))
            ->get('/projects/PH-14/documents?lang=en')
            ->assertNotFound();
    }

    // ---- entitlement + capability ----------------------------------------

    public function test_the_starter_plan_can_use_the_document_module(): void
    {
        // `doc` is sold in Starter. It rendered as "Soon" for months; this is
        // the test that says it must actually work.
        $this->actingAs($this->user('hosseini@arvand.example'))
            ->get('/projects/KISH-1/documents?lang=en')
            ->assertOk()
            ->assertSee('Document register');
    }

    public function test_a_viewer_is_not_offered_write_controls(): void
    {
        // The consultant holds doc.view only.
        $this->actingAs($this->user('tehrani@pmc.example'))
            ->get('/projects/PH-14/documents?lang=en')
            ->assertOk()
            ->assertDontSee('Register a document');
    }

    public function test_a_viewer_cannot_transition_a_document_over_http(): void
    {
        // Hiding the button is not a control; the capability check is.
        $pmc = $this->user('tehrani@pmc.example');
        $ph14 = $this->project('PH-14');

        $doc = app(TenantContext::class)->runAs(
            $this->user('rezaei@pars.example')->company_id,
            fn () => Document::where('number', 'PH-14-CIVIL-DWG-0001')->firstOrFail(),
            $ph14->id,
        );

        $this->actingAs($pmc)
            ->post("/projects/PH-14/documents/{$doc->id}?lang=en", [
                'cde_state' => CdeState::SHARED,
                'suitability' => 'S2',
            ])
            ->assertForbidden();
    }

    // ---- presentation ----------------------------------------------------

    public function test_the_register_is_bilingual(): void
    {
        $pars = $this->user('rezaei@pars.example');

        $this->actingAs($pars)->get('/projects/PH-14/documents')
            ->assertSee('دفتر مدارک')->assertSee('dir="rtl"', false);

        $this->actingAs($pars)->get('/projects/PH-14/documents?lang=en')
            ->assertSee('Document register')->assertSee('dir="ltr"', false);
    }

    public function test_the_sidebar_now_links_documents_instead_of_saying_soon(): void
    {
        $html = (string) $this->actingAs($this->user('rezaei@pars.example'))
            ->get('/projects/PH-14?lang=en')->getContent();

        $this->assertStringContainsString('/projects/PH-14/documents', $html);
        // The nav item must no longer be inert.
        $this->assertDoesNotMatchRegularExpression(
            '/sb-i off[^>]*>\s*<i[^>]*>.*?<\/i>\s*<span>Documents/s',
            $html,
        );
    }

    // ---- the CDE must actually be common (ARC §5.4) -----------------------

    public function test_a_participant_sees_shared_documents_from_other_companies(): void
    {
        // PMC-TEHRAN participates in PH-14 and holds doc.view. Before the
        // sharing policy it opened the register and saw "no documents" - the
        // module was useless to consultants, MC and TPI alike.
        $pmc = $this->user('tehrani@pmc.example');
        $project = $this->project('PH-14');

        $visible = app(TenantContext::class)->runAs(
            $pmc->company_id,
            fn () => Document::with('currentRevision')->get(),
            $project->id,
        );

        $this->assertGreaterThan(0, $visible->count(), 'a participant saw an empty register');

        // Everything it can see is either its own or genuinely shared.
        foreach ($visible as $doc) {
            $this->assertNotSame(
                CdeState::WIP,
                $doc->currentRevision->cde_state,
                "WIP document {$doc->number} leaked to a company that did not author it",
            );
        }
    }

    public function test_wip_stays_private_to_the_originating_company(): void
    {
        // The other half of the same rule. A contractor will not adopt a system
        // that shows the client half-drawn work.
        $pmc = $this->user('tehrani@pmc.example');
        $project = $this->project('PH-14');

        $wipNumbers = app(TenantContext::class)->runAs(
            $pmc->company_id,
            fn () => Document::with('currentRevision')->get()
                ->filter(fn ($d) => $d->currentRevision?->cde_state === CdeState::WIP)
                ->pluck('number')->all(),
            $project->id,
        );

        $this->assertSame([], $wipNumbers);

        // And the author still sees its own WIP.
        $author = $this->user('rezaei@pars.example');
        $ownWip = app(TenantContext::class)->runAs(
            $author->company_id,
            fn () => Document::with('currentRevision')->get()
                ->filter(fn ($d) => $d->currentRevision?->cde_state === CdeState::WIP)
                ->count(),
            $project->id,
        );

        $this->assertGreaterThan(0, $ownWip, 'the author lost sight of its own WIP');
    }

    public function test_sharing_a_document_makes_it_appear_for_other_participants(): void
    {
        // The transition is the act of sharing, so visibility must follow it
        // with no separate "publish to CDE" step to forget.
        $author = $this->user('rezaei@pars.example');
        $pmc = $this->user('tehrani@pmc.example');
        $project = $this->project('PH-14');
        $ctx = app(TenantContext::class);

        $doc = $ctx->runAs($author->company_id, fn () => app(DocumentRegister::class)->register(
            $project,
            $author,
            [
                'discipline' => 'HVAC',
                'doc_type' => 'DWG',
                'title_en' => 'Ventilation layout',
                'title_fa' => 'چیدمان تهویه',
            ],
        ), $project->id);

        $seenBefore = $ctx->runAs(
            $pmc->company_id,
            fn () => Document::where('id', $doc->id)->exists(),
            $project->id,
        );
        $this->assertFalse($seenBefore, 'a brand-new WIP drawing was visible to another company');

        $ctx->runAs(
            $author->company_id,
            fn () => app(DocumentRegister::class)->transition($doc, $author, CdeState::SHARED, 'S2'),
            $project->id,
        );

        $seenAfter = $ctx->runAs(
            $pmc->company_id,
            fn () => Document::where('id', $doc->id)->exists(),
            $project->id,
        );
        $this->assertTrue($seenAfter, 'sharing a drawing did not make it visible to a participant');
    }

    public function test_a_participant_still_cannot_write_to_another_companys_document(): void
    {
        // Reading widely must not become writing widely. The WITH CHECK clause
        // is deliberately unchanged.
        $pmc = $this->user('tehrani@pmc.example');
        $project = $this->project('PH-14');

        $shared = app(TenantContext::class)->runAs(
            $pmc->company_id,
            fn () => Document::with('currentRevision')->get()
                ->first(fn ($d) => $d->company_id !== $pmc->company_id),
            $project->id,
        );

        $this->assertNotNull($shared, 'expected to see a foreign shared document');

        $this->expectException(\Illuminate\Database\QueryException::class);

        app(TenantContext::class)->runAs($pmc->company_id, function () use ($shared): void {
            \App\Models\DocumentRevision::create([
                'document_id' => $shared->id,
                'project_id' => $shared->project_id,
                // Forging the owning company is the obvious attack, and the
                // policy refuses it at the database.
                'company_id' => $shared->company_id,
                'revision' => 'Z',
                'ordinal' => 99,
                'cde_state' => CdeState::WIP,
            ]);
        }, $shared->project_id);
    }

    public function test_a_non_participant_sees_nothing_even_when_shared(): void
    {
        // Sharing is to the PROJECT, never to the world. Arvand is on KISH-1.
        $arvand = $this->user('hosseini@arvand.example');
        $project = $this->project('PH-14');

        $count = app(TenantContext::class)->runAs(
            $arvand->company_id,
            fn () => Document::count(),
            $project->id,
        );

        $this->assertSame(0, $count, 'a shared document leaked to a non-participant');
    }
}
