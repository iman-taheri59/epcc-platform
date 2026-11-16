<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Component;
use App\Models\ComponentPoseRevision;
use App\Models\Joint;
use App\Models\JointGeometryDiagnosticRevision;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\ProjectModelFrameRevision;
use App\Models\Role;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentPoseService;
use App\Services\ComponentService;
use App\Services\JointPortGeometryDiagnosticService;
use App\Services\JointService;
use App\Services\PipeScheduleExceptionRequestService;
use App\Services\PipeScheduleReportService;
use App\Services\PipingLineService;
use App\Services\ProjectArchiveService;
use App\Services\ProjectModelFrameService;
use App\Support\LineReleaseState;
use App\Support\Piping\JointBranchKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\ApprovedPmsClassFixture;
use Tests\TestCase;

/**
 * ADR-006 phase 2 - components and ports.
 *
 * The question this phase answers is the user's own: how does the system know
 * a pipe has two ports and a tee has three? It is never asked. These tests
 * assert that the port count, the port roles and the port sizes are all
 * DERIVED, and that the states an engineer would call nonsense are refused
 * structurally rather than by convention.
 */
final class ComponentModellingTest extends TestCase
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

    private function svc(): ComponentService
    {
        return app(ComponentService::class);
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

    /** A line with nothing modelled on it yet. */
    private function line(string $size = '6'): PipingLine
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->asOwner(function () use ($project, $owner, $size) {
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            ApprovedPmsClassFixture::ensure($project, $owner, $class);

            return $lines->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => $size, 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        });
    }

    private function pipe(PipingLine $line, ?float $lengthMm = 3000.0): Component
    {
        $attributes = $lengthMm === null ? [] : ['length_mm' => $lengthMm];

        return $this->asOwner(fn () => $this->svc()->place(
            $line,
            $this->owner(),
            'PIPE',
            $attributes,
        ));
    }

    private function modelFrame(?Project $project = null, ?User $actor = null, string $origin = 'PH-14 survey origin'): ProjectModelFrameRevision
    {
        return app(ProjectModelFrameService::class)->define(
            $project ?? $this->project(),
            $actor ?? $this->owner(),
            $origin,
        );
    }

    private function poseService(): ComponentPoseService
    {
        return app(ComponentPoseService::class);
    }

    // ------------------------------------------- ports come from templates --

    public function test_a_pipe_gets_two_ports_without_being_asked(): void
    {
        $line = $this->line();

        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->assertCount(2, $pipe->ports);
        $this->assertSame(['RUN', 'RUN'], $pipe->ports->pluck('role')->all());
    }

    public function test_a_tee_gets_three_ports_and_the_third_is_the_branch(): void
    {
        // The user's exact question. The port count is a property of the
        // component class, declared once in the template.
        $line = $this->line();

        $tee = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'TEE'));

        $this->assertCount(3, $tee->ports);
        $this->assertSame(['RUN', 'RUN', 'BRANCH'], $tee->ports->pluck('role')->all());
    }

    public function test_a_cap_gets_one_port(): void
    {
        $line = $this->line();

        $cap = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'CAP'));

        $this->assertCount(1, $cap->ports);
    }

    public function test_bw_elbow_requires_and_persists_a_supported_b16_9_qualifier(): void
    {
        $line = $this->line('6');

        try {
            $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'ELBOW'));
            $this->fail('A BW elbow must not default its angle or radius.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fitting_qualifier', $exception->errors());
        }

        foreach ([
            ['90SR', 'fitting_qualifier'],
            // An unqualified 45 is a B16.11 angle, so the safe error is to
            // require its explicit standard rather than imply B16.9 semantics.
            ['45', 'fitting_standard'],
            ['180LR', 'fitting_qualifier'],
        ] as [$unsupported, $expectedField]) {
            try {
                $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'ELBOW', [
                    'fitting_qualifier' => $unsupported,
                ]));
                $this->fail("Unsupported elbow qualifier $unsupported must be refused.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($expectedField, $exception->errors());
            }
        }

        $elbow = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'ELBOW', [
            'fitting_qualifier' => '45LR',
        ]));

        $this->assertSame('45LR', $elbow->fitting_qualifier);
        $this->assertSame('#1 ELBOW 45LR 6"', $elbow->label());
    }

    public function test_the_service_exposes_no_way_to_set_a_port_count(): void
    {
        // Guard against a future "convenience" parameter. If port counts ever
        // become caller-supplied, the template stops being the single source
        // of truth and a tee with four ports becomes representable.
        $line = $this->line();

        $tee = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'TEE', [
            'ports' => 9,
            'port_count' => 9,
        ]));

        $this->assertCount(3, $tee->ports, 'port count must come from the template alone');
    }

    // ------------------------------------------------------- size derivation --

    public function test_a_single_size_component_gives_every_port_that_size(): void
    {
        $line = $this->line('6');

        $valve = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'VALVE'));

        $this->assertSame(['6', '6'], $valve->ports->pluck('nominal_size')->all());
    }

    public function test_a_reducing_tee_is_one_component_with_a_smaller_branch(): void
    {
        // User correction, recorded in the handoff: Eq. Tee and Red. Tee are
        // ONE component differentiated by its sizes, not two component types.
        $line = $this->line('6');

        $tee = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'TEE', [
            'port_sizes' => [3 => '2'],
        ]));

        $this->assertSame(['6', '6', '2'], $tee->ports->pluck('nominal_size')->all());
        $this->assertSame('TEE', $tee->template_code, 'still a tee, not a separate type');
    }

    public function test_a_branch_larger_than_the_run_is_refused(): void
    {
        $line = $this->line('2');

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'TEE', [
            'port_sizes' => [3 => '6'],
        ]));
    }

    public function test_a_reducer_with_two_equal_ends_is_refused(): void
    {
        // A 6x6 "reducer" is a spool nobody ordered.
        $line = $this->line('6');

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'REDUCER', [
            'port_sizes' => [1 => '6', 2 => '6'],
        ]));
    }

    public function test_a_reducer_reduces(): void
    {
        $line = $this->line('6');

        $red = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'REDUCER', [
            'port_sizes' => [1 => '6', 2 => '4'],
        ]));

        $this->assertSame(['6', '4'], $red->ports->pluck('nominal_size')->all());
        $this->assertSame(['LARGE', 'SMALL'], $red->ports->pluck('role')->all());
    }

    public function test_a_per_port_override_is_ignored_on_a_single_size_component(): void
    {
        // Asking for a 6x4 valve is a user error, not a 6x4 valve.
        $line = $this->line('6');

        $valve = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'VALVE', [
            'port_sizes' => [2 => '4'],
        ]));

        $this->assertSame(['6', '6'], $valve->ports->pluck('nominal_size')->all());
    }

    public function test_both_size_spellings_are_stored(): void
    {
        // NPS as written and as a number: '2 1/2' sorts wrong, 2.5 computes
        // right, and dia-inch (ADR-010) needs the comparison.
        $line = $this->line('2 1/2');

        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->assertSame('2 1/2', $pipe->nominal_size);
        $this->assertEqualsWithDelta(2.5, (float) $pipe->nominal_size_decimal, 1e-9);
        $this->assertEqualsWithDelta(2.5, (float) $pipe->ports->first()->nominal_size_decimal, 1e-9);
    }

    public function test_an_unrecognised_size_is_refused_not_silently_nulled(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE', [
            'nominal_size' => '7',
        ]));
    }

    // --------------------------------------------------------- attachment --

    public function test_attaching_inherits_the_parent_port_size(): void
    {
        // The gesture that makes "6in pipe onto a 2in branch" impossible: the
        // size is inherited, never typed.
        $line = $this->line('6');

        $result = $this->asOwner(function () use ($line) {
            $tee = $this->svc()->place($line, $this->owner(), 'TEE', ['port_sizes' => [3 => '2']]);
            $branch = $tee->ports->firstWhere('role', 'BRANCH');

            return $this->svc()->attach($branch, $this->owner(), 'PIPE', ['length_mm' => 3000]);
        });

        $this->assertSame('2', $result['component']->nominal_size);
    }

    public function test_an_attempt_to_override_the_inherited_size_is_discarded(): void
    {
        $line = $this->line('6');

        $result = $this->asOwner(function () use ($line) {
            $tee = $this->svc()->place($line, $this->owner(), 'TEE', ['port_sizes' => [3 => '2']]);
            $branch = $tee->ports->firstWhere('role', 'BRANCH');

            return $this->svc()->attach($branch, $this->owner(), 'PIPE', ['nominal_size' => '6']);
        });

        $this->assertSame('2', $result['component']->nominal_size, 'inheritance must win');
    }

    public function test_attaching_closes_both_ports(): void
    {
        $line = $this->line('6');

        $result = $this->asOwner(function () use ($line) {
            $tee = $this->svc()->place($line, $this->owner(), 'TEE');
            $port = $tee->ports->firstWhere('seq', 1);

            return $this->svc()->attach($port, $this->owner(), 'PIPE');
        });

        $this->assertTrue($result['parentPort']->is_connected);
        $this->assertTrue($result['childPort']->is_connected);
    }

    public function test_a_connected_port_cannot_be_attached_to_twice(): void
    {
        // The UI never offers it, so reaching here means a stale form or a
        // second tab - exactly when a silent second attachment does damage.
        $line = $this->line('6');

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->svc()->place($line, $this->owner(), 'TEE');
            $port = $tee->ports->firstWhere('seq', 1);

            $this->svc()->attach($port, $this->owner(), 'PIPE');
            // This second request must fail on the already-connected parent
            // port, not because the candidate BW elbow lacks its qualifier.
            $this->svc()->attach($port->fresh(), $this->owner(), 'ELBOW', [
                'fitting_qualifier' => '90LR',
            ]);
        });
    }

    // ------------------------------------------------------- the worklist --

    public function test_open_ends_lists_every_unconnected_port_in_order(): void
    {
        $line = $this->line('6');

        $open = $this->asOwner(function () use ($line) {
            $tee = $this->svc()->place($line, $this->owner(), 'TEE', ['port_sizes' => [3 => '2']]);
            $this->svc()->attach($tee->ports->firstWhere('role', 'BRANCH'), $this->owner(), 'PIPE');

            return $this->svc()->openPorts($line);
        });

        // Tee P1, tee P2, and the far end of the new pipe. The branch pair is
        // closed.
        $this->assertCount(3, $open);
        $this->assertSame(
            ['#1 TEE 6" / P1 RUN 6"', '#1 TEE 6" / P2 RUN 6"', '#2 PIPE 2" / P2 RUN 2"'],
            $open->map(fn (Port $p): string => $p->component->label().' / '.$p->label())->all(),
        );
    }

    public function test_open_ends_is_scoped_to_its_own_line(): void
    {
        // The worklist drives the progress metric; leaking another line's
        // ports into it would overstate how much is left everywhere.
        $a = $this->line('6');
        $b = $this->line('4');

        [$openA, $openB] = $this->asOwner(function () use ($a, $b) {
            $this->svc()->place($a, $this->owner(), 'TEE');
            $this->svc()->place($b, $this->owner(), 'PIPE');

            return [$this->svc()->openPorts($a), $this->svc()->openPorts($b)];
        });

        $this->assertCount(3, $openA);
        $this->assertCount(2, $openB);
    }

    // ---------------------------------------------------------- lifecycle --

    public function test_sequence_is_per_line_and_starts_at_one(): void
    {
        $a = $this->line('6');
        $b = $this->line('4');

        [$first, $second, $other] = $this->asOwner(fn () => [
            $this->svc()->place($a, $this->owner(), 'PIPE'),
            $this->svc()->place($a, $this->owner(), 'ELBOW', ['fitting_qualifier' => '90LR']),
            $this->svc()->place($b, $this->owner(), 'PIPE'),
        ]);

        $this->assertSame(1, $first->sequence);
        $this->assertSame(2, $second->sequence);
        $this->assertSame(1, $other->sequence, 'numbering restarts on each line');
    }

    public function test_removing_a_component_removes_its_ports(): void
    {
        $line = $this->line('6');

        $this->asOwner(function () use ($line) {
            $tee = $this->svc()->place($line, $this->owner(), 'TEE');
            $id = $tee->id;

            $this->svc()->remove($tee, $this->owner());

            $this->assertSame(0, Component::query()->whereKey($id)->count());
            $this->assertSame(0, Port::query()->where('component_id', $id)->count());
        });
    }

    public function test_a_released_line_cannot_be_modelled(): void
    {
        $line = $this->line('6');

        $this->asOwner(function () use ($line) {
            $line->forceFill([
                'release_state' => LineReleaseState::RELEASED,
                'afc_confirmed_at' => now(),
                'afc_confirmed_by' => $this->owner()->id,
                'released_at' => now(),
                'released_by' => $this->owner()->id,
            ])->save();
        });

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->place($line->fresh(), $this->owner(), 'PIPE'));
    }

    public function test_an_archived_project_cannot_be_modelled(): void
    {
        $line = $this->line('6');
        $project = $this->project();
        $owner = $this->owner();

        app(ProjectArchiveService::class)->archive($project, $owner, 'Closed out');

        try {
            $this->expectException(ValidationException::class);
            $this->asOwner(fn () => $this->svc()->place($line, $owner, 'PIPE'));
        } finally {
            app(ProjectArchiveService::class)->reopen($project->fresh(), $owner);
        }
    }

    // ------------------------------------------------------- the template --

    public function test_an_unknown_template_is_a_validation_error(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'FLUX_CAPACITOR'));
    }

    public function test_a_nozzle_cannot_be_placed_on_a_line(): void
    {
        // A nozzle belongs to equipment (ADR-010 §3). It is in the pack
        // because a nozzle port is still a port, but placing one mid-line
        // would model a pipe running into thin air.
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'NOZZLE'));
    }

    public function test_the_nozzle_is_not_offered_as_placeable(): void
    {
        $codes = array_column($this->svc()->placeableTemplates(), 'code');

        $this->assertNotContains('NOZZLE', $codes);
        $this->assertContains('TEE', $codes);
    }

    public function test_a_length_is_kept_on_a_pipe_and_dropped_on_a_tee(): void
    {
        // A tee has no length; a number in that column would be uninterpretable
        // to every downstream report.
        $line = $this->line('6');

        [$pipe, $tee] = $this->asOwner(fn () => [
            $this->svc()->place($line, $this->owner(), 'PIPE', ['length_mm' => 6000]),
            $this->svc()->place($line, $this->owner(), 'TEE', ['length_mm' => 6000]),
        ]);

        $this->assertEqualsWithDelta(6000.0, (float) $pipe->length_mm, 1e-6);
        $this->assertNull($tee->length_mm);
    }

    // ------------------------------------------------- database backstops --

    public function test_the_database_refuses_a_port_with_two_owners(): void
    {
        // ADR-010 §3.1: one ports table is only safe because the mixed-owner
        // row is unrepresentable.
        $line = $this->line('6');

        $component = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->expectException(QueryException::class);

        $this->asOwner(fn () => DB::table('ports')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $component->project_id,
            'company_id' => $component->company_id,
            'owner_kind' => 'COMPONENT',
            'component_id' => $component->id,
            'nozzle_id' => (string) Str::uuid(),
            'seq' => 9,
            'role' => 'RUN',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_the_database_refuses_a_port_with_no_owner(): void
    {
        $line = $this->line('6');
        $component = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->expectException(QueryException::class);

        $this->asOwner(fn () => DB::table('ports')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $component->project_id,
            'company_id' => $component->company_id,
            'owner_kind' => 'COMPONENT',
            'component_id' => null,
            'nozzle_id' => null,
            'seq' => 9,
            'role' => 'RUN',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_the_database_refuses_a_duplicate_port_number(): void
    {
        $line = $this->line('6');
        $component = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->expectException(QueryException::class);

        $this->asOwner(fn () => DB::table('ports')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $component->project_id,
            'company_id' => $component->company_id,
            'owner_kind' => 'COMPONENT',
            'component_id' => $component->id,
            'seq' => 1,
            'role' => 'RUN',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_the_database_refuses_a_half_written_size(): void
    {
        // A decimal with no NPS string cannot be displayed; an NPS string with
        // no decimal cannot be compared.
        $line = $this->line('6');
        $component = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->expectException(QueryException::class);

        $this->asOwner(fn () => DB::table('components')
            ->where('id', $component->id)
            ->update(['nominal_size_decimal' => null]));
    }

    public function test_the_database_refuses_a_duplicate_sequence_on_a_line(): void
    {
        $line = $this->line('6');
        $component = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->expectException(QueryException::class);

        $this->asOwner(fn () => DB::table('components')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $component->project_id,
            'company_id' => $component->company_id,
            'piping_line_id' => $component->piping_line_id,
            'template_code' => 'ELBOW',
            'sequence' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_the_database_rejects_an_unsupported_elbow_qualifier(): void
    {
        $line = $this->line('6');
        $elbow = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'ELBOW', [
            'fitting_qualifier' => '90LR',
        ]));

        try {
            $this->asOwner(fn () => DB::table('components')->where('id', $elbow->id)
                ->update(['fitting_qualifier' => '90SR']));
            $this->fail('The database must not admit an unsupported elbow qualifier.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('components_fitting_qualifier_scope_valid', $exception->getMessage());
        }
    }

    // ------------------------------------------------------------- HTTP --

    public function test_bw_elbow_qualifier_flows_through_http_and_the_model_page(): void
    {
        $line = $this->line('6');
        $url = "/projects/PH-14/piping/{$line->id}/model?lang=en";

        $this->actingAs($this->owner())
            ->post($url, ['template_code' => 'ELBOW', 'nominal_size' => '6'])
            ->assertRedirect()
            ->assertSessionHasErrors('fitting_qualifier');

        $this->actingAs($this->owner())
            ->post($url, [
                'template_code' => 'ELBOW',
                'fitting_qualifier' => '90LR',
                'nominal_size' => '6',
            ])
            ->assertRedirect();

        $elbow = $this->asOwner(fn () => Component::query()
            ->where('piping_line_id', $line->id)
            ->where('template_code', 'ELBOW')
            ->firstOrFail());
        $this->assertSame('90LR', $elbow->fitting_qualifier);

        $this->actingAs($this->owner())->get($url)
            ->assertOk()
            ->assertSee('90LR')
            ->assertSee('Variant / bend angle')
            ->assertSee('name="fitting_standard"', false)
            ->assertSee('name="fitting_qualifier"', false)
            ->assertSee('name="fitting_class_designation"', false)
            ->assertSee('name="fitting_end_type"', false)
            ->assertSee('B16.9: 90LR or 45LR only.')
            ->assertSee('B16.11 does not name LR/SR elbows');
    }

    public function test_b16_11_elbow_specification_flows_through_http_without_pms_or_default_inference(): void
    {
        $line = $this->line('6');
        $url = "/projects/PH-14/piping/{$line->id}/model?lang=en";

        $this->actingAs($this->owner())
            ->post($url, [
                'template_code' => 'ELBOW',
                'nominal_size' => '1/2',
                'fitting_standard' => 'B16.11',
                'fitting_qualifier' => '90',
                'fitting_end_type' => 'SW',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('fitting_class_designation');

        $this->actingAs($this->owner())
            ->post($url, [
                'template_code' => 'ELBOW',
                'nominal_size' => '1/2',
                'fitting_standard' => 'B16.11',
                'fitting_qualifier' => '90',
                'fitting_class_designation' => '3000',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('fitting_end_type');

        $this->actingAs($this->owner())
            ->post($url, [
                'template_code' => 'ELBOW',
                'nominal_size' => '1/2',
                'fitting_qualifier' => '90',
                'fitting_class_designation' => '3000',
                'fitting_end_type' => 'SW',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('fitting_standard');

        $this->actingAs($this->owner())
            ->post($url, [
                'template_code' => 'ELBOW',
                'nominal_size' => '1/2',
                'fitting_standard' => 'B16.11',
                'fitting_qualifier' => '90',
                'fitting_class_designation' => '3000',
                'fitting_end_type' => 'SW',
            ])
            ->assertRedirect();

        $elbow = $this->asOwner(fn () => Component::query()
            ->with('ports')
            ->where('piping_line_id', $line->id)
            ->where('template_code', 'ELBOW')
            ->firstOrFail());
        $this->assertSame('B16.11', $elbow->fitting_standard);
        $this->assertSame(3000, $elbow->fitting_class_designation);
        $this->assertSame(['SW', 'SW'], $elbow->ports->pluck('end_type')->all());

        // Exercise the separate attach form/controller mapping too. A plain
        // pipe end is a valid field-weld mate for the new socket port; this
        // verifies the explicit fitting_end_type reaches both child ports.
        $plainPipe = $this->asOwner(fn () => $this->svc()->place(
            $line,
            $this->owner(),
            'PIPE',
            ['nominal_size' => '1/2', 'end_type' => 'PL'],
        ));
        $attachUrl = "/projects/PH-14/piping/{$line->id}/model/attach?lang=en";
        $this->actingAs($this->owner())
            ->post($attachUrl, [
                'port_id' => $plainPipe->ports->first()->id,
                'template_code' => 'ELBOW',
                'fitting_standard' => 'B16.11',
                'fitting_qualifier' => '45',
                'fitting_class_designation' => '3000',
                'fitting_end_type' => 'SW',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $attachedElbow = $this->asOwner(fn () => Component::query()
            ->with('ports')
            ->where('piping_line_id', $line->id)
            ->where('template_code', 'ELBOW')
            ->where('sequence', '>', $elbow->sequence)
            ->firstOrFail());
        $this->assertSame('B16.11', $attachedElbow->fitting_standard);
        $this->assertSame('45', $attachedElbow->fitting_qualifier);
        $this->assertSame(3000, $attachedElbow->fitting_class_designation);
        $this->assertSame(['SW', 'SW'], $attachedElbow->ports->pluck('end_type')->all());
    }

    public function test_the_model_page_renders_components_and_open_ends(): void
    {
        $line = $this->line('6');

        $this->asOwner(function () use ($line) {
            $tee = $this->svc()->place($line, $this->owner(), 'TEE', ['port_sizes' => [3 => '2']]);
            $this->svc()->attach($tee->ports->firstWhere('role', 'BRANCH'), $this->owner(), 'PIPE');
        });

        $html = $this->actingAs($this->owner())
            ->get("/projects/PH-14/piping/{$line->id}/model?lang=en")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('TEE', $html);
        $this->assertStringContainsString('Issued PMS revision', $html);
        $this->assertStringContainsString('PH-14-PIP-PMS-A1', $html);
        $this->assertStringContainsString('Schedule', $html);
        // Assert the PIPE schedule and status, not the obsolete exact HTML cell.
        $document = new \DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html);
        $cells = (new \DOMXPath($document))->query('//tr[td[2]//div[normalize-space(.)="PIPE"]]/td[4]');
        $this->assertCount(1, $cells);
        $this->assertMatchesRegularExpression('/^80\b/', trim($cells->item(0)->textContent));
        $this->assertStringContainsString('Matches issued PMS schedule', $cells->item(0)->textContent);
        $this->assertStringContainsString('Open ends', $html);
        // Three ports remain open: the tee's two runs and the pipe's far end.
        $this->assertStringContainsString('(3)', $html);
    }

    public function test_model_page_flags_explicit_pipe_schedule_difference_against_issued_pms(): void
    {
        $line = $this->line('6');
        $this->asOwner(function () use ($line): void {
            $this->svc()->place($line, $this->owner(), 'PIPE', ['schedule' => '40']);
            $this->svc()->place($line, $this->owner(), 'PIPE');
        });

        $this->actingAs($this->owner())
            ->get("/projects/PH-14/piping/{$line->id}/model?lang=en")
            ->assertOk()
            ->assertSee('Differs from issued PMS schedule')
            ->assertSee('Expected: 80')
            ->assertSee('Matches issued PMS schedule');
    }

    public function test_schedule_exception_audit_requires_reason_and_independent_decision_and_is_append_only(): void
    {
        $line = $this->line('6');
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE', ['schedule' => '40']));
        $actor = $this->owner();
        $id = (string) Str::uuid();
        $request = [
            'id' => $id, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '80', 'actual_schedule' => '40',
            'reason' => 'Approved alternative pending engineering review', 'requested_by' => $actor->id,
        ];
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert($request));
        try {
            $this->asOwner(fn () => DB::transaction(fn () => DB::table('pipe_schedule_exception_requests')
                ->where('id', $id)->update(['reason' => 'Changed after submission'])));
            $this->fail('Audit reason must be immutable.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        try {
            $this->asOwner(fn () => DB::transaction(fn () => DB::table('pipe_schedule_exception_decisions')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $line->project_id, 'company_id' => $actor->company_id,
                'request_id' => $id, 'decision' => 'APPROVED', 'note' => 'Looks acceptable',
                'decided_by' => $actor->id,
            ])));
            $this->fail('The requester must not approve their own exception.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $this->assertSame(0, $this->asOwner(fn () => DB::table('pipe_schedule_exception_decisions')->count()));
    }

    public function test_offline_signature_metadata_keeps_signer_separate_from_uploader_and_refuses_self_verification(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']));
        $requestId = (string) Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => $requestId, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '80', 'actual_schedule' => '40',
            'reason' => 'Reviewed outside the application by contractor', 'requested_by' => $actor->id,
        ]));
        $signerCompany = Company::where('code', 'EPC-PARS')->firstOrFail();
        $signatureId = (string) Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_external_signatures')->insert([
            'id' => $signatureId, 'request_id' => $requestId,
            'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'signer_company_id' => $signerCompany->id, 'signer_name' => 'Contractor engineer',
            'signer_capacity' => 'Authorized project signatory',
            'document_number' => 'PH14-EXT-001', 'document_revision' => 'A',
            'file_sha256' => str_repeat('a', 64), 'signed_at' => now(), 'uploaded_by' => $actor->id,
        ]));
        $this->assertSame($signerCompany->id, $this->asOwner(fn () => DB::table('pipe_schedule_external_signatures')
            ->where('id', $signatureId)->value('signer_company_id')));
        $metadataOnlyId = (string) Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_external_signatures')->insert([
            'id' => $metadataOnlyId, 'request_id' => $requestId,
            'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'signer_company_id' => $signerCompany->id, 'signer_name' => 'Contractor engineer',
            'signer_capacity' => 'Authorized project signatory',
            'document_number' => 'PH14-EXT-002', 'signed_at' => now(), 'uploaded_by' => $actor->id,
        ]));
        $this->assertNull($this->asOwner(fn () => DB::table('pipe_schedule_external_signatures')
            ->where('id', $metadataOnlyId)->value('file_sha256')));
        try {
            $this->asOwner(fn () => DB::transaction(fn () => DB::table('pipe_schedule_signature_verifications')->insert([
                'id' => (string) Str::uuid(), 'signature_id' => $signatureId,
                'project_id' => $line->project_id, 'company_id' => $actor->company_id,
                'decision' => 'VERIFIED', 'authority_basis' => 'PH14 contract appointment',
                'note' => 'Signature checked', 'verified_by' => $actor->id,
            ])));
            $this->fail('The uploader cannot verify their own uploaded signature.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_tq_ncr_register_keeps_internal_draft_distinct_from_externally_issued_metadata(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']));
        $requestId = (string) Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => $requestId, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '80', 'actual_schedule' => '40',
            'reason' => 'Technical query required for schedule deviation', 'requested_by' => $actor->id,
        ]));
        $base = ['request_id' => $requestId, 'project_id' => $line->project_id,
            'company_id' => $actor->company_id, 'recorded_by' => $actor->id];
        $this->asOwner(fn () => DB::table('pipe_schedule_discrepancy_reports')->insert($base + [
            'id' => (string) Str::uuid(), 'kind' => 'TQ',
            'origin' => 'IN_SYSTEM', 'state' => 'DRAFT',
        ]));
        $this->asOwner(fn () => DB::table('pipe_schedule_discrepancy_reports')->insert($base + [
            'id' => (string) Str::uuid(), 'kind' => 'NCR',
            'origin' => 'EXTERNAL_REGISTER', 'state' => 'EXTERNAL_RECORDED',
            'report_number' => 'EXT-NCR-42', 'reported_issuer' => 'Project contractor',
            'reported_signer' => 'External engineer', 'reported_signer_capacity' => 'NCR signatory',
            'reported_issued_at' => now(), 'reported_signed_at' => now(),
            'external_register_ref' => 'Contractor register 2026 / NCR 42',
        ]));
        $this->assertSame(2, $this->asOwner(fn () => DB::table('pipe_schedule_discrepancy_reports')->count()));
        foreach ([
            ['kind' => 'TQ', 'origin' => 'IN_SYSTEM', 'state' => 'EXTERNAL_RECORDED'],
            ['kind' => 'NCR', 'origin' => 'EXTERNAL_REGISTER', 'state' => 'EXTERNAL_RECORDED',
                'report_number' => 'EXT-NCR-43', 'reported_issuer' => 'Project contractor',
                'reported_signer' => 'External engineer', 'reported_signer_capacity' => 'NCR signatory',
                'reported_issued_at' => now(), 'reported_signed_at' => now()],
        ] as $invalid) {
            try {
                $this->asOwner(fn () => DB::transaction(fn () => DB::table('pipe_schedule_discrepancy_reports')
                    ->insert($base + ['id' => (string) Str::uuid()] + $invalid)));
                $this->fail('Incomplete external metadata or forged internal issue must be refused.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_report_service_records_only_internal_drafts_or_unverified_external_metadata(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']));
        $requestId = (string) Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => $requestId, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '80', 'actual_schedule' => '40',
            'nps_at_request' => '6', 'dn_at_request' => 150,
            'reason' => 'Reference conflict needs independent resolution', 'requested_by' => $actor->id,
        ]));
        $service = app(PipeScheduleReportService::class);
        $draft = $service->draft($this->project(), $actor, $requestId, 'TQ');
        $this->assertSame('DRAFT', $draft->state);
        $this->assertNull($draft->report_number);
        $externalInput = [
            'report_number' => ' NCR-EXT-42 ', 'report_revision' => '   ',
            'reported_issuer' => 'Contractor QC', 'reported_signer' => 'Authorized engineer',
            'reported_signer_capacity' => 'QC signatory',
            'reported_issued_at' => '2026-10-14', 'reported_signed_at' => '2026-10-14',
            'external_register_ref' => 'Contractor NCR register / 42',
        ];
        $external = $service->registerExternal($this->project(), $actor, $requestId, 'NCR', $externalInput);
        $this->assertSame('EXTERNAL_RECORDED', $external->state);
        $this->assertSame('NCR-EXT-42', $external->report_number);
        $this->assertNull($external->report_revision);
        foreach (['report_number', 'reported_issuer', 'reported_signer',
            'reported_signer_capacity', 'external_register_ref'] as $field) {
            try {
                $service->registerExternal($this->project(), $actor, $requestId, 'NCR',
                    array_replace($externalInput, [$field => '   ']));
                $this->fail("Whitespace-only $field must be a validation error, not a SQL exception.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $forgedId = (string) Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => $forgedId, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '60', 'actual_schedule' => '40',
            'nps_at_request' => '6', 'dn_at_request' => 150,
            'reason' => 'Incorrect claimed PMS schedule entered by raw SQL', 'requested_by' => $actor->id,
        ]));
        try {
            $service->draft($this->project(), $actor, $forgedId, 'TQ');
            $this->fail('A forged expected PMS schedule must not create an internal TQ draft.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request_id', $e->errors());
        }
        try {
            $service->registerExternal($this->project(), $actor, $requestId, 'TQ', [
                'report_number' => 'TQ-EXT-43', 'reported_issuer' => 'Contractor QC',
                'reported_signer' => 'Authorized engineer',
                'reported_signer_capacity' => 'QC signatory',
                'reported_issued_at' => '2026-10-14', 'reported_signed_at' => '2026-10-14',
            ]);
            $this->fail('An external report without its source register must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('external_register_ref', $e->errors());
        }
        $this->assertSame(2, $this->asOwner(fn () => DB::table('pipe_schedule_discrepancy_reports')->count()));
        $this->asOwner(fn () => DB::table('components')->where('id', $pipe->id)->update(['schedule' => '60']));
        try {
            $service->draft($this->project(), $actor, $requestId, 'TQ');
            $this->fail('A new internal TQ draft must not use a stale schedule request.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request_id', $e->errors());
        }
        $historical = $service->registerExternal($this->project(), $actor, $requestId, 'TQ', [
            'report_number' => 'TQ-EXT-OLD', 'reported_issuer' => 'Contractor engineering',
            'reported_signer' => 'External engineer',
            'reported_signer_capacity' => 'Engineering signatory',
            'reported_issued_at' => '2026-10-14', 'reported_signed_at' => '2026-10-14',
            'external_register_ref' => 'Contractor TQ register / old value',
        ]);
        $this->assertSame('EXTERNAL_RECORDED', $historical->state);
        $this->assertSame(3, $this->asOwner(fn () => DB::table('pipe_schedule_discrepancy_reports')->count()));
    }

    public function test_report_capture_needs_its_own_capability_and_piping_scope(): void
    {
        $service = app(PipeScheduleReportService::class);
        $project = $this->project();
        $requestId = (string) Str::uuid();
        $civilEngineer = $this->userByEmail('mousavi@sahand.example');
        try {
            $service->draft($project, $civilEngineer, $requestId, 'TQ');
            $this->fail('A CIVIL-only project member cannot capture PIPING reports.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $role = Role::where('code', 'employer_rep')->firstOrFail();
        $role->update(['capabilities' => ['project.view']]);
        try {
            $service->draft($project, $this->owner(), $requestId, 'TQ');
            $this->fail('Project viewing alone must not grant report capture.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_schedule_request_recomputes_issued_value_and_refuses_matching_or_duplicate_pipes(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        [$matching, $different] = $this->asOwner(fn () => [
            $this->svc()->place($line, $actor, 'PIPE'),
            $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']),
        ]);
        $requests = app(PipeScheduleExceptionRequestService::class);
        try {
            $requests->submit($this->project(), $actor, $matching->id, 'Schedule differs from contract PMS');
            $this->fail('A pipe matching the issued PMS cannot request a difference.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('component_id', $e->errors());
        }
        $record = $requests->submit($this->project(), $actor, $different->id, 'Schedule differs from contract PMS');
        $this->assertSame($line->pms_class_snapshot_id, $record->pms_class_snapshot_id);
        $this->assertSame('80', $record->expected_schedule);
        $this->assertSame('40', $record->actual_schedule);
        $this->assertSame($actor->id, $record->requested_by);
        try {
            $requests->submit($this->project(), $actor, $different->id, 'Repeated request without a decision');
            $this->fail('An identical unresolved request must not be duplicated.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('component_id', $e->errors());
        }
        $this->assertSame(1, $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->count()));
        $this->assertSame('6', $record->nps_at_request);
        $this->assertSame(150, (int) $record->dn_at_request);
        $this->asOwner(function () use ($different): void {
            DB::table('components')->where('id', $different->id)
                ->update(['nominal_size' => '8', 'nominal_size_decimal' => 8.0]);
            DB::table('ports')->where('component_id', $different->id)
                ->update(['nominal_size' => '8', 'nominal_size_decimal' => 8.0]);
        });
        $this->actingAs($actor)->get("/projects/PH-14/piping/{$line->id}/model?lang=en")
            ->assertOk()->assertSee('Request discrepancy review')
            ->assertSee('Previous value; not applicable to current schedule');
        $next = $requests->submit($this->project(), $actor, $different->id,
            'Pipe size changed; this is a different schedule discrepancy');
        $this->assertSame('8', $next->nps_at_request);
        $this->assertSame(200, (int) $next->dn_at_request);
        $this->assertSame(2, $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->count()));
        $this->actingAs($actor)->get("/projects/PH-14/piping/{$line->id}/model?lang=en")
            ->assertOk()->assertSee('Previous value; not applicable to current schedule');
        try {
            app(PipeScheduleReportService::class)
                ->draft($this->project(), $actor, $record->id, 'TQ');
            $this->fail('The old DN150 request cannot seed a DN200 internal draft.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('request_id', $e->errors());
        }
    }

    public function test_false_expected_pms_request_cannot_mask_a_valid_schedule_discrepancy(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']));
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => (string) Str::uuid(),
            'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '60', 'actual_schedule' => '40',
            'nps_at_request' => '6', 'dn_at_request' => 150,
            'reason' => 'An invalid expected PMS value entered outside the service',
            'requested_by' => $actor->id,
        ]));
        $url = "/projects/PH-14/piping/{$line->id}/model?lang=en";
        $this->actingAs($actor)->get($url)->assertOk()
            ->assertSee('Request PMS value does not match the current issued basis')
            ->assertSee('Request discrepancy review')
            ->assertDontSee('/draft?lang=en', false);
        $valid = app(PipeScheduleExceptionRequestService::class)
            ->submit($this->project(), $actor, $pipe->id, 'Review against the real issued PMS schedule');
        $this->assertSame('80', $valid->expected_schedule);
        $this->assertSame(2, $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->count()));
    }

    public function test_internal_taper_is_only_a_proposal_for_a_thicker_reference_wall(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        [$thin, $thick] = $this->asOwner(fn () => [
            $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']),
            $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '160']),
        ]);
        $requests = app(PipeScheduleExceptionRequestService::class);
        try {
            $requests->submit($this->project(), $actor, $thin->id,
                'Plan an internal transition for this pipe', 'INTERNAL_TAPER_PROPOSED');
            $this->fail('Thinner pipe cannot claim a thicker-wall taper route.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('proposed_remedy', $e->errors());
        }
        $record = $requests->submit($this->project(), $actor, $thick->id,
            'Propose internal taper subject to future ITP Fit-up', 'INTERNAL_TAPER_PROPOSED');
        $this->assertSame('INTERNAL_TAPER_PROPOSED', $record->proposed_remedy);
        $this->assertSame('80', $record->expected_schedule);
        $this->assertSame('160', $record->actual_schedule);
        $this->assertSame(0, $this->asOwner(fn () => DB::table('pipe_schedule_exception_decisions')->count()));
        $this->actingAs($actor)->get("/projects/PH-14/piping/{$line->id}/model?lang=en")
            ->assertOk()->assertSee('Proposed internal taper (plan only; not Fit-up acceptance)');
    }

    public function test_model_page_submits_schedule_discrepancy_reason_without_approving_it(): void
    {
        $line = $this->line('6');
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE', ['schedule' => '40']));
        $url = "/projects/PH-14/piping/{$line->id}/model";
        $this->actingAs($this->owner())->get($url.'?lang=en')->assertOk()
            ->assertSee('Request discrepancy review');
        $this->actingAs($this->owner())->post($url."/{$pipe->id}/request-review?lang=en", [
            'reason' => 'Schedule differs from issued PMS and needs review',
        ])->assertRedirect($url.'?lang=en');
        $request = $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')
            ->where('component_id', $pipe->id)->first());
        $this->assertNotNull($request);
        $this->assertSame('80', $request->expected_schedule);
        $this->assertSame('40', $request->actual_schedule);
        $reportUrl = $url."/reports/{$request->id}";
        $this->actingAs($this->owner())->post($reportUrl.'/draft?lang=en', [
            'kind' => 'TQ',
        ])->assertRedirect($url.'?lang=en')
            ->assertSessionHas('status', 'Internal draft saved; not issued or approved.');
        $this->actingAs($this->owner())->get($url.'?lang=en')->assertOk()
            ->assertSee('role="status"', false)
            ->assertSee('Internal draft saved; not issued or approved.');
        $this->actingAs($this->owner())->post($reportUrl.'/draft?lang=en', [
            'kind' => 'TQ',
        ])->assertRedirect($url.'?lang=en')->assertSessionHasErrors('kind');
        $this->actingAs($this->owner())->post($reportUrl.'/external?lang=en', [
            'kind' => 'NCR', 'report_number' => 'EXT-NCR-42',
            'reported_issuer' => 'Contractor QC', 'reported_signer' => 'External engineer',
            'reported_signer_capacity' => 'NCR signatory',
            'reported_issued_at' => '2026-10-14', 'reported_signed_at' => '2026-10-14',
            'external_register_ref' => 'Contractor NCR register / 42',
        ])->assertRedirect($url.'?lang=en')
            ->assertSessionHas('status', 'External report metadata saved; report and signature not verified.');
        $this->actingAs($this->owner())->get($url.'?lang=en')->assertOk()
            ->assertSee('External report metadata saved; report and signature not verified.');
        $this->actingAs($this->owner())->post($reportUrl.'/external?lang=en', [
            'kind' => 'NCR', 'report_number' => '   ',
            'reported_issuer' => 'Contractor QC', 'reported_signer' => 'External engineer',
            'reported_signer_capacity' => 'NCR signatory',
            'reported_issued_at' => '2026-10-14', 'reported_signed_at' => '2026-10-14',
            'external_register_ref' => 'Contractor NCR register / 42',
        ])->assertRedirect($url.'?lang=en')->assertSessionHasErrors('report_number');
        $this->actingAs($this->owner())->post('/projects/PH-14/piping/'.Str::uuid()."/model/reports/{$request->id}/draft?lang=en", [
            'kind' => 'TQ',
        ])->assertNotFound();
        $this->actingAs($this->owner())->get($url.'?lang=en')->assertOk()
            ->assertSee('Discrepancy request history')
            ->assertSee('Internal draft; not issued')
            ->assertSee('External report metadata; not verified')
            ->assertSee('Recorded details (external claim; not verified)')
            ->assertSee('EXT-NCR-42')
            ->assertSee('Contractor QC')
            ->assertSee('External engineer')
            ->assertSee('NCR signatory')
            ->assertSee('2026-10-14')
            ->assertSee('Contractor NCR register / 42')
            ->assertSee('System recorder ID (not issuer)')
            ->assertSee('Review requested; not approved')
            ->assertDontSee('Request discrepancy review');
        $this->actingAs($this->owner())->post($url."/{$pipe->id}/request-review?lang=en", [
            'reason' => 'Duplicate report for same discrepancy',
        ])->assertRedirect($url.'?lang=en')->assertSessionHasErrors('component_id');
        $this->actingAs($this->owner())->post($url.'/'.Str::uuid().'/request-review?lang=en', [
            'reason' => 'Attempt to use an unrelated component identifier',
        ])->assertNotFound();
        $this->asOwner(fn () => DB::table('components')->where('id', $pipe->id)->update(['schedule' => '60']));
        $this->actingAs($this->owner())->post($reportUrl.'/draft?lang=en', [
            'kind' => 'TQ',
        ])->assertRedirect($url.'?lang=en')->assertSessionHasErrors('request_id');
        $this->actingAs($this->owner())->get($url.'?lang=en')->assertOk()
            ->assertSee('Request discrepancy review')
            ->assertSee('Previous value; not applicable to current schedule')
            ->assertSee('Schedule differs from issued PMS and needs review');
        $this->actingAs($this->owner())->post($url."/{$pipe->id}/request-review?lang=en", [
            'reason' => 'Schedule changed again; a new review is needed',
        ])->assertRedirect($url.'?lang=en');
        $this->assertSame(2, $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')
            ->where('component_id', $pipe->id)->count()));
    }

    public function test_the_register_links_to_the_model_page(): void
    {
        // A screen nobody can reach is a screen that does not exist.
        $line = $this->line('6');

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping?lang=en')
            ->assertOk()
            ->assertSee("/projects/PH-14/piping/{$line->id}/model", false);
    }

    public function test_placing_a_component_over_http(): void
    {
        $line = $this->line('6');

        $this->actingAs($this->owner())
            ->post("/projects/PH-14/piping/{$line->id}/model?lang=en", [
                'template_code' => 'TEE',
                'nominal_size' => '6',
                'branch_size' => '2',
            ])
            ->assertRedirect();

        $ports = $this->asOwner(
            fn () => Component::with('ports')->where('piping_line_id', $line->id)->first()->ports,
        );

        $this->assertSame(['6', '6', '2'], $ports->pluck('nominal_size')->all());
    }

    public function test_the_branch_box_does_not_leak_onto_a_reducer(): void
    {
        // The form's "branch size" and "small end" boxes are mapped by ROLE,
        // not by port number: the branch is port 3 on a tee but port 2 on an
        // olet, and a reducer has no branch at all. A positional guess would
        // put a branch size on a reducer's large port.
        $line = $this->line('6');

        $this->actingAs($this->owner())
            ->post("/projects/PH-14/piping/{$line->id}/model?lang=en", [
                'template_code' => 'REDUCER',
                'nominal_size' => '6',
                'branch_size' => '2',     // nonsense for a reducer - must be ignored
                'small_size' => '4',
            ])
            ->assertRedirect();

        $ports = $this->asOwner(
            fn () => Component::with('ports')->where('piping_line_id', $line->id)->first()->ports,
        );

        $this->assertSame(['6', '4'], $ports->pluck('nominal_size')->all());
    }

    public function test_attaching_over_http_closes_the_port(): void
    {
        $line = $this->line('6');

        $port = $this->asOwner(
            fn () => $this->svc()->place($line, $this->owner(), 'TEE')->ports->firstWhere('seq', 1),
        );

        $this->actingAs($this->owner())
            ->post("/projects/PH-14/piping/{$line->id}/model/attach?lang=en", [
                'port_id' => $port->id,
                'template_code' => 'ELBOW',
                'fitting_qualifier' => '90LR',
            ])
            ->assertRedirect();

        $this->assertTrue($this->asOwner(fn () => Port::findOrFail($port->id))->is_connected);
    }

    public function test_a_bad_size_over_http_is_a_field_error_not_a_crash(): void
    {
        $line = $this->line('6');

        $this->actingAs($this->owner())
            ->post("/projects/PH-14/piping/{$line->id}/model?lang=en", [
                'template_code' => 'TEE',
                'nominal_size' => '6',
                'branch_size' => '12',   // branch larger than the run
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('port_sizes');
    }

    public function test_one_line_cannot_delete_another_lines_component(): void
    {
        // The component id is in the URL, so a hand-edited path must not reach
        // across lines.
        $a = $this->line('6');
        $b = $this->line('4');

        $victim = $this->asOwner(fn () => $this->svc()->place($b, $this->owner(), 'PIPE'));

        $this->actingAs($this->owner())
            ->post("/projects/PH-14/piping/{$a->id}/model/{$victim->id}/delete?lang=en")
            ->assertNotFound();

        $this->assertSame(1, $this->asOwner(fn () => Component::query()->whereKey($victim->id)->count()));
    }

    public function test_a_non_participant_cannot_see_the_model_page(): void
    {
        $line = $this->line('6');

        $this->actingAs($this->userByEmail('hosseini@arvand.example'))
            ->get("/projects/PH-14/piping/{$line->id}/model?lang=en")
            ->assertNotFound();
    }

    public function test_manual_pose_saves_only_in_draft_and_appends_a_second_revision(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line);
        $frame = $this->modelFrame();
        $service = $this->poseService();

        $first = $service->recordManual($project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [1000, 2000, 300]);
        $second = $service->recordManual($project, $line, $pipe, $frame, $owner, 90.0, 0.0, 30.0, [1000, 2000, 300]);

        $this->assertSame(1, $first->revision);
        $this->assertSame(2, $second->revision);
        $this->assertSame(2, $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('component_id', $pipe->id)->count()));

        $current = $service->currentGeometry($project, $owner, $pipe);
        $this->assertSame('RESOLVED', $current['status']);
        $this->assertSame(2, $current['pose_revision']);
        $this->assertEqualsWithDelta(1000.0, $current['ports'][1]['point_model'][0], 0.001);
        $this->assertEqualsWithDelta(5000.0, $current['ports'][1]['point_model'][1], 0.001);
        $this->assertEqualsWithDelta(300.0, $current['ports'][1]['point_model'][2], 0.001);

        // A participating contractor can read the same project frame and pose
        // snapshot; the RLS policy is project-participant scoped, not owner-only.
        $contractor = $this->userByEmail('rezaei@pars.example');
        $sharedView = $service->currentGeometry($project, $contractor, $pipe);
        $this->assertSame(2, $sharedView['pose_revision']);
    }

    public function test_explicit_length_nipple_pose_resolves_and_length_change_marks_it_stale(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $nipple = $this->asOwner(fn () => $this->svc()->place(
            $line,
            $owner,
            'NIPPLE',
            ['length_mm' => 180.0],
        ));
        $frame = $this->modelFrame();
        $service = $this->poseService();

        $first = $service->recordManual(
            $project,
            $line,
            $nipple,
            $frame,
            $owner,
            0.0,
            0.0,
            45.0,
            [100, 200, 300],
        );

        $this->assertSame('STRAIGHT_NIPPLE_TWO_PORTS_V1', $first->geometry_profile_id);
        $this->assertSame('RESOLVED', $first->geometry_status);
        $this->assertSame(['RUN', 'RUN'], array_column($first->resolved_port_geometry['ports'], 'role'));
        $this->assertEquals([100.0, 200.0, 300.0], $first->resolved_port_geometry['ports'][0]['point_model']);
        $this->assertEquals([280.0, 200.0, 300.0], $first->resolved_port_geometry['ports'][1]['point_model']);

        $this->asOwner(fn () => DB::table('components')
            ->where('id', $nipple->id)
            ->update(['length_mm' => 240.0]));

        $stale = $service->currentGeometry($project, $owner, $nipple);
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('GEOMETRY_PROFILE_CHANGED', $stale['reason']);
        $this->assertSame([], $stale['ports']);

        $second = $service->recordManual(
            $project,
            $line,
            $nipple,
            $frame,
            $owner,
            0.0,
            0.0,
            0.0,
            [100, 200, 300],
        );
        $this->assertSame(2, $second->revision);
        $this->assertEquals([340.0, 200.0, 300.0], $second->resolved_port_geometry['ports'][1]['point_model']);

        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('RESOLVED')
            ->assertSee('P1 RUN')
            ->assertSee('NIPPLE length is end-to-end between port centre planes')
            ->assertSee('name="azimuth_deg"', false);
    }

    public function test_bw_long_radius_elbow_pose_uses_b16_9_and_qualifier_changes_make_it_stale(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line('6');
        $elbow = $this->asOwner(fn () => $this->svc()->place(
            $line,
            $owner,
            'ELBOW',
            ['fitting_qualifier' => '90LR'],
        ));
        $this->assertSame('90LR', $elbow->fitting_qualifier);
        $frame = $this->modelFrame();
        $poses = $this->poseService();

        $first = $poses->recordManual($project, $line, $elbow, $frame, $owner, 0.0, 0.0, 0.0, [100, 200, 300]);
        $this->assertSame('ASME_B16_9_BW_LR_ELBOW_TWO_PORTS_V1', $first->geometry_profile_id);
        $this->assertSame('RESOLVED', $first->geometry_status);
        $this->assertEqualsWithDelta(229.0, (float) $first->resolved_port_geometry['profile_parameters']['center_to_end_mm'], 1e-9);
        $this->assertSame('A', $first->resolved_port_geometry['profile_parameters']['dimension_symbol']);
        $this->assertEquals([329.0, 429.0, 300.0], $first->resolved_port_geometry['ports'][1]['point_model']);

        $this->asOwner(fn () => DB::table('components')->where('id', $elbow->id)
            ->update(['fitting_qualifier' => '45LR']));
        $stale = $poses->currentGeometry($project, $owner, $elbow);
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('GEOMETRY_PROFILE_CHANGED', $stale['reason']);
        $this->assertSame([], $stale['ports']);

        $second = $poses->recordManual($project, $line, $elbow, $frame, $owner, 0.0, 0.0, 90.0, [100, 200, 300]);
        $this->assertSame(2, $second->revision);
        $this->assertEqualsWithDelta(95.0, (float) $second->resolved_port_geometry['profile_parameters']['center_to_end_mm'], 1e-9);
        $this->assertSame('B', $second->resolved_port_geometry['profile_parameters']['dimension_symbol']);
        $this->assertEqualsWithDelta(100 + 95 * (1 + sqrt(0.5)), $second->resolved_port_geometry['ports'][1]['point_model'][0], 1e-8);
        $this->assertEqualsWithDelta(200.0, $second->resolved_port_geometry['ports'][1]['point_model'][1], 1e-8);
        $this->assertEqualsWithDelta(300 + 95 * sqrt(0.5), $second->resolved_port_geometry['ports'][1]['point_model'][2], 1e-8);

        $current = $poses->currentGeometry($project, $owner, $elbow);
        $this->assertSame('RESOLVED', $current['status']);
        $this->assertSame(2, $current['pose_revision']);

        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('45LR')
            ->assertSee('B16.9-2018')
            ->assertSee('Table 6.1-1');
    }

    public function test_b16_11_elbow_requires_explicit_standard_angle_class_and_end_type(): void
    {
        $line = $this->line('6');
        $owner = $this->owner();

        foreach ([
            [
                'fitting_standard' => 'B16.11',
                'fitting_qualifier' => '90',
                'fitting_end_type' => 'SW',
            ],
            [
                'fitting_standard' => 'B16.11',
                'fitting_qualifier' => '90',
                'fitting_class_designation' => 3000,
            ],
            [
                'fitting_qualifier' => '90',
                'fitting_class_designation' => 3000,
                'fitting_end_type' => 'SW',
            ],
        ] as $missingInput) {
            try {
                $this->asOwner(fn () => $this->svc()->place($line, $owner, 'ELBOW', $missingInput));
                $this->fail('B16.11 must not infer its standard, class, or end type.');
            } catch (ValidationException $exception) {
                $expectedField = match (true) {
                    ! isset($missingInput['fitting_standard']) => 'fitting_standard',
                    ! isset($missingInput['fitting_class_designation']) => 'fitting_class_designation',
                    ! isset($missingInput['fitting_end_type']) => 'fitting_end_type',
                    default => 'fitting_qualifier',
                };
                $this->assertArrayHasKey($expectedField, $exception->errors());
            }
        }

        $elbow = $this->asOwner(fn () => $this->svc()->place($line, $owner, 'ELBOW', [
            'nominal_size' => '1/2',
            'fitting_standard' => 'B16.11',
            'fitting_qualifier' => '90',
            'fitting_class_designation' => 3000,
            'fitting_end_type' => 'SW',
        ]));

        $this->assertSame('B16.11', $elbow->fitting_standard);
        $this->assertSame(3000, $elbow->fitting_class_designation);
        $this->assertSame('90', $elbow->fitting_qualifier);
        $this->assertSame(['SW', 'SW'], $elbow->ports->pluck('end_type')->all());
        $this->assertSame('#1 ELBOW 90° B16.11 Class 3000 1/2"', $elbow->label());
    }

    public function test_b16_11_pose_uses_the_explicit_end_configuration_class_and_stales_when_class_changes(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line('6');
        $elbow = $this->asOwner(fn () => $this->svc()->place($line, $owner, 'ELBOW', [
            'nominal_size' => '1/2',
            'fitting_standard' => 'B16.11',
            'fitting_qualifier' => '90',
            'fitting_class_designation' => 3000,
            'fitting_end_type' => 'SW',
        ]));
        $frame = $this->modelFrame();
        $poses = $this->poseService();

        $first = $poses->recordManual($project, $line, $elbow, $frame, $owner, 0.0, 0.0, 0.0, [100, 200, 300]);
        $this->assertSame('ASME_B16_11_FORGED_ELBOW_TWO_PORTS_V1', $first->geometry_profile_id);
        $this->assertSame('RESOLVED', $first->geometry_status);
        $this->assertSame('B16.11', $first->resolved_port_geometry['profile_parameters']['standard_code']);
        $this->assertSame(3000, $first->resolved_port_geometry['profile_parameters']['class_designation']);
        $this->assertSame('SW', $first->resolved_port_geometry['profile_parameters']['end_type']);
        $this->assertSame('A', $first->resolved_port_geometry['profile_parameters']['dimension_symbol']);
        $this->assertSame('CENTRE_TO_BOTTOM_OF_SOCKET', $first->resolved_port_geometry['profile_parameters']['dimension']);
        $this->assertEqualsWithDelta(15.5, $first->resolved_port_geometry['profile_parameters']['dimension_mm'], 1e-9);
        $this->assertEqualsWithDelta(9.5, $first->resolved_port_geometry['profile_parameters']['socket_depth_min_mm'], 1e-9);
        $this->assertEquals([115.5, 215.5, 300.0], $first->resolved_port_geometry['ports'][1]['point_model']);

        $this->asOwner(fn () => DB::table('components')->where('id', $elbow->id)
            ->update(['fitting_class_designation' => 6000]));
        $stale = $poses->currentGeometry($project, $owner, $elbow);
        $this->assertSame('STALE', $stale['status']);
        $this->assertSame('GEOMETRY_PROFILE_CHANGED', $stale['reason']);
        $this->assertSame([], $stale['ports']);

        $second = $poses->recordManual($project, $line, $elbow, $frame, $owner, 0.0, 0.0, 0.0, [100, 200, 300]);
        $this->assertSame(2, $second->revision);
        $this->assertSame(6000, $second->resolved_port_geometry['profile_parameters']['class_designation']);
        $this->assertEqualsWithDelta(19.0, $second->resolved_port_geometry['profile_parameters']['dimension_mm'], 1e-9);
        $this->assertEquals([119.0, 219.0, 300.0], $second->resolved_port_geometry['ports'][1]['point_model']);

        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('B16.11-2021')
            ->assertSee('Class 6000')
            ->assertSee('A (CENTRE_TO_BOTTOM_OF_SOCKET) = 19 mm')
            ->assertSee('J (minimum socket depth) = 9.5 mm')
            ->assertSee('SOCKET_BOTTOM');
    }

    public function test_b16_11_unlisted_class_and_size_pair_stays_unresolved_without_extrapolation(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line('6');
        $elbow = $this->asOwner(fn () => $this->svc()->place($line, $owner, 'ELBOW', [
            'nominal_size' => '1/2',
            'fitting_standard' => 'B16.11',
            'fitting_qualifier' => '90',
            'fitting_class_designation' => 2000,
            'fitting_end_type' => 'SW',
        ]));
        $frame = $this->modelFrame();

        $pose = $this->poseService()->recordManual($project, $line, $elbow, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $this->assertSame('UNRESOLVED', $pose->geometry_status);
        $this->assertSame('SIZE_CLASS_NOT_COVERED', $pose->unresolved_reason);
        $this->assertSame([], $pose->resolved_port_geometry['ports']);

        $current = $this->poseService()->currentGeometry($project, $owner, $elbow);
        $this->assertSame('UNRESOLVED', $current['status']);
        $this->assertSame('SIZE_CLASS_NOT_COVERED', $current['reason']);
    }

    public function test_manual_pose_is_rejected_for_each_non_draft_line_state(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line);
        $frame = $this->modelFrame();
        $service = $this->poseService();

        foreach ([LineReleaseState::AFC_CONFIRMED, LineReleaseState::RELEASED] as $state) {
            $provenance = [
                'release_state' => $state,
                'afc_confirmed_at' => now(),
                'afc_confirmed_by' => $owner->id,
            ];
            if ($state === LineReleaseState::RELEASED) {
                $provenance['released_at'] = now();
                $provenance['released_by'] = $owner->id;
            }
            $this->asOwner(fn () => DB::table('piping_lines')->where('id', $line->id)
                ->update($provenance));

            $this->assertPoseRejected(fn () => $service->recordManual(
                $project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]
            ), 'release_state');
        }

        $this->assertSame(0, $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('component_id', $pipe->id)->count()));
    }

    public function test_manual_pose_write_is_refused_for_an_archived_project(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line);
        $frame = $this->modelFrame();

        app(ProjectArchiveService::class)->archive($project, $owner, 'Pose freeze integration test');

        $this->assertPoseRejected(fn () => $this->poseService()->recordManual(
            $project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]
        ), 'project');
        $this->assertSame(0, $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('component_id', $pipe->id)->count()));
    }

    public function test_manual_pose_rejects_project_component_and_frame_mismatches_and_non_members(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $lineA = $this->line('6');
        $lineB = $this->line('4');
        $pipeA = $this->pipe($lineA);
        $pipeB = $this->pipe($lineB);
        $frameA = $this->modelFrame($project, $owner, 'PH-14 survey origin');

        $otherProject = Project::query()->where('code', 'BB-2')->firstOrFail();
        $otherActor = $this->userByEmail('ahmadi@nigc.example');
        $frameB = $this->modelFrame($otherProject, $otherActor, 'BB-2 survey origin');
        $service = $this->poseService();

        $this->assertPoseRejected(fn () => $service->recordManual(
            $otherProject, $lineA, $pipeA, $frameB, $otherActor, 0.0, 0.0, 0.0, [0, 0, 0]
        ));
        $this->assertPoseRejected(fn () => $service->recordManual(
            $project, $lineA, $pipeB, $frameA, $owner, 0.0, 0.0, 0.0, [0, 0, 0]
        ));
        $this->assertPoseRejected(fn () => $service->recordManual(
            $project, $lineA, $pipeA, $frameB, $owner, 0.0, 0.0, 0.0, [0, 0, 0]
        ));

        try {
            app(ProjectModelFrameService::class)->define(
                $project,
                $otherActor,
                'Should not be recorded for a non-member',
            );
            $this->fail('A user without project membership must not define a project frame.');
        } catch (NotFoundHttpException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('project_id', $project->id)->count()));
    }

    public function test_missing_pipe_length_is_saved_as_unresolved_without_port_coordinates(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line, null);
        $frame = $this->modelFrame();

        $pose = $this->poseService()->recordManual(
            $project, $line, $pipe, $frame, $owner, 25.0, 10.0, -15.0, [1200, 2400, 350]
        );

        $this->assertSame('UNRESOLVED', $pose->geometry_status);
        $this->assertSame('MISSING_LENGTH', $pose->unresolved_reason);
        $this->assertSame([], $pose->resolved_port_geometry['ports']);

        $current = $this->poseService()->currentGeometry($project, $owner, $pipe);
        $this->assertSame('UNRESOLVED', $current['status']);
        $this->assertSame('MISSING_LENGTH', $current['reason']);
        $this->assertSame([], $current['ports']);
    }

    public function test_geometry_is_reported_stale_until_a_new_draft_pose_refreshes_it(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line, 3000.0);
        $frame = $this->modelFrame($project, $owner, 'PH-14 survey origin');
        $service = $this->poseService();

        $first = $service->recordManual($project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $this->assertSame(1, $first->revision);

        $this->asOwner(fn () => DB::table('components')->where('id', $pipe->id)->update(['length_mm' => 4200.0]));
        $staleProfile = $service->currentGeometry($project, $owner, $pipe);
        $this->assertSame('STALE', $staleProfile['status']);
        $this->assertSame('GEOMETRY_PROFILE_CHANGED', $staleProfile['reason']);
        $this->assertSame([], $staleProfile['ports']);

        $second = $service->recordManual($project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $this->assertSame(2, $second->revision);
        $this->assertEqualsWithDelta(
            4200.0,
            (float) $service->currentGeometry($project, $owner, $pipe)['ports'][1]['point_model'][0],
            0.001,
        );

        $newFrame = $this->modelFrame($project, $owner, 'PH-14 revised survey origin');
        $staleFrame = $service->currentGeometry($project, $owner, $pipe);
        $this->assertSame('STALE', $staleFrame['status']);
        $this->assertSame('FRAME_REVISION_CHANGED', $staleFrame['reason']);
        $this->assertSame([], $staleFrame['ports']);

        $this->assertPoseRejected(fn () => $service->recordManual(
            $project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]
        ), 'model_frame_revision_id');

        $third = $service->recordManual($project, $line, $pipe, $newFrame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $this->assertSame(3, $third->revision);
        $this->assertSame('RESOLVED', $service->currentGeometry($project, $owner, $pipe)['status']);

        $this->asOwner(fn () => DB::table('ports')->where('component_id', $pipe->id)
            ->where('seq', 2)->update(['role' => 'SMALL']));
        $stalePortShape = $service->currentGeometry($project, $owner, $pipe);
        $this->assertSame('STALE', $stalePortShape['status']);
        $this->assertSame('PROFILE_PORT_SHAPE_CHANGED', $stalePortShape['reason']);
        $this->assertSame([], $stalePortShape['ports']);
    }

    public function test_project_frame_cannot_change_while_pose_exists_on_a_non_draft_line(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line);
        $frame = $this->modelFrame($project, $owner, 'PH-14 survey origin');

        $this->poseService()->recordManual($project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $this->asOwner(fn () => DB::table('piping_lines')->where('id', $line->id)->update([
            'release_state' => LineReleaseState::AFC_CONFIRMED,
            'afc_confirmed_at' => now(),
            'afc_confirmed_by' => $owner->id,
        ]));

        $this->assertPoseRejected(fn () => $this->modelFrame(
            $project, $owner, 'An origin change after AFC must be refused'
        ), 'origin_description');
        $this->assertSame(1, $this->asOwner(fn () => ProjectModelFrameRevision::query()
            ->where('project_id', $project->id)->count()));
    }

    public function test_component_removal_preserves_pose_snapshot_history(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line);
        $frame = $this->modelFrame();

        $this->poseService()->recordManual($project, $line, $pipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $this->asOwner(fn () => $this->svc()->remove($pipe, $owner));

        $this->assertSame(1, $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('component_id', $pipe->id)->count()));
        $this->assertSame(0, $this->asOwner(fn () => Component::query()->whereKey($pipe->id)->count()));
    }

    public function test_model_page_records_a_project_frame_and_manual_component_pose(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line, 3000.0);
        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);
        $frameUrl = route('piping.model.frame', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);

        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('Project model frame')
            ->assertSee('No project coordinate frame is defined yet')
            ->assertSee('Orientation & geometry')
            ->assertDontSee('name="azimuth_deg"', false);

        $this->actingAs($owner)->post($frameUrl, [
            'origin_description' => 'PH-14 grid intersection A/1',
        ])->assertRedirect($modelUrl)
            ->assertSessionHas('status', 'Project model frame revision 1 saved.');

        $frame = $this->asOwner(fn () => ProjectModelFrameRevision::query()
            ->where('project_id', $project->id)
            ->firstOrFail());
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('PH-14 grid intersection A/1')
            ->assertSee('name="azimuth_deg"', false)
            ->assertSee('name="anchor_easting_mm"', false);

        $this->actingAs($owner)->post(route('piping.model.pose', [
            'projectCode' => 'PH-14',
            'lineId' => $line->id,
            'componentId' => $pipe->id,
            'lang' => 'en',
        ]), [
            'input_mode' => 'AZIMUTH_INCLINATION_ROLL',
            'component_id' => $pipe->id,
            'model_frame_revision_id' => $frame->id,
            'azimuth_deg' => 90,
            'inclination_deg' => 0,
            'roll_deg' => 30,
            'anchor_easting_mm' => 1000,
            'anchor_northing_mm' => 2000,
            'anchor_elevation_mm' => 300,
        ])->assertRedirect($modelUrl)
            ->assertSessionHas('status', 'Pose revision 1 saved; geometry status: RESOLVED; engineering acceptance remains unassessed.');

        $geometry = $this->poseService()->currentGeometry($project, $owner, $pipe);
        $this->assertSame('RESOLVED', $geometry['status']);
        $this->assertEqualsWithDelta(1000.0, $geometry['ports'][1]['point_model'][0], 0.001);
        $this->assertEqualsWithDelta(5000.0, $geometry['ports'][1]['point_model'][1], 0.001);
        $this->assertEqualsWithDelta(300.0, $geometry['ports'][1]['point_model'][2], 0.001);

        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('RESOLVED')
            ->assertSee('[1000, 5000, 300]')
            ->assertSee('Frame revision 1');

        $faUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'fa',
        ]);
        $this->actingAs($owner)->get($faUrl)
            ->assertOk()
            ->assertSee('فریم مدل پروژه')
            ->assertSee('ثبت جهت‌گیری دستی');
    }

    public function test_model_page_records_dimensioned_triangle_pose_with_raw_input_and_method_provenance(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line, 5000.0);
        $frame = $this->modelFrame();
        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);

        $this->actingAs($owner)->post(route('piping.model.pose', [
            'projectCode' => 'PH-14',
            'lineId' => $line->id,
            'componentId' => $pipe->id,
            'lang' => 'en',
        ]), [
            'input_mode' => 'DIMENSIONED_TRIANGLE',
            'component_id' => $pipe->id,
            'model_frame_revision_id' => $frame->id,
            'azimuth_deg' => 90,
            'roll_deg' => 25,
            'run_mm' => 3000,
            'rise_mm' => 4000,
            'measured_hypotenuse_mm' => 5000.25,
            'anchor_easting_mm' => 1000,
            'anchor_northing_mm' => 2000,
            'anchor_elevation_mm' => 300,
        ])->assertRedirect($modelUrl)
            ->assertSessionHas('status', 'Pose revision 1 saved; geometry status: RESOLVED; engineering acceptance remains unassessed.');

        $pose = $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('component_id', $pipe->id)
            ->firstOrFail());
        $this->assertSame('DIMENSIONED_TRIANGLE', $pose->input_geometry['input_mode']);
        $this->assertSame('AZIMUTH_SIGNED_RUN_RISE_ROLL_V1', $pose->input_geometry['adapter_method']);
        $this->assertSame('SIGNED_PLANAR_RUN_RISE_ATAN2_V1', $pose->input_geometry['angle_calculation_method']);
        $this->assertSame('RUN_RISE_HYPOTENUSE_RESIDUAL_V1', $pose->input_geometry['closure_calculation_method']);
        $this->assertSame('DEG', $pose->input_geometry['angle_unit']);
        $this->assertSame('MM', $pose->input_geometry['length_unit']);
        $this->assertEqualsWithDelta(3000.0, $pose->input_geometry['run_mm'], 1e-9);
        $this->assertEqualsWithDelta(4000.0, $pose->input_geometry['rise_mm'], 1e-9);
        $this->assertEqualsWithDelta(5000.25, $pose->input_geometry['measured_hypotenuse_mm'], 1e-9);
        $this->assertEqualsWithDelta(5000.0, $pose->input_geometry['calculated_hypotenuse_mm'], 1e-9);
        $this->assertEqualsWithDelta(0.25, $pose->input_geometry['closure_residual_mm'], 1e-9);
        $this->assertEqualsWithDelta(53.13010235415598, $pose->input_inclination_deg, 1e-12);

        $geometry = $this->poseService()->currentGeometry($project, $owner, $pipe);
        $this->assertSame('RESOLVED', $geometry['status']);
        $this->assertEqualsWithDelta(1000.0, $geometry['ports'][1]['point_model'][0], 0.001);
        $this->assertEqualsWithDelta(5000.0, $geometry['ports'][1]['point_model'][1], 0.001);
        $this->assertEqualsWithDelta(4300.0, $geometry['ports'][1]['point_model'][2], 0.001);

        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('Raw triangle input and calculation provenance')
            ->assertSee('AZIMUTH_SIGNED_RUN_RISE_ROLL_V1')
            ->assertSee('Calculated hypotenuse')
            ->assertSee('<bdi>0.25</bdi> mm', false)
            ->assertSee('UNASSESSED — no engineering acceptance tolerance is defined.')
            ->assertSee('Plane azimuth α');
    }

    public function test_triangle_pose_requires_explicit_plane_and_roll_and_refuses_negative_run(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line);
        $frame = $this->modelFrame($project, $owner);
        $poseUrl = route('piping.model.pose', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'componentId' => $pipe->id, 'lang' => 'en',
        ]);

        $this->actingAs($owner)->post($poseUrl, [
            'input_mode' => 'DIMENSIONED_TRIANGLE',
            'component_id' => $pipe->id,
            'model_frame_revision_id' => $frame->id,
            'run_mm' => 3,
            'rise_mm' => 4,
            'anchor_easting_mm' => 0,
            'anchor_northing_mm' => 0,
            'anchor_elevation_mm' => 0,
        ])->assertRedirect()
            ->assertSessionHasErrors(['azimuth_deg', 'roll_deg']);

        $this->actingAs($owner)->post($poseUrl, [
            'input_mode' => 'DIMENSIONED_TRIANGLE',
            'component_id' => $pipe->id,
            'model_frame_revision_id' => $frame->id,
            'azimuth_deg' => 0,
            'roll_deg' => 0,
            'run_mm' => -3,
            'rise_mm' => 4,
            'anchor_easting_mm' => 0,
            'anchor_northing_mm' => 0,
            'anchor_elevation_mm' => 0,
        ])->assertRedirect()
            ->assertSessionHasErrors('run_mm');

        $this->assertSame(0, $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('component_id', $pipe->id)->count()));
    }

    public function test_pose_and_frame_endpoints_require_the_modeling_capability(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $pipe = $this->pipe($line);
        $frame = $this->modelFrame();
        $qc = $this->userByEmail('sadeghi@pars.example');
        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);

        $this->actingAs($qc)->get($modelUrl)
            ->assertOk()
            ->assertDontSee('name="origin_description"', false)
            ->assertDontSee('name="azimuth_deg"', false);

        $this->actingAs($qc)->post(route('piping.model.frame', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]), [
            'origin_description' => 'Unauthorized frame attempt',
        ])->assertForbidden();

        $this->actingAs($qc)->post(route('piping.model.pose', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'componentId' => $pipe->id, 'lang' => 'en',
        ]), [
            'input_mode' => 'AZIMUTH_INCLINATION_ROLL',
            'component_id' => $pipe->id,
            'model_frame_revision_id' => $frame->id,
            'azimuth_deg' => 0,
            'inclination_deg' => 0,
            'roll_deg' => 0,
            'anchor_easting_mm' => 0,
            'anchor_northing_mm' => 0,
            'anchor_elevation_mm' => 0,
        ])->assertForbidden();

        $this->actingAs($qc)->post(route('piping.model.geometry-snapshots', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]))->assertForbidden();

        $this->assertSame(1, $this->asOwner(fn () => ProjectModelFrameRevision::query()
            ->where('project_id', $project->id)->count()));
        $this->assertSame(0, $this->asOwner(fn () => ComponentPoseRevision::query()
            ->where('component_id', $pipe->id)->count()));
        $this->assertSame(0, $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('project_id', $project->id)->count()));
    }

    public function test_joint_diagnostic_snapshot_capture_is_draft_only_even_when_no_joints_exist(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $this->pipe($line);
        $this->asOwner(fn () => DB::table('piping_lines')->where('id', $line->id)
            ->update([
                'release_state' => LineReleaseState::AFC_CONFIRMED,
                'afc_confirmed_at' => now(),
                'afc_confirmed_by' => $owner->id,
            ]));

        $this->actingAs($owner)->post(route('piping.model.geometry-snapshots', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]))->assertRedirect()->assertSessionHasErrors('release_state');

        $this->assertSame(0, $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('project_id', $project->id)->count()));
    }

    public function test_connected_nipple_joint_reports_raw_unassessed_geometry_and_marks_it_stale(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $first = $this->asOwner(fn () => $this->svc()->place(
            $line,
            $owner,
            'NIPPLE',
            ['length_mm' => 1000.0],
        ));
        $second = $this->asOwner(fn () => $this->svc()->place(
            $line,
            $owner,
            'NIPPLE',
            ['length_mm' => 1000.0],
        ));
        $joint = $this->asOwner(fn () => app(JointService::class)->join(
            $first->ports->firstWhere('seq', 2),
            $second->ports->firstWhere('seq', 1),
            $owner,
        ));
        $frame = $this->modelFrame();
        $poses = $this->poseService();
        $poses->recordManual($project, $line, $first, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $poses->recordManual($project, $line, $second, $frame, $owner, 0.0, 0.0, 0.0, [1100, 0, 0]);

        $joints = $this->asOwner(fn () => Joint::query()
            ->with(['portA.component', 'portB.component'])
            ->whereKey($joint->id)
            ->get());
        $geometryByComponentId = $poses->currentGeometries($project, $owner, [$first, $second]);
        $diagnostic = app(JointPortGeometryDiagnosticService::class)
            ->forJoints($joints, $geometryByComponentId)[$joint->id];

        $this->assertSame('UNASSESSED', $diagnostic['status']);
        $this->assertSame('NO_ACCEPTANCE_RULE', $diagnostic['reason']);
        $this->assertEqualsWithDelta(100.0, $diagnostic['gap_mm'], 0.001);
        $this->assertEqualsWithDelta(0.0, $diagnostic['outward_axis_deviation_deg'], 0.001);

        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('Joint geometry diagnostic')
            ->assertSee('Raw geometry only; no acceptance rule/tolerance is defined for PASS/FAIL.')
            ->assertSee('100 mm');

        $this->asOwner(fn () => DB::table('components')->where('id', $second->id)
            ->update(['length_mm' => 1500.0]));
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('STALE — dependent geometry cannot be evaluated until a new pose is recorded.');
    }

    public function test_p2p_wall_beta_is_a_separate_staleable_diagnostic_and_never_replaces_explicit_beta(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $branchLine = $this->line('2');
        $headerLine = $this->line('6');
        $branchPipe = $this->pipe($branchLine, 1000.0);
        $headerPipe = $this->pipe($headerLine, 6000.0);
        $joint = $this->asOwner(fn () => app(JointService::class)->joinToWall(
            $branchPipe->ports->firstWhere('seq', 1),
            $headerPipe,
            JointBranchKind::STUB_IN,
            2500.0,
            0.0,
            45.0,
            $owner,
        ));
        $frame = $this->modelFrame();
        $poses = $this->poseService();
        $poses->recordManual($project, $headerLine, $headerPipe, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $poses->recordManual($project, $branchLine, $branchPipe, $frame, $owner, 60.0, 0.0, 0.0, [2500, 0, 0]);

        $captureUrl = route('piping.model.geometry-snapshots', [
            'projectCode' => 'PH-14', 'lineId' => $branchLine->id, 'lang' => 'en',
        ]);
        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $branchLine->id, 'lang' => 'en',
        ]);

        $this->actingAs($owner)->post($captureUrl)->assertRedirect();
        $first = $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('joint_id', $joint->id)->firstOrFail());
        $firstBeta = $first->diagnostic_snapshot['branch_beta_geometry'];
        $firstStation = $first->diagnostic_snapshot['branch_station_geometry'];
        $this->assertSame('UNRESOLVED', $first->diagnostic_status, 'Wall contact still lacks host-surface geometry.');
        $this->assertSame('WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY', $first->diagnostic_reason);
        $this->assertSame('JOINT_GEOMETRY_INPUT_V3', $first->source_snapshot['schema']);
        $this->assertSame('UNASSESSED', $firstBeta['status']);
        $this->assertEqualsWithDelta(60.0, $firstBeta['beta_geometry_deg'], 1e-10);
        $this->assertSame(45.0, (float) $firstBeta['beta_recorded_deg']);
        $this->assertEqualsWithDelta(15.0, $firstBeta['delta_geometry_minus_recorded_deg'], 1e-10);
        $this->assertSame(JointPortGeometryDiagnosticService::BRANCH_STATION_METHOD, $firstStation['method_code']);
        $this->assertSame('UNASSESSED', $firstStation['status']);
        $this->assertSame('UPSTREAM_ENDPOINT_NOT_IDENTIFIED', $firstStation['reason']);
        $this->assertEqualsWithDelta(2500.0, $firstStation['station_recorded_mm'], 1e-10);
        $this->assertEqualsWithDelta(2500.0, $firstStation['station_from_port_1_mm'], 1e-10);
        $this->assertEqualsWithDelta(3500.0, $firstStation['station_from_port_2_mm'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $firstStation['perpendicular_distance_to_header_axis_mm'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $firstStation['delta_from_port_1_mm'], 1e-10);
        $this->assertEqualsWithDelta(1000.0, $firstStation['delta_from_port_2_mm'], 1e-10);
        $this->assertNull($first->source_snapshot['endpoint_b']);

        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('Pose-derived branch β (diagnostic only; explicit joint β remains authoritative)')
            ->assertSee('Geometry β:')
            ->assertSee('Recorded β:')
            ->assertSee('Raw Δ (geometry − recorded):')
            ->assertSee('Pose-derived branch station projection (diagnostic only; upstream end is not identified)')
            ->assertSee('Header axis length from pose:')
            ->assertSee('Branch-port point perpendicular distance to header axis:')
            ->assertSee('Station projected from port 1:')
            ->assertSee('Station projected from port 2:')
            ->assertSee('Raw station Δ from port 1 (geometry − recorded):');

        $persianModelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $branchLine->id, 'lang' => 'fa',
        ]);
        $this->actingAs($owner)->get($persianModelUrl)
            ->assertOk()
            ->assertSee('β مستخرج از pose')
            ->assertSee('β از هندسه')
            ->assertSee('β ثبت‌شده')
            ->assertSee('Δ خام (هندسه − ثبت‌شده)')
            ->assertSee('پروجکشن ایستگاه انشعاب از pose')
            ->assertSee('طول محور هدر از pose:')
            ->assertSee('فاصلهٔ عمود نقطهٔ پورت شاخه تا محور هدر:')
            ->assertSee('ایستگاه تصویرشده از پورت ۱:')
            ->assertSee('ایستگاه تصویرشده از پورت ۲:');

        $poses->recordManual($project, $branchLine, $branchPipe, $frame, $owner, 90.0, 0.0, 0.0, [2500, 0, 0]);
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('STALE — inputs changed since capture; capture a new snapshot to record a current result.')
            ->assertDontSee('Geometry β:')
            ->assertDontSee('Station projected from port 1:');

        $this->actingAs($owner)->post($captureUrl)->assertRedirect();
        $revisions = $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('joint_id', $joint->id)
            ->orderBy('revision')
            ->get());
        $this->assertCount(2, $revisions);
        $secondBeta = $revisions->last()->diagnostic_snapshot['branch_beta_geometry'];
        $secondStation = $revisions->last()->diagnostic_snapshot['branch_station_geometry'];
        $this->assertSame('UNRESOLVED', $revisions->last()->diagnostic_status);
        $this->assertSame('UNASSESSED', $secondBeta['status']);
        $this->assertEqualsWithDelta(90.0, $secondBeta['beta_geometry_deg'], 1e-10);
        $this->assertSame(45.0, (float) $secondBeta['beta_recorded_deg']);
        $this->assertEqualsWithDelta(45.0, $secondBeta['delta_geometry_minus_recorded_deg'], 1e-10);
        $this->assertSame('UNASSESSED', $secondStation['status']);
        $this->assertEqualsWithDelta(2500.0, $secondStation['station_from_port_1_mm'], 1e-10);
        $this->assertEqualsWithDelta(3500.0, $secondStation['station_from_port_2_mm'], 1e-10);
        $freshJoint = $this->asOwner(fn () => Joint::query()->whereKey($joint->id)->firstOrFail());
        $this->assertSame(45.0, (float) $freshJoint->branch_longitudinal_angle_deg);
        $this->assertNull($freshJoint->port_b_id);
        $this->assertSame($headerPipe->id, $freshJoint->branch_header_component_id);

        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('Current snapshot revision')
            ->assertSee('Pose-derived branch β (diagnostic only; explicit joint β remains authoritative)')
            ->assertSee('Geometry β:')
            ->assertSee('Pose-derived branch station projection (diagnostic only; upstream end is not identified)')
            ->assertSee('Station projected from port 1:');
    }

    public function test_joint_diagnostic_snapshots_are_immutable_idempotent_and_mark_changed_sources_stale(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $first = $this->pipe($line, 1000.0);
        $second = $this->pipe($line, 1000.0);
        $joint = $this->asOwner(fn () => app(JointService::class)->join(
            $first->ports->firstWhere('seq', 2),
            $second->ports->firstWhere('seq', 1),
            $owner,
        ));
        $frame = $this->modelFrame();
        $poses = $this->poseService();
        $poses->recordManual($project, $line, $first, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $poses->recordManual($project, $line, $second, $frame, $owner, 0.0, 0.0, 0.0, [1100, 0, 0]);

        $captureUrl = route('piping.model.geometry-snapshots', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);
        $modelUrl = route('piping.model', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]);

        $this->actingAs($owner)->post($captureUrl)->assertRedirect();
        $firstRevision = $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('joint_id', $joint->id)->firstOrFail());
        $this->assertSame(1, $firstRevision->revision);
        $this->assertSame('UNASSESSED', $firstRevision->diagnostic_status);
        $this->assertSame('NO_ACCEPTANCE_RULE', $firstRevision->diagnostic_reason);
        $this->assertSame('EXPLICIT_TWO_PORT_PORT_GAP_AXIS_DEVIATION_V1', $firstRevision->method_code);
        $this->assertSame('JOINT_GEOMETRY_INPUT_V2', $firstRevision->source_snapshot['schema']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $firstRevision->source_fingerprint);
        $this->assertNotNull($firstRevision->source_snapshot['endpoint_a']['component']['geometry_source_fingerprint']);

        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('Current snapshot revision')
            ->assertSee('Raw geometry only; no acceptance rule/tolerance is defined for PASS/FAIL.')
            ->assertSee('100 mm');

        // Re-capturing identical inputs reuses the same immutable revision.
        $this->actingAs($owner)->post($captureUrl)->assertRedirect();
        $this->assertSame(1, $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('joint_id', $joint->id)->count()));

        $this->asOwner(fn () => DB::table('components')->where('id', $second->id)
            ->update(['length_mm' => 1500.0]));
        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('STALE — inputs changed since capture; capture a new snapshot to record a current result.');

        // A new pose against the changed pipe length restores resolved source
        // geometry; the next capture is a fresh diagnostic revision, not a
        // rewrite of the prior snapshot.
        $poses->recordManual($project, $line, $second, $frame, $owner, 0.0, 0.0, 0.0, [1100, 0, 0]);
        $this->actingAs($owner)->post($captureUrl)->assertRedirect();
        $revisions = $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('joint_id', $joint->id)->orderBy('revision')->get());
        $this->assertCount(2, $revisions);
        $this->assertSame(2, $revisions->last()->revision);
        $this->assertSame('UNASSESSED', $revisions->last()->diagnostic_status);
        $this->assertSame('NO_ACCEPTANCE_RULE', $revisions->last()->diagnostic_reason);

        $changed = $this->asOwner(fn () => DB::table('joint_geometry_diagnostic_revisions')
            ->where('id', $revisions->last()->id)
            ->update(['diagnostic_reason' => 'FORGED_VERDICT']));
        $deleted = $this->asOwner(fn () => DB::table('joint_geometry_diagnostic_revisions')
            ->where('id', $revisions->last()->id)->delete());
        $this->assertSame(0, $changed, 'RLS must refuse in-place mutation of a diagnostic revision.');
        $this->assertSame(0, $deleted, 'RLS must refuse deletion of a diagnostic revision.');

        $this->actingAs($owner)->get($modelUrl)
            ->assertOk()
            ->assertSee('Current snapshot revision')
            ->assertSee('100 mm');
    }

    public function test_diagnostic_history_survives_draft_joint_deletion_and_database_rejects_verdicts(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $line = $this->line();
        $first = $this->pipe($line, 1000.0);
        $second = $this->pipe($line, 1000.0);
        $joint = $this->asOwner(fn () => app(JointService::class)->join(
            $first->ports->firstWhere('seq', 2),
            $second->ports->firstWhere('seq', 1),
            $owner,
        ));
        $frame = $this->modelFrame();
        $poses = $this->poseService();
        $poses->recordManual($project, $line, $first, $frame, $owner, 0.0, 0.0, 0.0, [0, 0, 0]);
        $poses->recordManual($project, $line, $second, $frame, $owner, 0.0, 0.0, 0.0, [1100, 0, 0]);

        $this->actingAs($owner)->post(route('piping.model.geometry-snapshots', [
            'projectCode' => 'PH-14', 'lineId' => $line->id, 'lang' => 'en',
        ]))->assertRedirect();
        $snapshot = $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('joint_id', $joint->id)->firstOrFail());

        try {
            $this->asOwner(fn () => DB::table('joint_geometry_diagnostic_revisions')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $owner->company_id,
                'piping_line_id' => $joint->piping_line_id,
                'joint_id' => $joint->id,
                'revision' => 2,
                'method_code' => $snapshot->method_code,
                'source_fingerprint' => $snapshot->source_fingerprint,
                'diagnostic_status' => 'PASS',
                'diagnostic_reason' => 'FABRICATED_VERDICT',
                'source_snapshot' => json_encode($snapshot->source_snapshot, JSON_THROW_ON_ERROR),
                'diagnostic_snapshot' => json_encode([
                    'status' => 'PASS',
                    'reason' => 'FABRICATED_VERDICT',
                ], JSON_THROW_ON_ERROR),
                'created_by' => $owner->id,
                'created_at' => now(),
            ]));
            $this->fail('The database must refuse PASS/FAIL diagnostic snapshots.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('joint_geom_diag_state_valid', $exception->getMessage());
        }

        $this->asOwner(fn () => app(ComponentService::class)->remove($second, $owner));
        $this->assertSame(0, $this->asOwner(fn () => Joint::query()->whereKey($joint->id)->count()));

        $history = $this->asOwner(fn () => JointGeometryDiagnosticRevision::query()
            ->where('joint_id', $joint->id)->orderBy('revision')->get());
        $this->assertCount(1, $history);
        $this->assertSame($snapshot->id, $history->first()->id);
        $this->assertSame($snapshot->source_fingerprint, $history->first()->source_fingerprint);
        $this->assertSame($snapshot->source_snapshot, $history->first()->source_snapshot);
        $this->assertSame($snapshot->diagnostic_snapshot, $history->first()->diagnostic_snapshot);
        $this->assertNull($history->first()->joint);
    }

    private function assertPoseRejected(callable $write, string $field = 'component_id'): void
    {
        try {
            $write();
            $this->fail('Expected the manual pose write to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }
}
