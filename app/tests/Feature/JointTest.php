<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\JointService;
use App\Services\PipingLineService;
use App\Support\LineReleaseState;
use App\Support\Piping\PortCompatibility;
use App\Support\Piping\Verdict;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use Tests\Support\ApprovedPmsClassFixture;

/**
 * ADR-006 phase 3 - joints, compatibility and dia-inch.
 *
 * The three things worth proving here, in order of how expensive they are to
 * get wrong:
 *
 *   1. DIA-INCH USES THE SMALLER DIAMETER. The fixture deliberately contains
 *      reducing welds, because a fixture of equal-bore welds passes under
 *      either convention and proves nothing (ADR-010 section 1). The named
 *      test asserts 19.0 and not 27.0 - a 42% overstatement on a job paid by
 *      dia-inch.
 *   2. THE ORDERED PAIR HOLDS. (A,B) and (B,A) must not both exist, or the
 *      same weld is counted twice.
 *   3. REMOVING A COMPONENT REOPENS ITS NEIGHBOUR. This is the hole phase 2
 *      documented and could not close.
 */
final class JointTest extends TestCase
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

    private function components(): ComponentService
    {
        return app(ComponentService::class);
    }

    private function joints(): JointService
    {
        return app(JointService::class);
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

    /** A port, built in memory only - the compatibility engine never reads the DB. */
    private function port(array $attributes = []): Port
    {
        return new Port(array_merge([
            'seq' => 1,
            'role' => 'RUN',
            'nominal_size' => '6',
            'nominal_size_decimal' => 6.0,
            'end_type' => 'BW',
            'schedule' => '40',
            'is_connected' => false,
        ], $attributes));
    }

    // ------------------------------------------------ the attachment joins --

    public function test_attaching_a_component_creates_a_joint(): void
    {
        $line = $this->line();

        $result = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');

            return $this->components()->attach($branch, $this->owner(), 'PIPE', ['length_mm' => 1500]);
        });

        $this->assertInstanceOf(Joint::class, $result['joint']);
        $this->assertSame($line->id, $result['joint']->piping_line_id);
        $this->assertSame(Joint::STATE_MODELLED, $result['joint']->state);
    }

    public function test_the_joint_links_the_two_ports_that_were_actually_joined(): void
    {
        $line = $this->line();

        [$joint, $parentId, $childId] = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');
            $r = $this->components()->attach($branch, $this->owner(), 'PIPE');

            return [$r['joint'], $branch->id, $r['childPort']->id];
        });

        $this->assertEqualsCanonicalizing(
            [$parentId, $childId],
            [$joint->port_a_id, $joint->port_b_id],
        );
    }

    public function test_the_port_pair_is_stored_in_sorted_order(): void
    {
        // The CHECK demands port_a_id < port_b_id. If the service did not sort
        // the pair, this would fail roughly half the time depending on which
        // UUID happened to be larger - the kind of test that must not be run
        // once and believed.
        $line = $this->line();

        $joints = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');

            foreach ($tee->ports as $port) {
                $this->components()->attach($port, $this->owner(), 'PIPE');
            }

            return Joint::where('piping_line_id', $line->id)->get();
        });

        $this->assertCount(3, $joints);

        foreach ($joints as $joint) {
            $this->assertLessThan(0, strcmp($joint->port_a_id, $joint->port_b_id));
        }
    }

    public function test_the_reverse_pair_cannot_also_be_inserted(): void
    {
        $line = $this->line();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $pipe = $this->components()->place($line, $this->owner(), 'PIPE');

            $a = $tee->ports->first();
            $b = $pipe->ports->first();

            // Hand-written INSERT with the pair reversed, bypassing the
            // service. The database must refuse it on its own - a rule that
            // only exists in PHP is a rule an import can walk around.
            [$hi, $lo] = strcmp($a->id, $b->id) > 0 ? [$a->id, $b->id] : [$b->id, $a->id];

            DB::table('joints')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $line->project_id,
                'company_id' => $this->project()->owner_company_id,
                'piping_line_id' => $line->id,
                'port_a_id' => $hi,
                'port_b_id' => $lo,
                'joint_type' => 'BUTT_WELD',
                'state' => 'MODELLED',
                'numbering_policy' => 'REQUIRED',
                'compatibility_state' => 'VALID',
                'source' => 'MANUAL',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_a_port_cannot_be_in_two_joints(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');

            $this->components()->attach($branch, $this->owner(), 'PIPE');

            // The branch is now welded. A second attachment to the same port
            // would mean two things welded to one end of a tee.
            $this->components()->attach($branch->fresh(), $this->owner(), 'PIPE');
        });
    }

    // ------------------------------------------------------------ numbering --

    public function test_welded_joints_are_numbered_from_one_per_line(): void
    {
        $line = $this->line();

        $seqs = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');

            foreach ($tee->ports as $port) {
                $this->components()->attach($port, $this->owner(), 'PIPE');
            }

            return Joint::where('piping_line_id', $line->id)->orderBy('joint_seq')->pluck('joint_seq')->all();
        });

        $this->assertSame([1, 2, 3], $seqs);
    }

    public function test_a_flanged_joint_gets_no_number_and_is_marked_not_applicable(): void
    {
        // The distinction the numbering_policy column exists for: this is not
        // a weld awaiting a number, it is a joint that will never have one
        // (ADR-006 section 11.2).
        $line = $this->line();

        $joint = $this->asOwner(function () use ($line) {
            $flangeA = $this->components()->place($line, $this->owner(), 'FLANGE');
            $flangeB = $this->components()->place($line, $this->owner(), 'FLANGE');

            $a = $flangeA->ports->firstWhere('end_type', 'FL');
            $b = $flangeB->ports->firstWhere('end_type', 'FL');

            return $this->joints()->join($a, $b, $this->owner());
        });

        $this->assertNull($joint->joint_seq);
        $this->assertSame(Joint::NUMBERING_NOT_APPLICABLE, $joint->numbering_policy);
        $this->assertSame('FLANGED', $joint->joint_type);
    }

    public function test_an_unnumberable_joint_cannot_be_given_a_number(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $flangeA = $this->components()->place($line, $this->owner(), 'FLANGE');
            $flangeB = $this->components()->place($line, $this->owner(), 'FLANGE');

            $joint = $this->joints()->join(
                $flangeA->ports->firstWhere('end_type', 'FL'),
                $flangeB->ports->firstWhere('end_type', 'FL'),
                $this->owner(),
            );

            $this->joints()->assignNumber($joint, $this->owner(), 5);
        });
    }

    public function test_the_database_refuses_a_number_on_a_not_applicable_joint(): void
    {
        // Same rule as above, one layer down. Switching a weld to a flanged
        // connection must not leave the old number behind to keep appearing
        // on reports.
        $line = $this->line();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            DB::table('joints')->where('id', $r['joint']->id)->update([
                'numbering_policy' => 'NOT_APPLICABLE',
            ]);
        });
    }

    public function test_numbers_are_never_reused_after_a_joint_is_deleted(): void
    {
        // max()+1, not count()+1. A reissued number is a number that means two
        // different welds on two different drawings.
        $line = $this->line();

        $seq = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');

            $first = $this->components()->attach($tee->ports[0], $this->owner(), 'PIPE');
            $this->components()->attach($tee->ports[1], $this->owner(), 'PIPE');

            $this->joints()->separate($first['joint'], $this->owner());

            $third = $this->components()->attach($tee->ports[2]->fresh(), $this->owner(), 'PIPE');

            return $third['joint']->joint_seq;
        });

        $this->assertSame(3, $seq);
    }

    public function test_two_joints_on_one_line_cannot_share_a_number(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');

            $first = $this->components()->attach($tee->ports[0], $this->owner(), 'PIPE');
            $second = $this->components()->attach($tee->ports[1], $this->owner(), 'PIPE');

            $this->joints()->assignNumber($second['joint'], $this->owner(), (int) $first['joint']->joint_seq);
        });
    }

    public function test_the_display_number_is_padded_but_the_column_is_not(): void
    {
        $line = $this->line();

        $joint = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');

            return $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE')['joint'];
        });

        $this->assertSame(1, $joint->joint_seq);
        $this->assertSame('001', $joint->displayNumber());
    }

    // -------------------------------------------------------- compatibility --

    public function test_a_butt_weld_onto_a_flange_face_is_refused(): void
    {
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['end_type' => 'BW']),
            $this->port(['end_type' => 'FL']),
        );

        $this->assertTrue($verdict->isInvalid());
        $this->assertTrue($verdict->has('end_mismatch'));
    }

    public function test_mismatched_sizes_are_refused(): void
    {
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['nominal_size' => '6', 'nominal_size_decimal' => 6.0]),
            $this->port(['nominal_size' => '2', 'nominal_size_decimal' => 2.0]),
        );

        $this->assertTrue($verdict->isInvalid());
        $this->assertTrue($verdict->has('size_mismatch'));
    }

    public function test_a_schedule_break_warns_but_does_not_refuse(): void
    {
        // The engineer may be right: this is exactly what a spec break looks
        // like. A boolean verdict would force it to be either a lie or a
        // blocker (ADR-006 section 5.4).
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['schedule' => '40']),
            $this->port(['schedule' => '80']),
        );

        $this->assertTrue($verdict->isWarning());
        $this->assertTrue($verdict->has('schedule_mismatch'));
        $this->assertFalse($verdict->isInvalid());
    }

    public function test_std_against_sch40_does_not_warn_at_six_inch(): void
    {
        // Same wall, different name. Comparing the resolved WALL rather than
        // the schedule string is the whole point: nagging about STD vs 40 at
        // 6 inch would train people to ignore the warning.
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['nominal_size' => '6', 'nominal_size_decimal' => 6.0, 'schedule' => 'STD']),
            $this->port(['nominal_size' => '6', 'nominal_size_decimal' => 6.0, 'schedule' => '40']),
        );

        $this->assertTrue($verdict->isValid());
    }

    public function test_std_against_sch40_does_warn_at_twelve_inch(): void
    {
        // And above 10 inch they genuinely differ - 10.31 mm against 9.53 mm.
        // This is the pair of tests that stops the wall comparison being
        // "optimised" into a string comparison later.
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['nominal_size' => '12', 'nominal_size_decimal' => 12.0, 'schedule' => 'STD']),
            $this->port(['nominal_size' => '12', 'nominal_size_decimal' => 12.0, 'schedule' => '40']),
        );

        $this->assertTrue($verdict->isWarning());
        $this->assertTrue($verdict->has('schedule_mismatch'));
    }

    public function test_a_plain_end_may_be_welded_to_a_bevelled_one(): void
    {
        // A field-cut pipe. The plain end gets bevelled on site; refusing it
        // would block the commonest field weld there is.
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['end_type' => 'PL']),
            $this->port(['end_type' => 'BW']),
        );

        $this->assertTrue($verdict->isValid());
    }

    public function test_an_unknown_end_code_is_refused_not_ignored(): void
    {
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['end_type' => 'BX']),
            $this->port(['end_type' => 'BW']),
        );

        $this->assertTrue($verdict->isInvalid());
        $this->assertTrue($verdict->has('end_unknown_code'));
    }

    public function test_a_port_with_no_size_is_refused_rather_than_sized_as_zero(): void
    {
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['nominal_size' => null, 'nominal_size_decimal' => null]),
            $this->port(),
        );

        $this->assertTrue($verdict->isInvalid());
        $this->assertTrue($verdict->has('size_unknown'));
    }

    public function test_a_port_cannot_be_joined_to_itself(): void
    {
        // No application path produces this - but a PCF import whose
        // END-POINT coordinates collide certainly can, and without the guard
        // it falls straight through to "equal bore, equal schedule, VALID"
        // and books dia-inch for a weld that does not exist.
        // Found by a surviving mutant, not by writing the test first.
        $port = $this->port();
        $port->id = '0f2b1a7c-0000-4000-8000-000000000001';

        $verdict = app(PortCompatibility::class)->evaluate($port, $port);

        $this->assertTrue($verdict->isInvalid());
        $this->assertTrue($verdict->has('self_joint'));
    }

    public function test_the_wildcard_end_agrees_with_anything(): void
    {
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['end_type' => '**']),
            $this->port(['end_type' => 'FL']),
        );

        $this->assertFalse($verdict->isInvalid());
    }

    public function test_a_socket_weld_pair_warns_about_unverified_gender(): void
    {
        $verdict = app(PortCompatibility::class)->evaluate(
            $this->port(['end_type' => 'SW', 'nominal_size' => '2', 'nominal_size_decimal' => 2.0]),
            $this->port(['end_type' => 'SW', 'nominal_size' => '2', 'nominal_size_decimal' => 2.0]),
        );

        $this->assertTrue($verdict->isWarning());
        $this->assertTrue($verdict->has('gender_unverified'));
    }

    public function test_an_invalid_pair_is_refused_by_the_service_not_merely_recorded(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $flange = $this->components()->place($line, $this->owner(), 'FLANGE');
            $pipe = $this->components()->place($line, $this->owner(), 'PIPE');

            $this->joints()->join(
                $flange->ports->firstWhere('end_type', 'FL'),
                $pipe->ports->first(),
                $this->owner(),
            );
        });
    }

    public function test_a_refused_join_leaves_both_ports_open(): void
    {
        // The check runs before any write. A half-connected port is worse than
        // a refusal: it is invisible to the open-ends worklist.
        $line = $this->line();

        [$openA, $openB] = $this->asOwner(function () use ($line) {
            $flange = $this->components()->place($line, $this->owner(), 'FLANGE');
            $pipe = $this->components()->place($line, $this->owner(), 'PIPE');

            $a = $flange->ports->firstWhere('end_type', 'FL');
            $b = $pipe->ports->first();

            try {
                $this->joints()->join($a, $b, $this->owner());
            } catch (ValidationException) {
                // expected
            }

            return [$a->fresh()->is_connected, $b->fresh()->is_connected];
        });

        $this->assertFalse($openA);
        $this->assertFalse($openB);
    }

    public function test_the_warning_reason_is_stored_on_the_joint(): void
    {
        // A warning whose reason is lost is indistinguishable from an
        // unexplained one, and nobody clears a warning they cannot read.
        $line = $this->line();

        $joint = $this->asOwner(function () use ($line) {
            $a = $this->components()->place($line, $this->owner(), 'PIPE', ['schedule' => '40']);
            $b = $this->components()->place($line, $this->owner(), 'PIPE', ['schedule' => '80']);

            return $this->joints()->join($a->ports->first(), $b->ports->first(), $this->owner());
        });

        $this->assertSame(Verdict::WARNING, $joint->compatibility_state);
        $this->assertNotNull($joint->compatibility_notes);
        $this->assertSame('schedule_mismatch', $joint->compatibility_notes['reasons'][0]['code']);
    }

    // ------------------------------------------------------------ dia-inch --

    public function test_dia_inch_uses_the_smaller_diameter(): void
    {
        $compat = app(PortCompatibility::class);

        $this->assertSame(2.0, $compat->diaInch(
            $this->port(['nominal_size_decimal' => 6.0]),
            $this->port(['nominal_size_decimal' => 2.0]),
        ));
    }

    public function test_the_adr_sample_totals_nineteen_not_twenty_seven(): void
    {
        // ADR-010 section 1, verbatim. The "larger governs" reading gives 27.0
        // - a 42% overstatement. On a job paid by dia-inch that is an invoice,
        // which is why this test names both numbers.
        $compat = app(PortCompatibility::class);

        // W-001..W-005 from the ADR. The two equal-bore welds are not
        // padding: without them the sample would pass under either convention
        // and prove nothing - the same trap as testing pipe schedules only at
        // 2 inch. Writing this test is what showed the ADR's own table had
        // printed just the three differing rows and summed to 16; the document
        // has been corrected to match.
        $sample = [[6.0, 6.0], [6.0, 2.0], [2.0, 2.0], [12.0, 8.0], [1.0, 1.0]];

        $total = 0.0;

        foreach ($sample as [$a, $b]) {
            $total += $compat->diaInch(
                $this->port(['nominal_size_decimal' => $a]),
                $this->port(['nominal_size_decimal' => $b]),
            );
        }

        $this->assertSame(19.0, $total);

        // Named explicitly: this is the number the wrong convention produces,
        // 42% higher, and on a job paid by dia-inch it is an invoice.
        $this->assertNotSame(27.0, $total);
    }

    public function test_dia_inch_is_null_not_zero_when_a_size_is_missing(): void
    {
        // Zero is a real value that sums silently into a progress total.
        $compat = app(PortCompatibility::class);

        $this->assertNull($compat->diaInch(
            $this->port(['nominal_size_decimal' => null]),
            $this->port(),
        ));
    }

    public function test_a_flanged_joint_contributes_no_dia_inch(): void
    {
        // Bolt-up is real work, but it is not welding, and folding it into the
        // welded figure inflates the number the project is paid against.
        $line = $this->line();

        $dia = $this->asOwner(function () use ($line) {
            $a = $this->components()->place($line, $this->owner(), 'FLANGE');
            $b = $this->components()->place($line, $this->owner(), 'FLANGE');

            $joint = $this->joints()->join(
                $a->ports->firstWhere('end_type', 'FL'),
                $b->ports->firstWhere('end_type', 'FL'),
                $this->owner(),
            );

            return $this->joints()->diaInch($joint);
        });

        $this->assertNull($dia);
    }

    public function test_line_metrics_total_the_welds_with_the_smaller_diameter(): void
    {
        $line = $this->line();

        $metrics = $this->asOwner(function () use ($line) {
            // 6" tee, 2" branch. The run weld is 6 dia-inch, the branch weld
            // is 2 - not 6.
            $tee = $this->components()->place($line, $this->owner(), 'TEE', [
                'port_sizes' => [3 => '2'],
            ]);

            $this->components()->attach($tee->ports->firstWhere('role', 'BRANCH'), $this->owner(), 'PIPE');
            $this->components()->attach($tee->ports->firstWhere('seq', 1), $this->owner(), 'PIPE');

            return $this->joints()->lineMetrics($line->fresh());
        });

        $this->assertSame(2, $metrics['welds']);
        $this->assertSame(8.0, $metrics['dia_inch']);
        $this->assertSame(0, $metrics['fitted']);
    }

    // -------------------------------------------------------------- fit-up --

    public function test_marking_a_joint_fitted_records_who_and_when(): void
    {
        $line = $this->line();

        $joint = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            return $this->joints()->markFitted($r['joint'], $this->owner(), 'FIT-207');
        });

        $this->assertSame(Joint::STATE_FITTED, $joint->state);
        $this->assertSame('FIT-207', $joint->fitted_by_code);
        $this->assertNotNull($joint->fitted_at);
    }

    public function test_the_database_refuses_fitted_without_evidence(): void
    {
        // Same shape as the AFC gate, which demands both the time and the
        // person. A state recording who but not when is not auditable.
        $line = $this->line();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            DB::table('joints')->where('id', $r['joint']->id)->update(['state' => 'FITTED']);
        });
    }

    public function test_an_empty_fitter_code_is_refused(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            $this->joints()->markFitted($r['joint'], $this->owner(), '   ');
        });
    }

    public function test_a_fitted_joint_cannot_be_separated(): void
    {
        // Fit-up is a witnessed event; deleting the joint deletes the record
        // that it happened.
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            $fitted = $this->joints()->markFitted($r['joint'], $this->owner(), 'FIT-207');

            $this->joints()->separate($fitted, $this->owner());
        });
    }

    public function test_fitted_dia_inch_is_counted_separately(): void
    {
        $line = $this->line();

        $metrics = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE', ['port_sizes' => [3 => '2']]);

            $branch = $this->components()->attach($tee->ports->firstWhere('role', 'BRANCH'), $this->owner(), 'PIPE');
            $this->components()->attach($tee->ports->firstWhere('seq', 1), $this->owner(), 'PIPE');

            $this->joints()->markFitted($branch['joint'], $this->owner(), 'FIT-207');

            return $this->joints()->lineMetrics($line->fresh());
        });

        $this->assertSame(8.0, $metrics['dia_inch']);
        $this->assertSame(2.0, $metrics['dia_inch_fitted']);
        $this->assertSame(1, $metrics['fitted']);
    }

    // ------------------------------------------------------ separation --

    public function test_separating_a_joint_reopens_both_ports(): void
    {
        $line = $this->line();

        [$a, $b] = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            $this->joints()->separate($r['joint'], $this->owner());

            return [$r['parentPort']->fresh(), $r['childPort']->fresh()];
        });

        $this->assertFalse($a->is_connected);
        $this->assertFalse($b->is_connected);
    }

    public function test_removing_a_component_reopens_its_neighbours_port(): void
    {
        // THE PHASE 2 HOLE, NOW CLOSED. Phase 2 could only free the removed
        // component's own ports, because nothing recorded which port each had
        // been joined to; the neighbour stayed flagged connected with nothing
        // attached - invisible to the open-ends worklist and never finished.
        $line = $this->line();

        $parentOpen = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');

            $r = $this->components()->attach($branch, $this->owner(), 'PIPE');

            $this->components()->remove($r['component'], $this->owner());

            return $branch->fresh()->is_connected;
        });

        $this->assertFalse($parentOpen);
    }

    public function test_removing_a_component_deletes_the_joints_it_was_in(): void
    {
        $line = $this->line();

        $remaining = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            $this->components()->remove($r['component'], $this->owner());

            return Joint::where('piping_line_id', $line->id)->count();
        });

        $this->assertSame(0, $remaining);
    }

    public function test_the_open_ends_list_grows_back_when_a_component_is_removed(): void
    {
        // The end-to-end consequence, stated in the terms the UI uses.
        $line = $this->line();

        [$before, $after] = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            $before = $this->components()->openPorts($line)->count();

            $this->components()->remove($r['component'], $this->owner());

            return [$before, $this->components()->openPorts($line)->count()];
        });

        // tee(3) + pipe(2) - 2 joined = 3 open; after removing the pipe, the
        // tee's three ports are all open again.
        $this->assertSame(3, $before);
        $this->assertSame(3, $after);
    }

    // ---------------------------------------------------------- guard rails --

    public function test_a_released_line_accepts_no_new_joints(): void
    {
        $line = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($line) {
            $a = $this->components()->place($line, $this->owner(), 'PIPE');
            $b = $this->components()->place($line, $this->owner(), 'PIPE');

            // RELEASED carries TWO provenance CHECKs, not one: the AFC pair
            // and the release pair. Supplying only the first fails with a
            // 23514 that looks like the guard under test firing.
            $line->forceFill([
                'release_state' => LineReleaseState::RELEASED,
                'afc_confirmed_at' => now(),
                'afc_confirmed_by' => $this->owner()->id,
                'released_at' => now(),
                'released_by' => $this->owner()->id,
            ])->save();

            $this->joints()->join($a->ports->first(), $b->ports->first(), $this->owner(), []);
        });
    }

    public function test_ports_on_different_lines_cannot_be_joined_yet(): void
    {
        $lineA = $this->line();
        $lineB = $this->line();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($lineA, $lineB) {
            $a = $this->components()->place($lineA, $this->owner(), 'PIPE');
            $b = $this->components()->place($lineB, $this->owner(), 'PIPE');

            $this->joints()->join($a->ports->first(), $b->ports->first(), $this->owner());
        });
    }

    public function test_shop_components_produce_a_shop_joint(): void
    {
        $line = $this->line();

        $joint = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE', ['fabrication' => 'SHOP']);

            return $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE', [
                'fabrication' => 'SHOP',
            ])['joint'];
        });

        $this->assertSame('SHOP', $joint->shop_field);
    }

    public function test_joining_a_shop_item_to_a_field_item_is_a_field_joint(): void
    {
        // That is what joining a prefabricated spool to another one means.
        $line = $this->line();

        $joint = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE', ['fabrication' => 'SHOP']);

            return $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE', [
                'fabrication' => 'FIELD',
            ])['joint'];
        });

        $this->assertSame('FIELD', $joint->shop_field);
    }

    public function test_the_joint_freezes_the_size_it_was_built_at(): void
    {
        // A joint accepted at 6 inch must keep saying 6 inch even if a port is
        // later edited - the opposite of the dia-inch rule, and deliberately
        // so: this records what was BUILT, dia-inch MEASURES it.
        $line = $this->line();

        $frozen = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE');
            $r = $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE');

            Port::where('id', $r['childPort']->id)->update([
                'nominal_size' => '8',
                'nominal_size_decimal' => 8.0,
            ]);

            return $r['joint']->fresh()->nominal_size;
        });

        $this->assertSame('6', $frozen);
    }

    public function test_the_joint_records_the_wall_thickness_from_the_reference_pack(): void
    {
        $line = $this->line();

        $joint = $this->asOwner(function () use ($line) {
            $tee = $this->components()->place($line, $this->owner(), 'TEE', ['schedule' => '40']);

            return $this->components()->attach($tee->ports->first(), $this->owner(), 'PIPE', [
                'schedule' => '40',
            ])['joint'];
        });

        // 6" SCH 40 = 7.11 mm, ASME B36.10M.
        $this->assertEqualsWithDelta(7.11, $joint->thickness_mm, 0.001);
    }
}
