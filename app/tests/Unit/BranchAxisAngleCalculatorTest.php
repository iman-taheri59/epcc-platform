<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchAxisAngleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchAxisAngleCalculatorTest extends TestCase
{
    public function test_known_45_60_and_90_degree_perpendicular_cases(): void
    {
        $cases = [
            [[1, 0, 0], [1, 1, 0], 45.0],
            [[1, 0, 0], [0.5, sqrt(3) / 2, 0], 60.0],
            [[1, 0, 0], [0, 0, 1], 90.0],
        ];

        foreach ($cases as [$header, $branch, $expected]) {
            $result = BranchAxisAngleCalculator::measure($header, $branch);
            $this->assertEqualsWithDelta($expected, $result['beta_deg'], 1e-10);
            $this->assertEqualsWithDelta($expected, $result['angle_between_oriented_vectors_deg'], 1e-10);
            $this->assertSame(BranchAxisAngleCalculator::METHOD, $result['method']);
        }
    }

    public function test_negative_component_direction_preserves_smaller_unoriented_beta(): void
    {
        $result = BranchAxisAngleCalculator::measure([1, 0, 0], [-0.5, sqrt(3) / 2, 0]);

        $this->assertEqualsWithDelta(60.0, $result['beta_deg'], 1e-10);
        $this->assertEqualsWithDelta(120.0, $result['angle_between_oriented_vectors_deg'], 1e-10);
        $this->assertEqualsWithDelta(-0.5, $result['signed_header_axis_projection'], 1e-12);
    }

    public function test_reversing_either_axis_leaves_beta_unchanged_but_retains_signed_direction(): void
    {
        $base = BranchAxisAngleCalculator::measure([1, 0, 0], [0.5, sqrt(3) / 2, 0]);
        $reverseHeader = BranchAxisAngleCalculator::measure([-1, 0, 0], [0.5, sqrt(3) / 2, 0]);
        $reverseBranch = BranchAxisAngleCalculator::measure([1, 0, 0], [-0.5, -sqrt(3) / 2, 0]);

        $this->assertEqualsWithDelta(60.0, $base['beta_deg'], 1e-10);
        $this->assertEqualsWithDelta($base['beta_deg'], $reverseHeader['beta_deg'], 1e-10);
        $this->assertEqualsWithDelta($base['beta_deg'], $reverseBranch['beta_deg'], 1e-10);
        $this->assertLessThan(0.0, $reverseHeader['signed_header_axis_projection']);
        $this->assertLessThan(0.0, $reverseBranch['signed_header_axis_projection']);
    }

    public function test_three_dimensional_branch_transverse_direction_is_returned_in_shared_frame(): void
    {
        $result = BranchAxisAngleCalculator::measure([1, 0, 0], [1, 2, 2]);

        $this->assertEqualsWithDelta(rad2deg(acos(1 / 3)), $result['beta_deg'], 1e-10);
        $this->assertNotNull($result['branch_transverse_unit_vector']);
        $this->assertEqualsWithDelta(0.0, $result['branch_transverse_unit_vector'][0], 1e-12);
        $this->assertEqualsWithDelta(1 / sqrt(2), $result['branch_transverse_unit_vector'][1], 1e-12);
        $this->assertEqualsWithDelta(1 / sqrt(2), $result['branch_transverse_unit_vector'][2], 1e-12);
    }

    public function test_parallel_and_antiparallel_axes_have_zero_beta_and_no_transverse_direction(): void
    {
        foreach ([[1, 0, 0], [-1, 0, 0]] as $branch) {
            $result = BranchAxisAngleCalculator::measure([1, 0, 0], $branch);
            $this->assertEqualsWithDelta(0.0, $result['beta_deg'], 1e-12);
            $this->assertNull($result['branch_transverse_unit_vector']);
        }
    }

    public function test_zero_axes_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchAxisAngleCalculator::measure([0, 0, 0], [1, 0, 0]);
    }

    public function test_non_finite_axes_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchAxisAngleCalculator::measure([1, 0, 0], [1, INF, 0]);
    }
}
