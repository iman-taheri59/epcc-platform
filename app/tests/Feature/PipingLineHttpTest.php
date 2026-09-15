<?php

declare(strict_types=1);

namespace Tests\Feature;

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

                return PipingClass::create([
                    'project_id' => $project->id,
                    'company_id' => $project->owner_company_id,
                    'code' => 'A1',
                    'rating' => '150#',
                    'material' => 'CS',
                    'corrosion_allowance' => 3.0,
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
