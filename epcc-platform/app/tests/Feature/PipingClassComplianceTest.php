<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Component;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\PipingClassComplianceService;
use App\Services\PipingClassRuleService;
use App\Services\PipingLineService;
use App\Support\Piping\PipingRuleKind;
use App\Support\Piping\Verdict;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

/**
 * Checking the model against the PMS (ADR-008 §5, §6).
 *
 * The point of making the PMS data: importing a 3D model stops being
 * transcription and becomes an audit. These tests are mostly about the
 * two-layer distinction, because getting it wrong in either direction destroys
 * the value of the check:
 *
 *   calling a deviation INVALID   → the report cries wolf and gets ignored
 *   calling a deviation VALID     → the report misses the actual problem
 */
final class PipingClassComplianceTest extends TestCase
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

    private function compliance(): PipingClassComplianceService
    {
        return app(PipingClassComplianceService::class);
    }

    /**
     * A 6" line on class A1, whose PMS says SCH 80 below 4" and SCH 40 from
     * 4" up — the user's own sentence.
     */
    private function line(bool $withRules = true): PipingLine
    {
        return $this->asOwner(function () use ($withRules): PipingLine {
            $project = $this->project();
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            if ($withRules && $class->rules()->count() === 0) {
                $rules = app(PipingClassRuleService::class);
                $rules->setRules($class, PipingRuleKind::PIPE_SCHEDULE, ['1/2','3/4','1','1 1/4','1 1/2','2','2 1/2','3','3 1/2'], ['schedule' => '80']);
                $rules->setRules($class, PipingRuleKind::PIPE_SCHEDULE, ['4','5','6','8','10'], ['schedule' => '40']);
            }

            $number = '6-P-A1-PH-14-SS-2201-001';

            $line = PipingLine::where('line_number', $number)->first()
                ?? $lines->register(
                    Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                    $this->owner(),
                    $number,
                    ['nominal_size' => '6', 'service_code' => 'P'],
                );

            if ($line->piping_class_id === null) {
                $line->update(['piping_class_id' => $class->id]);
            }

            return $line->fresh();
        });
    }

    /** @param array<string,mixed> $attrs */
    private function place(PipingLine $line, string $template, array $attrs): Component
    {
        return $this->asOwner(
            fn (): Component => app(ComponentService::class)->place($line, $this->owner(), $template, $attrs)
        );
    }

    // ------------------------------------------------------------ the check

    public function test_a_component_matching_its_class_is_valid(): void
    {
        $line = $this->line();
        // 6" is DN150, which the class says is SCH 40.
        $pipe = $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '40', 'length_mm' => 6000]);

        $this->asOwner(function () use ($pipe): void {
            self::assertSame(Verdict::VALID, $this->compliance()->check($pipe)->state);
        });
    }

    public function test_a_real_but_wrong_schedule_is_a_deviation_not_an_error(): void
    {
        // THE two-layer test. 6" SCH 80 is a pipe that exists and can be
        // bought; it is simply not what this class specifies. Reporting it as
        // INVALID would be false - the thing is buildable.
        $line = $this->line();
        $pipe = $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '80', 'length_mm' => 6000]);

        $this->asOwner(function () use ($pipe): void {
            $verdict = $this->compliance()->check($pipe);

            self::assertSame(Verdict::WARNING, $verdict->state);
            self::assertSame('schedule.deviates', $verdict->reasons[0]['code']);
            self::assertSame('40', $verdict->reasons[0]['params']['expected']);
            self::assertSame('80', $verdict->reasons[0]['params']['actual']);
        });
    }

    public function test_a_schedule_that_does_not_exist_is_invalid(): void
    {
        // The other layer. There is no 6" SCH 999; this could not be built at
        // all, and that is a different conversation from a deviation.
        $line = $this->line();
        $pipe = $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '999', 'length_mm' => 6000]);

        $this->asOwner(function () use ($pipe): void {
            $verdict = $this->compliance()->check($pipe);

            self::assertSame(Verdict::INVALID, $verdict->state);
            self::assertSame('schedule.unknown', $verdict->reasons[0]['code']);
        });
    }

    public function test_a_missing_schedule_is_reported_with_what_it_should_be(): void
    {
        $line = $this->line();
        $pipe = $this->place($line, 'PIPE', ['nominal_size' => '6', 'length_mm' => 6000]);

        $this->asOwner(function () use ($pipe): void {
            $verdict = $this->compliance()->check($pipe);

            self::assertSame(Verdict::WARNING, $verdict->state);
            self::assertSame('schedule.missing', $verdict->reasons[0]['code']);
            self::assertSame('40', $verdict->reasons[0]['params']['expected']);
        });
    }

    public function test_a_valve_is_not_judged_against_a_schedule(): void
    {
        // A valve has a pressure class, not a schedule. Asking the schedule
        // question of one would manufacture a deviation out of a category
        // error - and a report full of those is a report nobody reads.
        $line = $this->line();
        $valve = $this->place($line, 'VALVE', ['nominal_size' => '6']);

        $this->asOwner(function () use ($valve): void {
            self::assertSame(Verdict::VALID, $this->compliance()->check($valve)->state);
        });
    }

    public function test_a_hole_in_the_pms_is_reported_as_a_pms_problem(): void
    {
        // Distinct from a deviation, because the fix is different: this one is
        // fixed by the specification, not by the designer.
        $line = $this->asOwner(function (): PipingLine {
            $project = $this->project();
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'C3'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'small bore only'],
            );

            // Covers only up to 3", so a 6" component falls in a hole.
            app(PipingClassRuleService::class)
                ->setRules($class, PipingRuleKind::PIPE_SCHEDULE, ['1/2','3/4','1','1 1/4','1 1/2','2','2 1/2','3'], ['schedule' => '80']);

            $l = $lines->register(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $this->owner(),
                '6-P-C3-PH-14-SS-2201-009',
                ['nominal_size' => '6', 'service_code' => 'P'],
            );
            $l->update(['piping_class_id' => $class->id]);

            return $l->fresh();
        });

        $pipe = $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '40', 'length_mm' => 6000]);

        $this->asOwner(function () use ($pipe): void {
            $verdict = $this->compliance()->check($pipe);

            self::assertSame(Verdict::WARNING, $verdict->state);
            self::assertSame('piping_class.no_rule', $verdict->reasons[0]['code']);
            self::assertSame('C3', $verdict->reasons[0]['params']['class']);
        });
    }

    public function test_a_line_with_no_class_is_unjudgeable_not_approved(): void
    {
        // Saying VALID here would imply an approval nobody gave.
        $line = $this->line();
        // fresh() must happen INSIDE the tenant binding: outside it the row is
        // invisible and fresh() quietly returns null (HANDOFF §8).
        $line = $this->asOwner(function () use ($line): PipingLine {
            $line->update(['piping_class_id' => null]);

            return $line->fresh();
        });

        $pipe = $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '40', 'length_mm' => 6000]);

        $this->asOwner(function () use ($pipe): void {
            $verdict = $this->compliance()->check($pipe->fresh());

            self::assertSame(Verdict::WARNING, $verdict->state);
            self::assertSame('piping_class.not_set', $verdict->reasons[0]['code']);
        });
    }

    // ------------------------------------------------------------ the audit

    public function test_a_whole_line_is_audited_in_one_call(): void
    {
        $line = $this->line();

        $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '40', 'length_mm' => 6000]);
        $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '80', 'length_mm' => 3000]);
        $this->place($line, 'ELBOW', ['nominal_size' => '6', 'schedule' => '40']);
        $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '999', 'length_mm' => 1000]);

        $this->asOwner(function () use ($line): void {
            $report = $this->compliance()->auditLine($line->fresh());

            self::assertSame(4, $report['checked']);
            self::assertSame(2, $report['valid']);
            self::assertSame(1, $report['deviations']);
            self::assertSame(1, $report['invalid']);
            self::assertCount(2, $report['findings']);
        });
    }

    public function test_the_audit_names_the_component_not_just_a_count(): void
    {
        // "3 deviations" is not actionable; a tag and a size is.
        $line = $this->line();
        $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '80', 'length_mm' => 3000]);

        $this->asOwner(function () use ($line): void {
            $finding = $this->compliance()->auditLine($line->fresh())['findings'][0];

            self::assertSame('PIPE', $finding['template']);
            self::assertSame('6', $finding['size']);
            self::assertSame(Verdict::WARNING, $finding['state']);
            self::assertNotEmpty($finding['component_id']);
        });
    }

    public function test_a_clean_line_reports_no_findings(): void
    {
        $line = $this->line();
        $this->place($line, 'PIPE', ['nominal_size' => '6', 'schedule' => '40', 'length_mm' => 6000]);
        $this->place($line, 'ELBOW', ['nominal_size' => '6', 'schedule' => '40']);

        $this->asOwner(function () use ($line): void {
            $report = $this->compliance()->auditLine($line->fresh());

            self::assertSame(2, $report['valid']);
            self::assertSame([], $report['findings']);
        });
    }
}
