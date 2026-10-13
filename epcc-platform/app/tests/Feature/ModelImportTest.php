<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Joint;
use App\Models\ModelImport;
use App\Models\ModelImportDiff;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ModelImportService;
use App\Services\PipingLineService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Building the piping model from a 3D model extract (ADR-005 phase 2).
 *
 * The 3D model itself cannot be read - NWD/NWF/NWC are proprietary and
 * Windows-only - so the path in is PCF, which every major plant tool emits and
 * which carries the CONNECTIVITY rather than just a parts list. That is what
 * makes joints possible instead of a spreadsheet of components.
 *
 * What these tests are really defending is the three rulings the user gave:
 * a re-import diffs rather than destroys, an unknown line is quarantined
 * rather than refused or silently accepted, and imported objects stay editable
 * but get flagged.
 */
final class ModelImportTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function svc(): ModelImportService
    {
        return app(ModelImportService::class);
    }

    private function subsystem(): Subsystem
    {
        return Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/pcf/line-6-P.pcf');
    }

    /** Register the line the fixture refers to, so it is not an unknown line. */
    private function seedLine(string $number = '6-P-A1-PH-14-SS-2201-001'): PipingLine
    {
        return $this->asOwner(function () use ($number) {
            $project = $this->project();
            $lines = app(PipingLineService::class);

            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            return $lines->register($this->subsystem(), $this->owner(), $number, [
                'nominal_size' => '6', 'service_code' => 'P',
            ]);
        });
    }

    private function import(?string $text = null, bool $dryRun = false, bool $quarantine = true): ModelImport
    {
        return $this->asOwner(fn () => $this->svc()->importPcf(
            $this->project(),
            $this->owner(),
            $text ?? $this->fixture(),
            'line-6-P.pcf',
            $dryRun,
            $quarantine ? $this->subsystem() : null,
        ));
    }

    // ------------------------------------------------------- the basics --

    public function test_it_builds_the_line_model_from_the_file(): void
    {
        $line = $this->seedLine();
        $import = $this->import();

        $this->assertSame(ModelImport::STATE_APPLIED, $import->state);

        // Five material components; the SUPPORT is recognised and skipped, the
        // two WELDs are not components.
        $this->assertSame(5, $this->asOwner(
            fn () => Component::where('piping_line_id', $line->id)->count()
        ));
    }

    public function test_components_keep_their_identity_from_the_file(): void
    {
        $line = $this->seedLine();
        $this->import();

        $pipe = $this->asOwner(fn () => Component::where('piping_line_id', $line->id)
            ->where('pcf_identifier', 'C-001')->first());

        $this->assertNotNull($pipe);
        $this->assertSame('PIPE', $pipe->template_code);
        $this->assertSame('PIPE-CS-A106B-STD', $pipe->material_code);
        $this->assertSame('SP-01', $pipe->spool_identifier);
        $this->assertSame('PCF', $pipe->source);
    }

    public function test_the_bore_becomes_a_real_nps_size(): void
    {
        $line = $this->seedLine();
        $this->import();

        $pipe = $this->asOwner(fn () => Component::where('pcf_identifier', 'C-001')->first());

        $this->assertSame('6', $pipe->nominal_size);
    }

    public function test_joints_are_built_where_two_ports_share_a_point(): void
    {
        // The whole reason PCF beats an MTO: connectivity is stated, not
        // inferred. Pipe-elbow, elbow-pipe, pipe-flange, flange-gasket.
        $line = $this->seedLine();
        $import = $this->import();

        $this->assertSame(4, $import->joints_created);
        $this->assertSame(4, $this->asOwner(fn () => Joint::count()));
    }

    public function test_coordinates_are_stored_in_millimetres(): void
    {
        $line = $this->seedLine();
        $this->import();

        $port = $this->asOwner(fn () => Port::whereIn(
            'component_id', Component::where('pcf_identifier', 'C-001')->select('id')
        )->orderBy('seq')->get()->last());

        $this->assertEqualsWithDelta(3000.0, (float) $port->x_mm, 0.001);
    }

    public function test_mm_hundredths_are_converted_on_the_way_in(): void
    {
        // AVEVA emits MM-HUNDREDTHS. Stored raw, a 3 m pipe would be 300 m and
        // no joint would ever match across two files of differing units.
        $this->seedLine();

        $text = "UNITS-BORE INCH\nUNITS-CO-ORDS MM-HUNDREDTHS\n"
            ."PIPELINE-REFERENCE 6-P-A1-PH-14-SS-2201-001\n"
            ."PIPE\n    END-POINT 0 0 0 6 BW\n    END-POINT 300000 0 0 6 BW\n"
            ."    COMPONENT-IDENTIFIER C-900\n";

        $this->import($text);

        $port = $this->asOwner(fn () => Port::whereIn(
            'component_id', Component::where('pcf_identifier', 'C-900')->select('id')
        )->orderBy('seq')->get()->last());

        $this->assertEqualsWithDelta(3000.0, (float) $port->x_mm, 0.001);
    }

    // --------------------------------------------- refusing bad input --

    public function test_a_file_without_units_bore_is_refused(): void
    {
        // Not defaulted. INCH-SIXTEENTH read as INCH makes a 6" line 96" and
        // every number downstream stays plausible.
        $this->seedLine();

        $this->expectException(ValidationException::class);

        $this->import("PIPELINE-REFERENCE 6-P-A1-PH-14-SS-2201-001\nPIPE\n    END-POINT 0 0 0 6\n");
    }

    public function test_a_file_without_a_line_reference_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->import("UNITS-BORE INCH\nPIPE\n    END-POINT 0 0 0 6\n");
    }

    public function test_importing_onto_a_deleted_line_is_refused(): void
    {
        // The number stays reserved after deletion; the model must not
        // resurrect the line behind everyone's back.
        $line = $this->seedLine();

        $this->asOwner(fn () => app(PipingLineService::class)
            ->withdraw($line, $this->owner(), 'Duplicate'));

        $this->expectException(ValidationException::class);

        $this->import();
    }

    // -------------------------------------- ruling 2: quarantine --

    public function test_an_unknown_line_is_quarantined_not_refused(): void
    {
        // Refusing would mean the model cannot load until the line list is
        // perfect, which never happens on a live job.
        $import = $this->import();

        $line = $this->asOwner(fn () => PipingLine::where(
            'line_number', '6-P-A1-PH-14-SS-2201-001'
        )->first());

        $this->assertNotNull($line);
        $this->assertTrue($line->is_quarantined);
        $this->assertSame(ModelImport::STATE_APPLIED, $import->state);
    }

    public function test_a_quarantined_line_can_be_accepted_by_a_person(): void
    {
        $this->import();

        $line = $this->asOwner(fn () => PipingLine::where('is_quarantined', true)->firstOrFail());

        $accepted = $this->asOwner(fn () => $this->svc()->acceptQuarantinedLine($line, $this->owner()));

        $this->assertFalse($accepted->is_quarantined);
        $this->assertNotNull($accepted->released_from_quarantine_at);
        $this->assertSame($this->owner()->id, $accepted->released_from_quarantine_by);
    }

    public function test_an_unknown_line_is_refused_when_no_quarantine_is_offered(): void
    {
        $this->expectException(ValidationException::class);

        $this->import(quarantine: false);
    }

    public function test_a_known_line_is_not_quarantined(): void
    {
        $this->seedLine();
        $this->import();

        $line = $this->asOwner(fn () => PipingLine::where(
            'line_number', '6-P-A1-PH-14-SS-2201-001'
        )->firstOrFail());

        $this->assertFalse($line->is_quarantined);
    }

    // ------------------------------ ruling 1: re-import diffs --

    public function test_reimporting_the_same_file_changes_nothing(): void
    {
        // The second import must be quiet. If an unchanged re-import produced
        // a page of UPDATED rows, nobody would read the report on the third.
        $this->seedLine();
        $this->import();

        $second = $this->import();

        $this->assertSame(0, $second->components_created);
        $this->assertSame(0, $second->components_updated);
        $this->assertSame(5, $second->components_unchanged);
    }

    public function test_a_changed_material_code_is_applied_and_recorded(): void
    {
        $this->seedLine();
        $this->import();

        $this->import(str_replace('PIPE-CS-A106B-STD', 'PIPE-CS-A333-6', $this->fixture()));

        $pipe = $this->asOwner(fn () => Component::where('pcf_identifier', 'C-001')->firstOrFail());
        $this->assertSame('PIPE-CS-A333-6', $pipe->material_code);

        $diff = $this->asOwner(fn () => ModelImportDiff::where('change', ModelImportDiff::UPDATED)
            ->where('component_id', $pipe->id)->first());

        $this->assertNotNull($diff);
        $this->assertSame('PIPE-CS-A106B-STD', $diff->before['material_code']);
    }

    public function test_a_fitted_joint_protects_its_component_from_the_model(): void
    {
        // THE RULING THAT MATTERS MOST. The field has welded it. Engineering
        // re-issuing the model does not get to rewrite a physical fact.
        $this->seedLine();
        $this->import();

        $pipe = $this->asOwner(fn () => Component::where('pcf_identifier', 'C-001')->firstOrFail());

        $this->asOwner(function () use ($pipe) {
            $joint = Joint::query()->whereIn(
                'port_a_id', Port::where('component_id', $pipe->id)->select('id')
            )->orWhereIn(
                'port_b_id', Port::where('component_id', $pipe->id)->select('id')
            )->firstOrFail();

            app(\App\Services\JointService::class)->markFitted($joint, $this->owner(), 'W-01');
        });

        $import = $this->import(str_replace('PIPE-CS-A106B-STD', 'PIPE-CS-A333-6', $this->fixture()));

        $this->assertSame('PIPE-CS-A106B-STD', $this->asOwner(
            fn () => Component::where('pcf_identifier', 'C-001')->firstOrFail()->material_code
        ));

        $this->assertGreaterThan(0, $import->components_protected);

        $diff = $this->asOwner(fn () => ModelImportDiff::where('change', ModelImportDiff::PROTECTED)->first());
        $this->assertSame('FITTED_JOINT', $diff->protected_reason);
    }

    public function test_a_protected_change_still_says_what_it_would_have_done(): void
    {
        // A refusal with no detail is useless: the engineer has to be able to
        // see what the model wanted before deciding.
        $this->seedLine();
        $this->import();

        $pipe = $this->asOwner(fn () => Component::where('pcf_identifier', 'C-001')->firstOrFail());
        $this->asOwner(fn () => $pipe->markManuallyEdited($this->owner()));

        $this->import(str_replace('PIPE-CS-A106B-STD', 'PIPE-CS-A333-6', $this->fixture()));

        $diff = $this->asOwner(fn () => ModelImportDiff::where('change', ModelImportDiff::PROTECTED)->firstOrFail());

        $this->assertSame('PIPE-CS-A333-6', $diff->after['material_code']);
        $this->assertSame('PIPE-CS-A106B-STD', $diff->before['material_code']);
    }

    public function test_a_component_absent_from_the_new_revision_is_reported_not_deleted(): void
    {
        // Deleting has consequences for the take-off and for material already
        // bought. That is not a decision an upload gets to make.
        $this->seedLine();
        $this->import();

        $shorter = preg_replace('/GASKET\n(    .*\n)+/', '', $this->fixture());
        $this->import($shorter);

        $this->assertSame(5, $this->asOwner(fn () => Component::count()));

        $diff = $this->asOwner(fn () => ModelImportDiff::where(
            'change', ModelImportDiff::REMOVED_IN_MODEL
        )->first());

        $this->assertNotNull($diff);
    }

    // ------------------------------ ruling 3: editable but flagged --

    public function test_a_hand_edited_component_is_protected_from_the_model(): void
    {
        $this->seedLine();
        $this->import();

        $pipe = $this->asOwner(fn () => Component::where('pcf_identifier', 'C-001')->firstOrFail());
        $this->asOwner(fn () => $pipe->markManuallyEdited($this->owner()));

        $import = $this->import(str_replace('PIPE-CS-A106B-STD', 'PIPE-CS-A333-6', $this->fixture()));

        $diff = $this->asOwner(fn () => ModelImportDiff::where('change', ModelImportDiff::PROTECTED)->firstOrFail());

        $this->assertSame('MANUALLY_EDITED', $diff->protected_reason);
        $this->assertSame(1, $import->components_protected);
    }

    public function test_an_untouched_imported_component_is_still_updated(): void
    {
        // The flag must not be a blanket freeze on everything the model ever
        // created, or the second import would be useless.
        $this->seedLine();
        $this->import();

        $import = $this->import(str_replace('PIPE-CS-A106B-STD', 'PIPE-CS-A333-6', $this->fixture()));

        $this->assertSame(2, $import->components_updated); // two PIPE components
        $this->assertSame(0, $import->components_protected);
    }

    // --------------------------------------------------- reporting --

    public function test_an_unknown_component_type_is_reported_not_guessed(): void
    {
        $this->seedLine();

        $text = "UNITS-BORE INCH\nPIPELINE-REFERENCE 6-P-A1-PH-14-SS-2201-001\n"
            ."FLUX-CAPACITOR\n    END-POINT 0 0 0 6 BW\n    COMPONENT-IDENTIFIER C-999\n";

        $import = $this->import($text);

        $this->assertSame(1, $import->unmapped_count);
        $this->assertSame(0, $import->components_created);

        $diff = $this->asOwner(fn () => ModelImportDiff::where('change', ModelImportDiff::UNMAPPED)->firstOrFail());
        $this->assertSame('FLUX-CAPACITOR', $diff->pcf_type);
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->seedLine();

        $import = $this->import(dryRun: true);

        $this->assertSame(ModelImport::STATE_DRY_RUN, $import->state);
        $this->assertSame(5, $import->components_created);
        $this->assertSame(0, $this->asOwner(fn () => Component::count()));
    }

    public function test_the_file_hash_and_units_are_recorded(): void
    {
        // So that months later it can be proved which file produced the model
        // the isometric was drawn from.
        $this->seedLine();
        $import = $this->import();

        $this->assertSame(hash('sha256', $this->fixture()), $import->content_hash);
        $this->assertSame('INCH', $import->units_bore);
        $this->assertSame('MM', $import->units_coords);
        $this->assertSame('B', $import->revision);
    }

    public function test_a_refused_joint_does_not_abort_the_import(): void
    {
        // A butt weld onto a flange is a finding about the pipework, not a
        // failure of the importer. The rest of the file must still land.
        $this->seedLine();

        $text = "UNITS-BORE INCH\nPIPELINE-REFERENCE 6-P-A1-PH-14-SS-2201-001\n"
            ."PIPE\n    END-POINT 0 0 0 6 BW\n    END-POINT 1000 0 0 6 BW\n    COMPONENT-IDENTIFIER C-A\n"
            ."VALVE\n    END-POINT 1000 0 0 6 FL\n    END-POINT 1400 0 0 6 FL\n    COMPONENT-IDENTIFIER C-B\n";

        $import = $this->import($text);

        $this->assertSame(2, $import->components_created);
        $this->assertSame(0, $import->joints_created);

        $diff = $this->asOwner(fn () => ModelImportDiff::where('change', ModelImportDiff::UNMAPPED)->first());
        $this->assertNotNull($diff);
    }

    public function test_three_ports_at_one_point_make_no_joint(): void
    {
        // Geometrically impossible in real pipework: it means the model has
        // stacked components. Picking two of the three would fabricate a weld.
        $this->seedLine();

        $text = "UNITS-BORE INCH\nPIPELINE-REFERENCE 6-P-A1-PH-14-SS-2201-001\n"
            ."PIPE\n    END-POINT 0 0 0 6 BW\n    END-POINT 1000 0 0 6 BW\n    COMPONENT-IDENTIFIER C-A\n"
            ."PIPE\n    END-POINT 1000 0 0 6 BW\n    END-POINT 2000 0 0 6 BW\n    COMPONENT-IDENTIFIER C-B\n"
            ."PIPE\n    END-POINT 1000 0 0 6 BW\n    END-POINT 1000 900 0 6 BW\n    COMPONENT-IDENTIFIER C-C\n";

        $import = $this->import($text);

        $this->assertSame(0, $import->joints_created);
    }

    // ------------------------------------------------------------ the UI --

    public function test_the_import_screen_renders(): void
    {
        $this->seedLine();
        $this->import();

        $response = $this->actingAs($this->owner())
            ->get("/projects/PH-14/piping/model-import?lang=en");

        $response->assertOk();
        $response->assertSee('3D model import');
        $response->assertSee('line-6-P.pcf');
    }

    public function test_the_screen_is_reachable_and_not_swallowed_by_the_line_route(): void
    {
        // `piping/{lineId}/model` is declared in the same group. Declared in
        // the wrong order, Laravel reads "model-import" as a lineId and the
        // page 404s on a findOrFail nobody can explain.
        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/model-import?lang=en')
            ->assertOk();
    }

    public function test_the_screen_renders_in_farsi(): void
    {
        $this->seedLine();
        $this->import();

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/model-import?lang=fa')
            ->assertOk()
            ->assertSee('ورود مدل سه‌بعدی', false);
    }

    public function test_a_non_participant_gets_a_404(): void
    {
        $this->actingAs($this->userByEmail('hosseini@arvand.example'))
            ->get('/projects/PH-14/piping/model-import?lang=en')
            ->assertNotFound();
    }

    public function test_uploading_through_the_form_imports_the_file(): void
    {
        $this->seedLine();

        $response = $this->actingAs($this->owner())->post(
            '/projects/PH-14/piping/model-import?lang=en',
            [
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent(
                    'line-6-P.pcf', $this->fixture()
                ),
            ],
        );

        $response->assertRedirect();

        $this->assertSame(5, $this->asOwner(fn () => Component::count()));
    }

    public function test_the_quarantine_reason_is_not_frozen_in_one_language(): void
    {
        // The reason is generated by the system, so storing it through __() at
        // import time would bake the IMPORTER's language into the row and show
        // a Farsi user an English explanation. It is stored as a machine key
        // and translated when displayed.
        $this->import();

        $line = $this->asOwner(fn () => PipingLine::where('is_quarantined', true)->firstOrFail());
        $this->assertStringStartsWith('AUTO:FROM_FILE:', (string) $line->quarantine_reason);

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/model-import?lang=fa')
            ->assertOk()
            ->assertSee('از فایل مدل سه‌بعدی', false)
            ->assertDontSee('Created from the 3D model file');

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/model-import?lang=en')
            ->assertOk()
            ->assertSee('Created from the 3D model file');
    }
}
