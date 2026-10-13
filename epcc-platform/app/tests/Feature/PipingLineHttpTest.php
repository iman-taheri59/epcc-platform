<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\LineDocument;
use App\Support\CdeState;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\PipingLineService;
use App\Support\LineReleaseState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * ADR-006 phase 1 over HTTP - the register screen and its two doors.
 *
 * PipingLineTest covers the service rules. This covers what the service
 * cannot: that a wrong value reaches the operator as a correctable message
 * rather than a 500, that the gate still refuses over HTTP, and that a user
 * from another company cannot see or touch the register at all.
 *
 * Both defects these tests now pin were found by driving the real screen with
 * curl after the service tests were already green.
 */
final class PipingLineHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        // The auto-numbering path is withdrawn in production (a counter
        // over surviving rows reissues a deleted line's number). These
        // tests exercise the numbering ENGINE, so they opt in explicitly.
        $this->allowAutoLineNumbers(\App\Models\Project::where('code', 'PH-14')->firstOrFail());
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    /** Give the project the registers the default pattern needs. */
    private function seedRegisters(): PipingClass
    {
        $project = $this->project();

        return app(TenantContext::class)->runAs(
            $project->owner_company_id,
            function () use ($project): PipingClass {
                app(PipingLineService::class)->configFor($project)->update([
                    'services' => ['P', 'HC'],
                    'coatings' => ['CT1'],
                ]);

                // 'rating', 'material' and 'corrosion_allowance' used to be
                // passed here. None of them was ever written: 'rating' is not
                // a column (it is 'pressure_rating'), and the other two were
                // dropped in 2026_09_24_120000. All three were outside
                // $fillable, so Eloquent discarded them in silence and the
                // test kept passing while asserting nothing about them.
                return PipingClass::create([
                    'project_id' => $project->id,
                    'company_id' => $project->owner_company_id,
                    'code' => 'A1',
                    'pressure_rating' => '150#',
                    'material_group_code' => 'PIPE',
                    'material_spec_code' => 'A106',
                    'material_grade_key' => 'B',
                    'material_as_written' => 'ASTM A106 Gr. B',
                    'corrosion_allowance_mm' => 3.0,
                ]);
            },
            $project->id,
        );
    }

    private function subsystem(): Subsystem
    {
        $project = $this->project();

        return app(TenantContext::class)->runAs(
            $project->owner_company_id,
            fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
            $project->id,
        );
    }

    public function test_the_register_renders_for_a_participant(): void
    {
        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping?lang=en')
            ->assertOk()
            ->assertSee('Piping lines');
    }

    public function test_a_non_participant_cannot_reach_the_register(): void
    {
        // EPC-ARVAND is the fixture's designated outsider. 404, not 403: the
        // existence of another company's project is itself not public.
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)
            ->get('/projects/PH-14/piping?lang=en')
            ->assertNotFound();
    }

    public function test_posting_a_line_allocates_the_next_number(): void
    {
        $class = $this->seedRegisters();
        $subsystem = $this->subsystem();

        $this->actingAs($this->owner())
            ->post('/projects/PH-14/piping?lang=en', [
                'subsystem_id' => $subsystem->id,
                'nominal_size' => '8',
                'service_code' => 'P',
                'piping_class_id' => $class->id,
            ])
            ->assertRedirect();

        $numbers = app(TenantContext::class)->runAs(
            $this->owner()->company_id,
            fn () => PipingLine::orderBy('line_number')->pluck('line_number')->all(),
            $this->project()->id,
        );

        $this->assertSame(['8-P-A1-PH-14-SS-2201-001'], $numbers);
    }

    public function test_an_unregistered_value_is_a_form_error_not_a_crash(): void
    {
        // Regression: the engine correctly refuses a value outside the project
        // register, but the controller first let that exception escape as a
        // 500. An operator picking a service the project has not set up is
        // making a correctable mistake, and must be told which value and which
        // token - not shown a stack trace.
        $this->seedRegisters();
        $subsystem = $this->subsystem();

        $response = $this->actingAs($this->owner())
            ->post('/projects/PH-14/piping?lang=en', [
                'subsystem_id' => $subsystem->id,
                'nominal_size' => '8',
                'service_code' => 'NOPE',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('line_number');

        $message = (string) session('errors')->first('line_number');
        $this->assertStringContainsString('NOPE', $message, 'the rejected value must be named');
        $this->assertStringContainsString('service', $message, 'and so must the token');

        $count = app(TenantContext::class)->runAs(
            $this->owner()->company_id,
            fn () => PipingLine::count(),
            $this->project()->id,
        );

        $this->assertSame(0, $count, 'a refused number must not leave a half-made line behind');
    }

    public function test_an_external_number_is_registered_verbatim(): void
    {
        $subsystem = $this->subsystem();

        $this->actingAs($this->owner())
            ->post('/projects/PH-14/piping?lang=en', [
                'subsystem_id' => $subsystem->id,
                'line_number' => 'LEGACY-XYZ-0042',
            ])
            ->assertRedirect();

        $line = app(TenantContext::class)->runAs(
            $this->owner()->company_id,
            fn () => PipingLine::first(),
            $this->project()->id,
        );

        $this->assertSame('LEGACY-XYZ-0042', $line->line_number);
        $this->assertNull($line->sequence, 'an imported number must not feed our counter');
    }

    /** Create a published document at the given suitability. */
    private function evidenceDoc(string $number, string $suitability, int $seq): Document
    {
        $project = $this->project();
        $owner = $this->owner();

        return app(TenantContext::class)->runAs(
            $owner->company_id,
            function () use ($project, $owner, $number, $suitability, $seq): Document {
                $doc = Document::create([
                    'project_id' => $project->id,
                    'company_id' => $owner->company_id,
                    'number' => $number,
                    'discipline' => 'PIPING',
                    'doc_type' => 'DWG',
                    'sequence' => $seq,
                    'title_en' => 'Evidence',
                    'title_fa' => 'مدرک',
                ]);
                $rev = DocumentRevision::create([
                    'document_id' => $doc->id,
                    'project_id' => $project->id,
                    'company_id' => $owner->company_id,
                    'revision' => '0',
                    'ordinal' => 1,
                    'cde_state' => CdeState::PUBLISHED,
                    'suitability' => $suitability,
                    'issued_by' => $owner->id,
                    'issued_at' => now(),
                ]);
                $doc->update(['current_revision_id' => $rev->id]);

                return $doc;
            },
            $project->id,
        );
    }

    private function aLine(): PipingLine
    {
        $class = $this->seedRegisters();
        $subsystem = $this->subsystem();

        $this->actingAs($this->owner())->post('/projects/PH-14/piping?lang=en', [
            'subsystem_id' => $subsystem->id,
            'nominal_size' => '8',
            'service_code' => 'P',
            'piping_class_id' => $class->id,
        ]);

        return app(TenantContext::class)->runAs(
            $this->owner()->company_id,
            fn () => PipingLine::firstOrFail(),
            $this->project()->id,
        );
    }

    public function test_evidence_can_be_attached_and_the_gate_then_passes(): void
    {
        // The whole point of the screen: a user, not a script, can get a line
        // from draft to AFC.
        $line = $this->aLine();
        $owner = $this->owner();
        $iso = $this->evidenceDoc('PH-14-PIP-ISO-7001', 'A1', 7001);
        $pid = $this->evidenceDoc('PH-14-PIP-PID-7002', 'A2', 7002);

        foreach ([[$iso, LineDocument::ISOMETRIC], [$pid, LineDocument::PID]] as [$doc, $kind]) {
            $this->actingAs($owner)
                ->post("/projects/PH-14/piping/{$line->id}/evidence?lang=en", [
                    'document_id' => $doc->id,
                    'evidence_kind' => $kind,
                ])
                ->assertRedirect();
        }

        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/transition?lang=en", [
                'to' => LineReleaseState::AFC_CONFIRMED,
            ])
            ->assertSessionHasNoErrors();

        $fresh = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => PipingLine::findOrFail($line->id),
            $this->project()->id,
        );

        $this->assertSame(LineReleaseState::AFC_CONFIRMED, $fresh->release_state);
        $this->assertNotNull($fresh->afc_confirmed_at);
    }

    public function test_a_wrongly_attached_document_can_be_removed(): void
    {
        // Found by driving the screen: attaching is not exclusive, and
        // unapprovedEvidence() checks every link. A B-coded drawing attached
        // by mistake therefore locked the line out of AFC permanently, with no
        // way back except editing the database. A strict gate must still be
        // escapable by correcting the input.
        $line = $this->aLine();
        $owner = $this->owner();
        $good = $this->evidenceDoc('PH-14-PIP-ISO-7003', 'A1', 7003);
        $bad = $this->evidenceDoc('PH-14-PIP-ISO-7004', 'B1', 7004);
        $pid = $this->evidenceDoc('PH-14-PIP-PID-7005', 'A1', 7005);

        foreach ([[$good, LineDocument::ISOMETRIC], [$bad, LineDocument::ISOMETRIC], [$pid, LineDocument::PID]] as [$doc, $kind]) {
            $this->actingAs($owner)->post("/projects/PH-14/piping/{$line->id}/evidence?lang=en", [
                'document_id' => $doc->id,
                'evidence_kind' => $kind,
            ]);
        }

        // Still blocked, because the B-coded one is also evidence.
        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/transition?lang=en", [
                'to' => LineReleaseState::AFC_CONFIRMED,
            ])
            ->assertSessionHasErrors();

        $badLink = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => LineDocument::where('document_id', $bad->id)->firstOrFail(),
            $this->project()->id,
        );

        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/evidence/{$badLink->id}/detach?lang=en")
            ->assertRedirect();

        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/transition?lang=en", [
                'to' => LineReleaseState::AFC_CONFIRMED,
            ])
            ->assertSessionHasNoErrors();

        $fresh = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => PipingLine::findOrFail($line->id),
            $this->project()->id,
        );

        $this->assertSame(LineReleaseState::AFC_CONFIRMED, $fresh->release_state);
    }

    public function test_evidence_cannot_be_detached_once_the_line_is_confirmed(): void
    {
        // Removing the justification for a decision already taken would leave
        // an approval with nothing behind it. Revert to draft first - that
        // path demands a reason.
        $line = $this->aLine();
        $owner = $this->owner();
        $iso = $this->evidenceDoc('PH-14-PIP-ISO-7006', 'A1', 7006);
        $pid = $this->evidenceDoc('PH-14-PIP-PID-7007', 'A1', 7007);

        foreach ([[$iso, LineDocument::ISOMETRIC], [$pid, LineDocument::PID]] as [$doc, $kind]) {
            $this->actingAs($owner)->post("/projects/PH-14/piping/{$line->id}/evidence?lang=en", [
                'document_id' => $doc->id,
                'evidence_kind' => $kind,
            ]);
        }

        $this->actingAs($owner)->post("/projects/PH-14/piping/{$line->id}/transition?lang=en", [
            'to' => LineReleaseState::AFC_CONFIRMED,
        ]);

        $link = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => LineDocument::where('document_id', $iso->id)->firstOrFail(),
            $this->project()->id,
        );

        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/evidence/{$link->id}/detach?lang=en")
            ->assertSessionHasErrors();

        $still = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => LineDocument::where('document_id', $iso->id)->exists(),
            $this->project()->id,
        );

        $this->assertTrue($still, 'the evidence must survive the refused detach');
    }

    public function test_evidence_cannot_be_detached_via_another_line(): void
    {
        // Mutation M21: nothing stopped a hand-edited URL from pairing line A
        // with line B's evidence link. Both rows are inside the same project,
        // so RLS is perfectly happy - the ownership check is the only thing
        // standing between a curious URL and quietly stripping the evidence
        // off a line the user never opened.
        $classA = $this->seedRegisters();
        $subsystem = $this->subsystem();
        $owner = $this->owner();

        foreach ([1, 2] as $_) {
            $this->actingAs($owner)->post('/projects/PH-14/piping?lang=en', [
                'subsystem_id' => $subsystem->id,
                'nominal_size' => '8',
                'service_code' => 'P',
                'piping_class_id' => $classA->id,
            ]);
        }

        [$lineA, $lineB] = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => PipingLine::orderBy('sequence')->get()->all(),
            $this->project()->id,
        );

        $doc = $this->evidenceDoc('PH-14-PIP-ISO-7008', 'A1', 7008);

        $this->actingAs($owner)->post("/projects/PH-14/piping/{$lineB->id}/evidence?lang=en", [
            'document_id' => $doc->id,
            'evidence_kind' => LineDocument::ISOMETRIC,
        ]);

        $link = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => LineDocument::where('document_id', $doc->id)->firstOrFail(),
            $this->project()->id,
        );

        // Line A is a draft, so the editable check passes - only the ownership
        // check can refuse this.
        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$lineA->id}/evidence/{$link->id}/detach?lang=en")
            ->assertSessionHasErrors();

        $survived = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => LineDocument::where('document_id', $doc->id)->exists(),
            $this->project()->id,
        );

        $this->assertTrue($survived, "line B's evidence must survive a detach aimed through line A");
    }

    public function test_the_gate_still_refuses_over_http(): void
    {
        $class = $this->seedRegisters();
        $subsystem = $this->subsystem();
        $owner = $this->owner();

        $this->actingAs($owner)->post('/projects/PH-14/piping?lang=en', [
            'subsystem_id' => $subsystem->id,
            'nominal_size' => '8',
            'service_code' => 'P',
            'piping_class_id' => $class->id,
        ]);

        $line = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => PipingLine::firstOrFail(),
            $this->project()->id,
        );

        $response = $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/transition?lang=en", [
                'to' => LineReleaseState::AFC_CONFIRMED,
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();

        $fresh = app(TenantContext::class)->runAs(
            $owner->company_id,
            fn () => PipingLine::findOrFail($line->id),
            $this->project()->id,
        );

        $this->assertSame(LineReleaseState::DRAFT, $fresh->release_state);
        $this->assertNull($fresh->afc_confirmed_at);
    }
}
