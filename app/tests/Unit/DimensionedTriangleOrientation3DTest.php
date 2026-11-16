<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\DimensionedTriangleOrientation3D;
use App\Services\PlanarRunRiseAngleCalculator;
use App\Services\RunRiseHypotenuseClosureCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DimensionedTriangleOrientation3DTest extends TestCase
{
    public function test_known_signed_three_four_five_triangle_resolves_in_declared_plane(): void
    {
        $resolved = DimensionedTriangleOrientation3D::resolveDeg(
            azimuthDeg: 90,
            rollDeg: 25,
            runMm: 3000,
            riseMm: 4000,
            measuredHypotenuseMm: 5000.2,
        );

        $this->assertSame(DimensionedTriangleOrientation3D::METHOD, $resolved['method']);
        $this->assertSame(90.0, $resolved['azimuth_deg']);
        $this->assertSame(25.0, $resolved['roll_deg']);
        $this->assertEqualsWithDelta(53.13010235415598, $resolved['inclination_deg'], 1e-12);
        $this->assertEqualsWithDelta(5000.0, $resolved['calculated_hypotenuse_mm'], 1e-9);
        $this->assertSame(5000.2, $resolved['measured_hypotenuse_mm']);
        $this->assertEqualsWithDelta(0.2, $resolved['closure_residual_mm'], 1e-9);
        $this->assertArrayNotHasKey('accepted', $resolved, 'A residual is not an engineering acceptance verdict.');

        $this->assertSame([
            'input_mode' => 'DIMENSIONED_TRIANGLE',
            'adapter_method' => DimensionedTriangleOrientation3D::METHOD,
            'angle_calculation_method' => PlanarRunRiseAngleCalculator::METHOD,
            'closure_calculation_method' => RunRiseHypotenuseClosureCalculator::METHOD,
            'angle_unit' => 'DEG',
            'length_unit' => 'MM',
            'azimuth_plane_deg' => 90.0,
            'roll_deg' => 25.0,
            'run_mm' => 3000.0,
            'rise_mm' => 4000.0,
            'measured_hypotenuse_mm' => 5000.2,
            'calculated_hypotenuse_mm' => 5000.0,
        ], array_diff_key($resolved['input_geometry'], ['closure_residual_mm' => true]));
        $this->assertArrayHasKey('closure_residual_mm', $resolved['input_geometry']);
        $this->assertEqualsWithDelta(0.2, $resolved['input_geometry']['closure_residual_mm'], 1e-9);
    }

    public function test_negative_rise_is_retained_and_can_resolve_a_vertical_triangle(): void
    {
        $resolved = DimensionedTriangleOrientation3D::resolveDeg(
            azimuthDeg: 180,
            rollDeg: -35,
            runMm: 0,
            riseMm: -750,
        );

        $this->assertSame(-90.0, $resolved['inclination_deg']);
        $this->assertNull($resolved['measured_hypotenuse_mm']);
        $this->assertNull($resolved['closure_residual_mm']);
        $this->assertSame(-750.0, $resolved['input_geometry']['rise_mm']);
        $this->assertSame(0.0, $resolved['input_geometry']['run_mm']);
    }

    public function test_a_negative_run_is_rejected_instead_of_reinterpreted_as_a_plane_reversal(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DimensionedTriangleOrientation3D::resolveDeg(0, 0, -1, 2);
    }

    public function test_zero_length_triangle_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DimensionedTriangleOrientation3D::resolveDeg(0, 0, 0, 0);
    }

    public function test_invalid_measured_hypotenuse_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DimensionedTriangleOrientation3D::resolveDeg(0, 0, 3, 4, 0);
    }

    public function test_non_finite_plane_or_clocking_input_is_rejected(): void
    {
        try {
            DimensionedTriangleOrientation3D::resolveDeg(INF, 0, 3, 4);
            $this->fail('A missing/invalid azimuth plane must not be inferred.');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        DimensionedTriangleOrientation3D::resolveDeg(0, NAN, 3, 4);
    }
}
