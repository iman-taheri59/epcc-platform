<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Component;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\PipingLineService;
use App\Services\ProjectArchiveService;
use App\Support\LineReleaseState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Support\ApprovedPmsClassFixture;

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
            $this->svc()->attach($port->fresh(), $this->owner(), 'ELBOW');
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
            $this->svc()->place($a, $this->owner(), 'ELBOW'),
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

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('ports')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $component->project_id,
            'company_id' => $component->company_id,
            'owner_kind' => 'COMPONENT',
            'component_id' => $component->id,
            'nozzle_id' => (string) \Illuminate\Support\Str::uuid(),
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

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('ports')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
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

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('ports')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
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

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('components')
            ->where('id', $component->id)
            ->update(['nominal_size_decimal' => null]));
    }

    public function test_the_database_refuses_a_duplicate_sequence_on_a_line(): void
    {
        $line = $this->line('6');
        $component = $this->asOwner(fn () => $this->svc()->place($line, $this->owner(), 'PIPE'));

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('components')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $component->project_id,
            'company_id' => $component->company_id,
            'piping_line_id' => $component->piping_line_id,
            'template_code' => 'ELBOW',
            'sequence' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    // ------------------------------------------------------------- HTTP --

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
        $this->assertStringContainsString('<td class="mono">80</td>', $html);
        $this->assertStringContainsString('Open ends', $html);
        // Three ports remain open: the tee's two runs and the pipe's far end.
        $this->assertStringContainsString('(3)', $html);
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
}
