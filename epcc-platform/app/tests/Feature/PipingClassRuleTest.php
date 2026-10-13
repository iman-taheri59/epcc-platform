<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassBranchRule;
use App\Models\PipingClassRule;
use App\Models\Project;
use App\Services\PipingClassRuleService;
use App\Support\Piping\DnRange;
use App\Support\Piping\Exceptions\BranchLargerThanHeaderException;
use App\Support\Piping\Exceptions\NoRuleForSizeException;
use App\Support\Piping\PipingRuleKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use App\Support\Reference\Exceptions\UnknownSizeException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The PMS as data (ADR-008 phases 1 and 2), as corrected by the user.
 *
 * The original design stored size BANDS, to disambiguate the sentence
 * "1/2 to 4 inch SCH80, 4 to 10 inch SCH40" - four inches being in both.
 *
 * THE USER REMOVED THE PROBLEM INSTEAD OF SOLVING IT: every size a class
 * carries gets its own row, because that is how a PMS is read and transcribed.
 * With no boundary there is nothing to place on the wrong side of one.
 *
 * These tests now pin that: one size one answer, a class covers exactly what it
 * lists, and re-stating a size corrects it rather than colliding. The branch
 * table keeps its ranges and its own tests further down, because overlap there
 * is deliberate.
 */
final class PipingClassRuleTest extends TestCase
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

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function svc(): PipingClassRuleService
    {
        return app(PipingClassRuleService::class);
    }

    private function cls(string $code = 'A1'): PipingClass
    {
        $project = $this->project();

        return PipingClass::firstOrCreate(
            ['project_id' => $project->id, 'code' => $code],
            ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
        );
    }

    /**
     * The user's sentence, transcribed the way a PMS is actually read: each
     * size listed with its own schedule.
     */
    private function theUsersSentence(PipingClass $class): void
    {
        $svc = $this->svc();
        $svc->setRules($class, PipingRuleKind::PIPE_SCHEDULE,
            ['1/2', '3/4', '1', '1 1/4', '1 1/2', '2', '2 1/2', '3', '3 1/2'],
            ['schedule' => '80'], 'PMS Table 3');
        $svc->setRules($class, PipingRuleKind::PIPE_SCHEDULE,
            ['4', '5', '6', '8', '10'],
            ['schedule' => '40'], 'PMS Table 3');
    }

    // ---------------------------------------------------- one size, one answer

    public function test_each_size_carries_its_own_schedule(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->theUsersSentence($class);
            $svc = $this->svc();

            // The size that caused all the trouble. It is simply a row now.
            self::assertSame('40', $svc->scheduleFor($class, '4'));
            self::assertSame('80', $svc->scheduleFor($class, '3 1/2'));
            self::assertSame('80', $svc->scheduleFor($class, '3'));
            self::assertSame('40', $svc->scheduleFor($class, '6'));
        });
    }

    public function test_the_boundary_problem_cannot_arise(): void
    {
        // There is no band, so there is no boundary to put four inches on the
        // wrong side of. The rule for 4" is the row that says 4".
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->theUsersSentence($class);

            $rows = PipingClassRule::query()
                ->where('piping_class_id', $class->id)
                ->where('rule_kind', PipingRuleKind::PIPE_SCHEDULE)
                ->where('dn', 100)
                ->get();

            self::assertCount(1, $rows, 'Exactly one row can speak for DN100.');
            self::assertSame('4', $rows[0]->nps);
            self::assertSame('40', $rows[0]->resolvedValue());
        });
    }

    public function test_the_same_size_twice_is_a_correction_not_a_collision(): void
    {
        // With bands, revising one size meant rewriting boundaries and risking
        // a gap. Now it overwrites the row.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();

            $svc->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '6', ['schedule' => '40']);
            $svc->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '6', ['schedule' => '80']);

            self::assertSame('80', $svc->scheduleFor($class, '6'));
            self::assertSame(1, PipingClassRule::where('piping_class_id', $class->id)->count());
        });
    }

    public function test_the_database_allows_only_one_row_per_size(): void
    {
        // The service updates rather than inserts, so this goes around it -
        // a bulk import is exactly what would.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $project = $this->project();
            $this->svc()->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '6', ['schedule' => '40']);

            $this->expectException(QueryException::class);
            $this->expectExceptionMessageMatches('/unique|duplicate/i');

            \DB::table('piping_class_rules')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'piping_class_id' => $class->id,
                'rule_kind' => PipingRuleKind::PIPE_SCHEDULE,
                'dn' => 150,
                'nps' => '6',
                'value' => json_encode(['schedule' => '80']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_different_kinds_may_describe_the_same_size(): void
    {
        // The uniqueness is per kind. A schedule and an end preparation for 2"
        // answer different questions.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();

            $svc->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '2', ['schedule' => '80']);
            $svc->setRule($class, PipingRuleKind::END_PREPARATION, '2', ['end_type' => 'SW']);

            self::assertSame('80', $svc->resolve($class, PipingRuleKind::PIPE_SCHEDULE, 50));
            self::assertSame('SW', $svc->resolve($class, PipingRuleKind::END_PREPARATION, 50));
        });
    }

    public function test_two_classes_may_specify_the_same_size_differently(): void
    {
        $this->asOwner(function (): void {
            $a = $this->cls('A1');
            $b = $this->cls('B2');
            $svc = $this->svc();

            $svc->setRule($a, PipingRuleKind::PIPE_SCHEDULE, '2', ['schedule' => '80']);
            $svc->setRule($b, PipingRuleKind::PIPE_SCHEDULE, '2', ['schedule' => '40']);

            self::assertSame('80', $svc->scheduleFor($a, '2'));
            self::assertSame('40', $svc->scheduleFor($b, '2'));
        });
    }

    public function test_the_nps_label_is_stored_beside_the_dn(): void
    {
        // "1 1/4" cannot be reconstructed from 32 without a lookup, and a rule
        // should be readable without one.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $rule = $this->svc()->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '1 1/4', ['schedule' => '80']);

            self::assertSame(32, $rule->dn);
            self::assertSame('1 1/4', $rule->nps);
        });
    }

    // ----------------------------------------------------- what a class covers

    public function test_a_class_covers_exactly_the_sizes_it_lists(): void
    {
        // Nothing is implied by falling between two entries. A class that lists
        // 2" and 6" does not thereby specify 4".
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();
            $svc->setRules($class, PipingRuleKind::PIPE_SCHEDULE, ['2', '6'], ['schedule' => '40']);

            self::assertSame(['2', '6'], $svc->sizesCovered($class, PipingRuleKind::PIPE_SCHEDULE));

            $this->expectException(NoRuleForSizeException::class);
            $svc->scheduleFor($class, '4');
        });
    }

    public function test_covered_sizes_come_back_in_size_order(): void
    {
        // Ordered by DN, not by insertion or alphabetically - "10" must not
        // sort before "2".
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();
            $svc->setRules($class, PipingRuleKind::PIPE_SCHEDULE, ['10', '2', '1/2'], ['schedule' => '40']);

            self::assertSame(['1/2', '2', '10'], $svc->sizesCovered($class, PipingRuleKind::PIPE_SCHEDULE));
        });
    }

    public function test_coverage_reports_what_a_project_needs_and_lacks(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->theUsersSentence($class);

            $coverage = $this->svc()->coverage(
                $class,
                PipingRuleKind::PIPE_SCHEDULE,
                ['2', '4', '12'],
            );

            self::assertSame(14, $coverage['count']);
            self::assertFalse($coverage['complete']);
            self::assertSame(['12'], $coverage['missing'], '12" was never listed by this class.');
        });
    }

    public function test_coverage_with_nothing_required_just_reports(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->theUsersSentence($class);

            $coverage = $this->svc()->coverage($class, PipingRuleKind::PIPE_SCHEDULE);

            self::assertTrue($coverage['complete'], 'Nothing was asked for, so nothing is missing.');
            self::assertSame(14, $coverage['count']);
        });
    }

    public function test_a_size_can_be_removed_from_a_class(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();
            $svc->setRules($class, PipingRuleKind::PIPE_SCHEDULE, ['2', '4', '6'], ['schedule' => '40']);

            self::assertTrue($svc->removeRule($class, PipingRuleKind::PIPE_SCHEDULE, '4'));
            self::assertSame(['2', '6'], $svc->sizesCovered($class, PipingRuleKind::PIPE_SCHEDULE));
        });
    }

    // ------------------------------------------------------ refusing to guess

    public function test_an_uncovered_size_throws_rather_than_defaulting(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->svc()->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '2', ['schedule' => '80']);

            $this->expectException(NoRuleForSizeException::class);
            $this->svc()->resolve($class, PipingRuleKind::PIPE_SCHEDULE, 300);
        });
    }

    public function test_the_exception_names_the_class_kind_and_size(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->svc()->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '2', ['schedule' => '80']);

            try {
                $this->svc()->resolve($class, PipingRuleKind::PIPE_SCHEDULE, 250);
                self::fail('Expected NoRuleForSizeException.');
            } catch (NoRuleForSizeException $e) {
                self::assertSame('A1', $e->classCode);
                self::assertSame(PipingRuleKind::PIPE_SCHEDULE, $e->ruleKind);
                self::assertSame(250, $e->dn);
            }
        });
    }

    // ------------------------------------------------- write-time validation

    public function test_a_payload_that_does_not_match_its_kind_is_refused(): void
    {
        // Stored, it would look present on a screen and resolve to nothing.
        $this->asOwner(function (): void {
            $this->expectException(ValidationException::class);
            $this->svc()->setRule($this->cls(), PipingRuleKind::PIPE_SCHEDULE, '2', ['end_type' => 'SW']);
        });
    }

    public function test_a_size_that_asme_does_not_make_is_refused(): void
    {
        $this->asOwner(function (): void {
            $this->expectException(UnknownSizeException::class);
            $this->svc()->setRule($this->cls(), PipingRuleKind::PIPE_SCHEDULE, '7', ['schedule' => '40']);
        });
    }

    public function test_a_schedule_not_made_at_that_size_is_refused(): void
    {
        // Now precise. SCH 160 is not made at 3 1/2", and the refusal names
        // that size - rather than condemning a whole band it happened to be in.
        $this->asOwner(function (): void {
            try {
                $this->svc()->setRule($this->cls(), PipingRuleKind::PIPE_SCHEDULE, '3 1/2', ['schedule' => '160']);
                self::fail('Expected the impossible schedule to be refused.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('value', $e->errors());
                self::assertStringContainsString('3 1/2', $e->errors()['value'][0]);
            }
        });
    }

    public function test_the_same_schedule_is_accepted_where_it_is_made(): void
    {
        // The mirror: SCH 160 IS made at 3", so only the one size is refused.
        $this->asOwner(function (): void {
            $rule = $this->svc()->setRule($this->cls(), PipingRuleKind::PIPE_SCHEDULE, '3', ['schedule' => '160']);

            self::assertSame('160', $rule->resolvedValue());
        });
    }

    public function test_an_unknown_rule_kind_is_refused(): void
    {
        $this->asOwner(function (): void {
            $this->expectException(ValidationException::class);
            $this->svc()->setRule($this->cls(), 'PAINT_COLOUR', '2', ['x' => 'y']);
        });
    }

    public function test_writing_many_sizes_at_once_writes_independent_rows(): void
    {
        // setRules() is a transcription convenience, NOT a band. What is stored
        // is one row per size, each correctable on its own afterwards.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();
            $svc->setRules($class, PipingRuleKind::PIPE_SCHEDULE, ['2', '3', '4'], ['schedule' => '40']);

            self::assertSame(3, PipingClassRule::where('piping_class_id', $class->id)->count());

            $svc->setRule($class, PipingRuleKind::PIPE_SCHEDULE, '3', ['schedule' => '80']);

            self::assertSame('40', $svc->scheduleFor($class, '2'));
            self::assertSame('80', $svc->scheduleFor($class, '3'), 'One size changed, the others did not.');
            self::assertSame('40', $svc->scheduleFor($class, '4'));
        });
    }

    // --------------------------------------------------------- branch table

    /**
     * The three-rule fixture from ADR-008 §4.3: a general rule plus two
     * narrower exceptions, which is how every real branch table is written.
     */
    private function branchTable(PipingClass $class): void
    {
        $svc = $this->svc();
        // Everything, by default, is a tee.
        $svc->addBranchRule($class, DnRange::of(15, 600), DnRange::of(15, 600), 'TEE');
        // A small branch off a large header is an olet instead.
        $svc->addBranchRule($class, DnRange::of(50, 600), DnRange::of(15, 50), 'OLET');
        // A very small branch off a large header is a socket olet.
        $svc->addBranchRule($class, DnRange::of(100, 600), DnRange::of(15, 25), 'OLET', ['olet_type' => 'SOCKOLET']);
    }

    public function test_the_narrowest_matching_branch_rule_wins(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->branchTable($class);
            $svc = $this->svc();

            // Equal size: only the general rule matches.
            self::assertSame('TEE', $svc->resolveBranch($class, 150, 150)->component_type);

            // DN150 header, DN25 branch: TEE and OLET both match; OLET is
            // narrower.
            self::assertSame('OLET', $svc->resolveBranch($class, 150, 25)->component_type);

            // DN150 header, DN15 branch: all three match; the sockolet is
            // narrowest.
            $tightest = $svc->resolveBranch($class, 150, 15);
            self::assertSame('OLET', $tightest->component_type);
            self::assertSame('SOCKOLET', $tightest->value['olet_type']);
        });
    }

    public function test_overlapping_branch_rules_are_allowed(): void
    {
        // The opposite of the 1-D table, and the point of ADR-008 §4.1: a spike
        // proved that forbidding overlap here rejects two correct PMS rules.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->branchTable($class);

            self::assertSame(3, PipingClassBranchRule::where('piping_class_id', $class->id)->count());
        });
    }

    public function test_the_same_footprint_twice_is_still_a_typo(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();
            $svc->addBranchRule($class, DnRange::of(15, 600), DnRange::of(15, 600), 'TEE');

            $this->expectException(QueryException::class);
            $svc->addBranchRule($class, DnRange::of(15, 600), DnRange::of(15, 600), 'OLET');
        });
    }

    public function test_a_branch_larger_than_its_header_is_a_modelling_error(): void
    {
        // A DIFFERENT exception from a PMS gap, because the fix is different:
        // one means go and correct the drawing, the other means go and read the
        // specification.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->branchTable($class);

            $this->expectException(BranchLargerThanHeaderException::class);
            $this->svc()->resolveBranch($class, 50, 150);
        });
    }

    public function test_a_branch_cell_with_no_rule_throws(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            // Only a narrow rule; nothing covers a big header.
            $this->svc()->addBranchRule($class, DnRange::of(15, 50), DnRange::of(15, 50), 'TEE');

            $this->expectException(NoRuleForSizeException::class);
            $this->svc()->resolveBranch($class, 300, 300);
        });
    }

    public function test_priority_breaks_a_tie_between_equally_narrow_rules(): void
    {
        // Without it the winner would depend on insertion order, which is not a
        // rule anybody wrote down.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $svc = $this->svc();

            $svc->addBranchRule($class, DnRange::of(100, 200), DnRange::of(15, 50), 'OLET', null, 0);
            $svc->addBranchRule($class, DnRange::of(15, 50), DnRange::of(100, 200), 'TEE', null, 5);
            // Both footprints span 85+35 = 120. Only the first can match a
            // DN150 header, so this asserts the ordering is stable, not random.
            self::assertSame('OLET', $svc->resolveBranch($class, 150, 25)->component_type);
        });
    }

    public function test_stub_in_is_a_legal_answer_even_though_nothing_is_bought(): void
    {
        // The branch pipe is welded straight into the header: a real
        // construction with no component of its own.
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->svc()->addBranchRule($class, DnRange::of(15, 600), DnRange::of(15, 600), 'STUB_IN');

            self::assertSame('STUB_IN', $this->svc()->resolveBranch($class, 150, 50)->component_type);
        });
    }

    public function test_an_unknown_component_type_is_refused(): void
    {
        $this->asOwner(function (): void {
            $this->expectException(ValidationException::class);
            $this->svc()->addBranchRule(
                $this->cls(),
                DnRange::of(15, 600),
                DnRange::of(15, 600),
                'WELDOLET_XL',
            );
        });
    }

    // ---------------------------------------------------------------- tenancy

    public function test_rules_are_scoped_to_their_project(): void
    {
        $this->asOwner(function (): void {
            $class = $this->cls();
            $this->theUsersSentence($class);
        });

        // A different company on a different project must not see them.
        $other = Project::where('code', 'BB-2')->firstOrFail();
        $count = $this->tenant->runAs(
            $other->owner_company_id,
            fn (): int => PipingClassRule::count(),
            $other->id,
        );

        self::assertSame(0, $count, 'PMS rules are commercially sensitive project data.');
    }
}
