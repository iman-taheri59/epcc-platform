<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PipingClass;
use App\Models\Project;
use App\Models\User;
use App\Services\PipingClassService;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\BranchReinforcement;
use App\Support\Piping\Exceptions\BranchLargerThanHeaderException;
use App\Support\Piping\Exceptions\NoRuleForSizeException;
use App\Support\Piping\P2pKind;
use App\Support\Piping\PipingClassState;
use App\Support\Piping\PipingRuleKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Piping P1 (ADR-008): the PMS as data. Half-open DN bands guarded by the
 * EXCLUDE constraint, the range_agg publish gate, and specificity-wins
 * branch resolution — each pinned with the ADR's own fixtures.
 */
final class PipingClassTest extends TestCase
{
    private TenantContext $tenant;

    private PipingClassService $classes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->classes = app(PipingClassService::class);
    }

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', 'PH-14')->firstOrFail()
        );
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    private function user(): User
    {
        return $this->userByEmail('rezaei@pars.example');
    }

    /** @return mixed */
    private function asPars(callable $fn, ?string $projectId = null): mixed
    {
        $pars = $this->company('EPC-PARS');

        return $this->tenant->runAs($pars->id, $fn, $projectId ?? $this->project()->id);
    }

    /** Create a draft class spanning [15,600). */
    private function makeClass(string $code = 'ASME-B16.1'): PipingClass
    {
        return $this->asPars(fn () => $this->classes->create(
            $this->project(), $this->user(), $code, 'Class '.$code, 'کلاس '.$code,
            15, 600, 'en'
        ));
    }

    // ------------------------------------------------------------------
    // One-dimensional rules
    // ------------------------------------------------------------------

    public function test_adjacent_half_open_bands_are_accepted_and_overlap_is_refused(): void
    {
        $class = $this->makeClass();

        $this->asPars(fn () => $this->classes->addRule(
            $this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE,
            15, 100, ['schedule' => '80'], 'en', sourceRef: 'PMS §4.2'
        ));
        $second = $this->asPars(fn () => $this->classes->addRule(
            $this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE,
            100, 250, ['schedule' => '40'], 'en', sourceRef: 'PMS §4.2'
        ));

        $this->assertSame('[100,250)', $second->dn_range);

        // [80,150) overlaps both bands — the database refuses and the
        // service translates the refusal.
        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->classes->addRule(
            $this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE,
            80, 150, ['schedule' => 'STD'], 'en'
        ));
    }

    public function test_the_same_kind_excludes_but_other_kinds_may_share_a_band(): void
    {
        $class = $this->makeClass();

        $this->asPars(fn () => $this->classes->addRule(
            $this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE,
            15, 100, ['schedule' => '80'], 'en'
        ));

        // Identical band, different kind — legal.
        $end = $this->asPars(fn () => $this->classes->addRule(
            $this->project(), $this->user(), $class, PipingRuleKind::END_PREPARATION,
            15, 100, ['end_type' => 'SW'], 'en'
        ));

        $this->assertSame(PipingRuleKind::END_PREPARATION, $end->rule_kind);
    }

    public function test_resolution_picks_the_owning_band_or_throws(): void
    {
        $class = $this->makeClass();

        $this->asPars(function () use ($class): void {
            $this->classes->addRule(
                $this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE,
                15, 100, ['schedule' => '80'], 'en'
            );
            $this->classes->addRule(
                $this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE,
                100, 250, ['schedule' => '40'], 'en'
            );
        });

        $this->asPars(function () use ($class): void {
            // DN100 sits in exactly one band: [100,250) → SCH 40.
            $this->assertSame('40', $this->classes->ruleFor($class, PipingRuleKind::PIPE_SCHEDULE, 100)->value['schedule']);
            // DN80 → SCH 80.
            $this->assertSame('80', $this->classes->ruleFor($class, PipingRuleKind::PIPE_SCHEDULE, 80)->value['schedule']);

            // DN300 is uncovered: a loud miss, never a silent default.
            $this->expectException(NoRuleForSizeException::class);
            $this->classes->ruleFor($class, PipingRuleKind::PIPE_SCHEDULE, 300);
        });
    }

    // ------------------------------------------------------------------
    // The publish gate
    // ------------------------------------------------------------------

    public function test_publish_refuses_a_gapped_class_and_accepts_a_complete_one(): void
    {
        // Span [15,250) with a hole at DN100..DN150.
        $pars = $this->company('EPC-PARS');
        $gapped = $this->asPars(fn () => $this->classes->create(
            $this->project(), $this->user(), 'GAP-CLS', 'Gapped class', 'کلاس دارای شکاف',
            15, 250, 'en'
        ));

        $this->asPars(function () use ($gapped): void {
            $this->classes->addRule($this->project(), $this->user(), $gapped, PipingRuleKind::PIPE_SCHEDULE, 15, 100, ['schedule' => '80'], 'en');
            $this->classes->addRule($this->project(), $this->user(), $gapped, PipingRuleKind::PIPE_SCHEDULE, 150, 250, ['schedule' => '40'], 'en');
        });

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $gapped, 'en'));
            $this->fail('A gapped class must not publish.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('PIPE_SCHEDULE', implode(' ', $e->errors()['rules'] ?? ['']));
        }

        // Fill the hole: the same class now publishes.
        $this->asPars(fn () => $this->classes->addRule(
            $this->project(), $this->user(), $gapped, PipingRuleKind::PIPE_SCHEDULE,
            100, 150, ['schedule' => '60'], 'en'
        ));

        $published = $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $gapped, 'en'));

        $this->assertSame(PipingClassState::PUBLISHED, $published->state);
        $this->assertNotNull($published->published_at);
        $this->assertSame($this->user()->id, $published->published_by);
    }

    public function test_a_published_class_is_read_only(): void
    {
        $class = $this->makeClass('LOCK-CLS');

        $this->asPars(function () use ($class): void {
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE, 15, 600, ['schedule' => '40'], 'en');
            $this->classes->publish($this->project(), $this->user(), $class, 'en');
        });

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->classes->addRule(
            $this->project(), $this->user(), $class, PipingRuleKind::VALVE_TYPE,
            15, 50, ['valve' => 'BALL'], 'en'
        ));
    }

    // ------------------------------------------------------------------
    // Branch table
    // ------------------------------------------------------------------

    public function test_branch_resolution_is_specificity_wins(): void
    {
        $class = $this->makeClass('BRANCH-CLS');

        $this->asPars(function () use ($class): void {
            // General case: any header, any branch → TEE.
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 15, 600, 15, 600, 'TEE', 'en');
            // Small branch off a large header → WELDOLET.
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 50, 600, 15, 50, 'WELDOLET', 'en');
            // The narrowest exception: tiny branches → SOCKOLET.
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 50, 600, 15, 30, 'SOCKOLET', 'en');
        });

        $this->asPars(function () use ($class): void {
            // Equal sizes: only the general rule matches.
            $this->assertSame('TEE', $this->classes->branchFor($class, 150, 150)->component_type);

            // DN25 branch: three rules match, the narrowest footprint wins.
            $this->assertSame('SOCKOLET', $this->classes->branchFor($class, 150, 25)->component_type);
            $this->assertSame('SOCKOLET', $this->classes->branchFor($class, 200, 25)->component_type);

            // DN40 branch: WELDOLET is the narrowest of the two matches.
            $this->assertSame('WELDOLET', $this->classes->branchFor($class, 200, 40)->component_type);
        });
    }

    public function test_pipe_to_pipe_is_a_first_class_branch_outcome(): void
    {
        $class = $this->makeClass('P2P-CLS');

        $this->asPars(function () use ($class): void {
            // General case: a fitting tee.
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 15, 600, 15, 600, BranchConnectionType::TEE, 'en');

            // Pipe-to-Pipe, STUB-IN, with a mandatory reinforcement pad:
            // the pad thickness travels with the rule.
            $this->classes->addBranchRule(
                $this->project(), $this->user(), $class, 200, 600, 15, 50,
                BranchConnectionType::P2P, 'en',
                p2pKind: P2pKind::STUB_IN,
                reinforcement: BranchReinforcement::PAD,
                padThickness: 6.35,
                sourceRef: 'PMS §6.1'
            );

            // Pipe-to-Pipe, STUB-ON (saddle), no reinforcement needed.
            $this->classes->addBranchRule(
                $this->project(), $this->user(), $class, 15, 50, 15, 50,
                BranchConnectionType::P2P, 'en',
                p2pKind: P2pKind::STUB_ON
            );
        });

        $this->asPars(function () use ($class): void {
            $p2p = $this->classes->branchFor($class, 300, 25);
            $this->assertSame(BranchConnectionType::P2P, $p2p->component_type);
            $this->assertSame(P2pKind::STUB_IN, $p2p->p2p_kind);
            $this->assertSame(BranchReinforcement::PAD, $p2p->reinforcement);
            $this->assertSame('6.350', $p2p->pad_thickness);

            // The stub-on band resolves its own kind.
            $stubOn = $this->classes->branchFor($class, 25, 25);
            $this->assertSame(P2pKind::STUB_ON, $stubOn->p2p_kind);

            // Outside both exception bands the general rule still resolves.
            $this->assertSame(BranchConnectionType::TEE, $this->classes->branchFor($class, 300, 150)->component_type);
        });
    }

    public function test_a_reinforcement_pad_without_thickness_is_refused(): void
    {
        $class = $this->makeClass('PAD-CLS');

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->classes->addBranchRule(
            $this->project(), $this->user(), $class, 200, 600, 15, 50,
            BranchConnectionType::P2P, 'en',
            p2pKind: P2pKind::STUB_IN,
            reinforcement: BranchReinforcement::PAD
        ));
    }

    public function test_p2p_kind_is_required_for_p2p_and_forbidden_for_fittings(): void
    {
        $class = $this->makeClass('KIND-CLS');

        $this->asPars(function () use ($class): void {
            // A P2P rule without its kind is refused.
            try {
                $this->classes->addBranchRule(
                    $this->project(), $this->user(), $class, 15, 600, 15, 600,
                    BranchConnectionType::P2P, 'en'
                );
                $this->fail('A P2P rule without its kind must be refused.');
            } catch (ValidationException $e) {
                // expected
            }

            // A fitting rule carrying a P2P kind is refused too.
            $this->expectException(ValidationException::class);
            $this->classes->addBranchRule(
                $this->project(), $this->user(), $class, 15, 600, 15, 600,
                BranchConnectionType::TEE, 'en',
                p2pKind: P2pKind::STUB_IN
            );
        });
    }

    public function test_a_branch_type_outside_the_vocabulary_is_refused(): void
    {
        $class = $this->makeClass('VOC-CLS');

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->classes->addBranchRule(
            $this->project(), $this->user(), $class, 15, 600, 15, 600, 'CROSS', 'en'
        ));
    }

    public function test_branch_larger_than_header_is_a_distinct_error(): void
    {
        $class = $this->makeClass('BRH-CLS');

        $this->expectException(BranchLargerThanHeaderException::class);
        $this->asPars(fn () => $this->classes->branchFor($class, 50, 100));
    }

    public function test_a_duplicate_branch_footprint_is_refused_but_priority_breaks_narrowness_ties(): void
    {
        $class = $this->makeClass('TIE-CLS');

        $this->asPars(function () use ($class): void {
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 15, 215, 15, 215, 'TEE', 'en');

            // The same exact footprint twice is a typo.
            try {
                $this->classes->addBranchRule($this->project(), $this->user(), $class, 15, 215, 15, 215, 'WELDOLET', 'en');
                $this->fail('Duplicate branch footprints must be refused.');
            } catch (ValidationException $e) {
                // expected
            }

            // Two DIFFERENT footprints of equal narrowness both cover
            // (50,50); the explicit priority column decides.
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 20, 220, 20, 220, 'WELDOLET', 'en', priority: 5);
        });

        $this->asPars(function () use ($class): void {
            $this->assertSame('WELDOLET', $this->classes->branchFor($class, 50, 50)->component_type);
        });
    }

    // ------------------------------------------------------------------
    // Tenancy mirror
    // ------------------------------------------------------------------

    public function test_a_non_participant_company_sees_no_classes(): void
    {
        $class = $this->makeClass('ISO-CLS');

        $this->asPars(function () use ($class): void {
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE, 15, 600, ['schedule' => '40'], 'en');
        });

        $kish = $this->tenant->forIdentityLookup(fn () => Project::where('code', 'KISH-1')->firstOrFail());
        $arvand = $this->company('EPC-ARVAND');

        $this->tenant->runAs($arvand->id, function (): void {
            $this->assertSame(0, PipingClass::count());
            $this->assertSame(0, DB::table('piping_class_rules')->count());
        }, $kish->id);
    }
}
