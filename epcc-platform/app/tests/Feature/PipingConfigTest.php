<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\ProjectPipingConfig;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\PipingConfigService;
use App\Services\PipingLineService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Editing the line-number format (ADR-006 §4).
 *
 * Making the format data only pays off if a project can actually reach it. The
 * risk that comes with that door is the point of these tests: a number format
 * is the one setting where a plausible mistake does not fail on save - it
 * fails on the hundredth line, weeks later, or produces numbers that quietly
 * cannot be read back.
 */
final class PipingConfigTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);

        // The auto-numbering path is withdrawn in production (a counter
        // over surviving rows reissues a deleted line's number). These
        // tests exercise the numbering ENGINE, so they opt in explicitly.
        $this->allowAutoLineNumbers(\App\Models\Project::where('code', 'PH-14')->firstOrFail());
    }

    private function service(): PipingConfigService
    {
        return app(PipingConfigService::class);
    }

    private function project(string $code = 'PH-14'): Project
    {
        return Project::where('code', $code)->firstOrFail();
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

    /** A valid pattern, as the stored jsonb shape. */
    private function pattern(array $tokens, string $separator = '-'): array
    {
        return ['separator' => $separator, 'tokens' => $tokens];
    }

    private function defaultTokens(): array
    {
        return ProjectPipingConfig::defaultPattern()['tokens'];
    }

    /**
     * Narrow the pattern to {service}-{subsystem}-{sequence}.
     *
     * These tests are about the editor, not about the default format, and the
     * default needs size, class and coating values that would otherwise have
     * to be threaded through every call.
     */
    private function useSimplePattern(int $width = 3): void
    {
        $this->asOwner(fn () => $this->service()->updatePattern(
            $this->project(),
            $this->owner(),
            $this->pattern([
                ['token' => 'service', 'source' => 'attribute', 'attribute' => 'service_code'],
                ['token' => 'subsystem', 'source' => 'parent', 'attribute' => 'subsystem.code'],
                ['token' => 'sequence', 'source' => 'counter', 'width' => $width],
            ]),
        ));
    }

    // -------------------------------------------------------- the format --

    public function test_the_owner_can_change_the_number_format(): void
    {
        $tokens = [
            ['token' => 'service', 'source' => 'attribute', 'attribute' => 'service_code'],
            ['token' => 'subsystem', 'source' => 'parent', 'attribute' => 'subsystem.code'],
            ['token' => 'sequence', 'source' => 'counter', 'width' => 4],
        ];

        $config = $this->asOwner(fn () => $this->service()->updatePattern(
            $this->project(),
            $this->owner(),
            $this->pattern($tokens),
        ));

        $this->assertSame(
            '{service}-{subsystem}-{sequence}',
            $config->pattern()->sketch(),
        );
        $this->assertSame(4, $config->pattern()->counterToken()->width);
    }

    public function test_a_new_format_applies_to_new_lines_only(): void
    {
        // The most important property of the whole editor. A number that has
        // been on a drawing, a weld map and a material requisition cannot be
        // rewritten because someone edited a setting afterwards.
        $project = $this->project();
        $owner = $this->owner();

        $first = $this->asOwner(function () use ($project, $owner) {
            app(PipingLineService::class)->configFor($project)->update([
                'services' => ['P'], 'coatings' => ['CT1'],
            ]);
            PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );
            $sub = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();

            $class = PipingClass::where('project_id', $project->id)->where('code', 'A1')->firstOrFail();

            return app(PipingLineService::class)->create($sub, $owner, [
                'nominal_size' => '8',
                'service_code' => 'P',
                'piping_class_id' => $class->id,
            ]);
        });

        $before = $first->line_number;

        $this->asOwner(fn () => $this->service()->updatePattern($project, $owner, $this->pattern([
            ['token' => 'service', 'source' => 'attribute', 'attribute' => 'service_code'],
            ['token' => 'subsystem', 'source' => 'parent', 'attribute' => 'subsystem.code'],
            ['token' => 'sequence', 'source' => 'counter', 'width' => 3],
        ])));

        $second = $this->asOwner(function () use ($owner) {
            $sub = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();

            return app(PipingLineService::class)->create($sub, $owner, ['service_code' => 'P']);
        });

        // fresh() must run inside the tenant scope: piping_lines carries RLS,
        // and a bare re-read outside runAs() returns null rather than the row.
        $reread = $this->asOwner(fn () => PipingLine::find($first->id));

        $this->assertNotNull($reread, 'the line must still be readable in tenant scope');
        $this->assertSame($before, $reread->line_number, 'an issued number must never be rewritten');
        $this->assertSame('P-PH-14-SS-2201-002', $second->line_number, 'the new line follows the new format');
        $this->assertSame(2, $second->sequence, 'and the counter carries on rather than restarting');
    }

    public function test_a_format_with_no_counter_is_refused(): void
    {
        // Confirmed by probing the engine before writing this: composing a
        // counter-less pattern twice with the same inputs returns the
        // identical string, so the second create dies on the uniqueness check
        // with "number already taken" - a message that names the symptom and
        // hides the cause. The project would be stuck at one line per
        // subsystem with no usable explanation.
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->service()->updatePattern(
            $this->project(),
            $this->owner(),
            $this->pattern([
                ['token' => 'size', 'source' => 'attribute', 'attribute' => 'nominal_size'],
                ['token' => 'subsystem', 'source' => 'parent', 'attribute' => 'subsystem.code'],
            ]),
        ));
    }

    public function test_two_counters_are_refused_by_the_pattern_itself(): void
    {
        // Delegated to NumberPattern rather than re-checked here, so the two
        // cannot drift apart. The service's job is to turn that into a
        // ValidationException a form can render.
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->service()->updatePattern(
            $this->project(),
            $this->owner(),
            $this->pattern([
                ['token' => 'a', 'source' => 'counter', 'width' => 3],
                ['token' => 'b', 'source' => 'counter', 'width' => 3],
            ]),
        ));
    }

    public function test_a_duplicate_token_name_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->service()->updatePattern(
            $this->project(),
            $this->owner(),
            $this->pattern([
                ['token' => 'service', 'source' => 'attribute', 'attribute' => 'service_code'],
                ['token' => 'service', 'source' => 'attribute', 'attribute' => 'service_code'],
                ['token' => 'sequence', 'source' => 'counter', 'width' => 3],
            ]),
        ));
    }

    public function test_the_counter_cannot_be_narrowed_below_what_was_issued(): void
    {
        // Padding is render-time, so narrowing does not corrupt stored data -
        // which is exactly why it is dangerous. Nothing breaks until the
        // project crosses the threshold, and then the register sorts wrongly.
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => app(PipingLineService::class)->configFor($project)->update(['services' => ['P']]));
        $this->useSimplePattern();

        $this->asOwner(function () use ($owner) {
            $sub = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();
            $line = app(PipingLineService::class)->create($sub, $owner, ['service_code' => 'P']);
            // Pretend the project has issued 120 lines in this subsystem.
            $line->update(['sequence' => 120]);
        });

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->service()->updatePattern($project, $owner, $this->pattern([
            ['token' => 'service', 'source' => 'attribute', 'attribute' => 'service_code'],
            ['token' => 'subsystem', 'source' => 'parent', 'attribute' => 'subsystem.code'],
            ['token' => 'sequence', 'source' => 'counter', 'width' => 2],
        ])));
    }

    public function test_widening_the_counter_is_allowed(): void
    {
        // The safe direction, and a real need: projects outgrow three digits.
        $project = $this->project();
        $owner = $this->owner();

        $config = $this->asOwner(fn () => $this->service()->updatePattern($project, $owner, $this->pattern([
            ['token' => 'subsystem', 'source' => 'parent', 'attribute' => 'subsystem.code'],
            ['token' => 'sequence', 'source' => 'counter', 'width' => 5],
        ])));

        $this->assertSame(5, $config->pattern()->counterToken()->width);
    }

    public function test_only_the_employer_may_change_the_format(): void
    {
        // The format appears on every drawing and take-off on the job. A
        // contractor changing it mid-project would leave their own new lines
        // inconsistent with everyone else's.
        $contractor = $this->userByEmail('rezaei@pars.example');

        $this->expectException(ValidationException::class);

        $this->tenant->runAs(
            $contractor->company_id,
            fn () => $this->service()->updatePattern(
                $this->project(),
                $contractor,
                $this->pattern($this->defaultTokens()),
            ),
            $this->project()->id,
        );
    }

    public function test_an_archived_project_refuses_configuration_changes(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        app(ProjectArchiveService::class)->archive($project, $owner, 'Closed out');

        try {
            $this->expectException(ValidationException::class);
            $this->asOwner(fn () => $this->service()->updatePattern(
                $project->fresh(),
                $owner,
                $this->pattern($this->defaultTokens()),
            ));
        } finally {
            app(ProjectArchiveService::class)->reopen($project->fresh(), $owner);
        }
    }

    // ------------------------------------------------------- the registers --

    public function test_the_owner_can_edit_a_register(): void
    {
        $config = $this->asOwner(fn () => $this->service()->updateRegister(
            $this->project(),
            $this->owner(),
            'services',
            ['HC', 'P', 'WA'],
        ));

        $this->assertSame(['HC', 'P', 'WA'], $config->services);
    }

    public function test_register_values_are_trimmed_deduplicated_and_sorted(): void
    {
        $config = $this->asOwner(fn () => $this->service()->updateRegister(
            $this->project(),
            $this->owner(),
            'services',
            ['  P  ', 'HC', 'P', '', '   '],
        ));

        $this->assertSame(['HC', 'P'], $config->services);
    }

    public function test_a_register_value_containing_the_separator_is_refused(): void
    {
        // Such a value would make every number built from it unparseable: the
        // regex cannot tell the operator's dash from a structural one.
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->service()->updateRegister(
            $this->project(),
            $this->owner(),
            'services',
            ['P', 'HOT-OIL'],
        ));
    }

    public function test_a_value_already_used_in_a_line_number_cannot_be_removed(): void
    {
        // Removing it would not renumber anything; it would just make those
        // numbers unrecognisable to parse(), which is how a register silently
        // stops describing reality.
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => app(PipingLineService::class)->configFor($project)->update(['services' => ['P', 'HC']]));
        $this->useSimplePattern();

        $this->asOwner(function () use ($owner) {
            $sub = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();
            app(PipingLineService::class)->create($sub, $owner, ['service_code' => 'P']);
        });

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->service()->updateRegister($project, $owner, 'services', ['HC']));
    }

    public function test_an_unused_value_can_still_be_removed(): void
    {
        // The counterpart: the rule above must not freeze the register solid.
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => app(PipingLineService::class)->configFor($project)->update(['services' => ['P', 'HC']]));
        $this->useSimplePattern();

        $this->asOwner(function () use ($owner) {
            $sub = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();
            app(PipingLineService::class)->create($sub, $owner, ['service_code' => 'P']);
        });

        $config = $this->asOwner(fn () => $this->service()->updateRegister($project, $owner, 'services', ['P']));

        $this->assertSame(['P'], $config->services);
    }

    public function test_an_unknown_register_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->service()->updateRegister(
            $this->project(),
            $this->owner(),
            'line_number_pattern',
            ['nonsense'],
        ));
    }
}
