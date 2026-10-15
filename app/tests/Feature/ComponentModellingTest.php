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
        $id = (string) \Illuminate\Support\Str::uuid();
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
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        try {
            $this->asOwner(fn () => DB::transaction(fn () => DB::table('pipe_schedule_exception_decisions')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $line->project_id, 'company_id' => $actor->company_id,
                'request_id' => $id, 'decision' => 'APPROVED', 'note' => 'Looks acceptable',
                'decided_by' => $actor->id,
            ])));
            $this->fail('The requester must not approve their own exception.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
        $this->assertSame(0, $this->asOwner(fn () => DB::table('pipe_schedule_exception_decisions')->count()));
    }

    public function test_offline_signature_metadata_keeps_signer_separate_from_uploader_and_refuses_self_verification(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']));
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => $requestId, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '80', 'actual_schedule' => '40',
            'reason' => 'Reviewed outside the application by contractor', 'requested_by' => $actor->id,
        ]));
        $signerCompany = \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail();
        $signatureId = (string) \Illuminate\Support\Str::uuid();
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
        $metadataOnlyId = (string) \Illuminate\Support\Str::uuid();
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
                'id' => (string) \Illuminate\Support\Str::uuid(), 'signature_id' => $signatureId,
                'project_id' => $line->project_id, 'company_id' => $actor->company_id,
                'decision' => 'VERIFIED', 'authority_basis' => 'PH14 contract appointment',
                'note' => 'Signature checked', 'verified_by' => $actor->id,
            ])));
            $this->fail('The uploader cannot verify their own uploaded signature.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertSame('23514', $e->getCode());
        }
    }

    public function test_tq_ncr_register_keeps_internal_draft_distinct_from_externally_issued_metadata(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']));
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => $requestId, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '80', 'actual_schedule' => '40',
            'reason' => 'Technical query required for schedule deviation', 'requested_by' => $actor->id,
        ]));
        $base = ['request_id' => $requestId, 'project_id' => $line->project_id,
            'company_id' => $actor->company_id, 'recorded_by' => $actor->id];
        $this->asOwner(fn () => DB::table('pipe_schedule_discrepancy_reports')->insert($base + [
            'id' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'TQ',
            'origin' => 'IN_SYSTEM', 'state' => 'DRAFT',
        ]));
        $this->asOwner(fn () => DB::table('pipe_schedule_discrepancy_reports')->insert($base + [
            'id' => (string) \Illuminate\Support\Str::uuid(), 'kind' => 'NCR',
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
                    ->insert($base + ['id' => (string) \Illuminate\Support\Str::uuid()] + $invalid)));
                $this->fail('Incomplete external metadata or forged internal issue must be refused.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertSame('23514', $e->getCode());
            }
        }
    }

    public function test_report_service_records_only_internal_drafts_or_unverified_external_metadata(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        $pipe = $this->asOwner(fn () => $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']));
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $this->asOwner(fn () => DB::table('pipe_schedule_exception_requests')->insert([
            'id' => $requestId, 'project_id' => $line->project_id, 'company_id' => $actor->company_id,
            'component_id' => $pipe->id, 'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
            'expected_schedule' => '80', 'actual_schedule' => '40',
            'reason' => 'Reference conflict needs independent resolution', 'requested_by' => $actor->id,
        ]));
        $service = app(\App\Services\PipeScheduleReportService::class);
        $draft = $service->draft($this->project(), $actor, $requestId, 'TQ');
        $this->assertSame('DRAFT', $draft->state);
        $this->assertNull($draft->report_number);
        $external = $service->registerExternal($this->project(), $actor, $requestId, 'NCR', [
            'report_number' => 'NCR-EXT-42', 'reported_issuer' => 'Contractor QC',
            'reported_signer' => 'Authorized engineer',
            'reported_signer_capacity' => 'QC signatory',
            'reported_issued_at' => '2026-10-14', 'reported_signed_at' => '2026-10-14',
            'external_register_ref' => 'Contractor NCR register / 42',
        ]);
        $this->assertSame('EXTERNAL_RECORDED', $external->state);
        $this->assertNull($external->report_revision);
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
    }

    public function test_report_capture_needs_its_own_capability_and_piping_scope(): void
    {
        $service = app(\App\Services\PipeScheduleReportService::class);
        $project = $this->project();
        $requestId = (string) \Illuminate\Support\Str::uuid();
        $civilEngineer = $this->userByEmail('mousavi@sahand.example');
        try {
            $service->draft($project, $civilEngineer, $requestId, 'TQ');
            $this->fail('A CIVIL-only project member cannot capture PIPING reports.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $role = \App\Models\Role::where('code', 'employer_rep')->firstOrFail();
        $role->update(['capabilities' => ['project.view']]);
        try {
            $service->draft($project, $this->owner(), $requestId, 'TQ');
            $this->fail('Project viewing alone must not grant report capture.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
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
        $requests = app(\App\Services\PipeScheduleExceptionRequestService::class);
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
    }

    public function test_internal_taper_is_only_a_proposal_for_a_thicker_reference_wall(): void
    {
        $line = $this->line('6');
        $actor = $this->owner();
        [$thin, $thick] = $this->asOwner(fn () => [
            $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '40']),
            $this->svc()->place($line, $actor, 'PIPE', ['schedule' => '160']),
        ]);
        $requests = app(\App\Services\PipeScheduleExceptionRequestService::class);
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
        $reports = app(\App\Services\PipeScheduleReportService::class);
        $reports->draft($this->project(), $this->owner(), $request->id, 'TQ');
        $reports->registerExternal($this->project(), $this->owner(), $request->id, 'NCR', [
            'report_number' => 'EXT-NCR-42', 'reported_issuer' => 'Contractor QC',
            'reported_signer' => 'External engineer', 'reported_signer_capacity' => 'NCR signatory',
            'reported_issued_at' => '2026-10-14', 'reported_signed_at' => '2026-10-14',
            'external_register_ref' => 'Contractor NCR register / 42',
        ]);
        $this->actingAs($this->owner())->get($url.'?lang=en')->assertOk()
            ->assertSee('Discrepancy request history')
            ->assertSee('Internal draft; not issued')
            ->assertSee('External report metadata; not verified')
            ->assertSee('EXT-NCR-42')
            ->assertSee('Review requested; not approved')
            ->assertDontSee('Request discrepancy review');
        $this->actingAs($this->owner())->post($url."/{$pipe->id}/request-review?lang=en", [
            'reason' => 'Duplicate report for same discrepancy',
        ])->assertRedirect($url.'?lang=en')->assertSessionHasErrors('component_id');
        $this->actingAs($this->owner())->post($url.'/'.\Illuminate\Support\Str::uuid().'/request-review?lang=en', [
            'reason' => 'Attempt to use an unrelated component identifier',
        ])->assertNotFound();
        $this->asOwner(fn () => DB::table('components')->where('id', $pipe->id)->update(['schedule' => '60']));
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
