<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AxisAngleRotation3D;
use App\Services\ComponentTransform3D;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AxisAngleRotation3DTest extends TestCase
{
    public function test_positive_right_hand_quarter_turn_about_z_maps_x_to_y(): void
    {
        $result = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 1], 90);
        $direction = ComponentTransform3D::transformDirection([1, 0, 0], $result['rotation_matrix']);

        $this->assertSame(AxisAngleRotation3D::METHOD, $result['method']);
        $this->assertEqualsWithDelta(0.0, $direction[0], 1e-12);
        $this->assertEqualsWithDelta(1.0, $direction[1], 1e-12);
        $this->assertEqualsWithDelta(0.0, $direction[2], 1e-12);
    }

    public function test_negative_angle_maps_x_to_negative_y(): void
    {
        $result = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 1], -90);
        $direction = ComponentTransform3D::transformDirection([1, 0, 0], $result['rotation_matrix']);

        $this->assertEqualsWithDelta(0.0, $direction[0], 1e-12);
        $this->assertEqualsWithDelta(-1.0, $direction[1], 1e-12);
        $this->assertEqualsWithDelta(0.0, $direction[2], 1e-12);
    }

    public function test_reversing_axis_is_equivalent_to_reversing_angle(): void
    {
        $forward = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 1], -90)['rotation_matrix'];
        $reverseAxis = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, -1], 90)['rotation_matrix'];

        foreach ($forward as $row => $values) {
            foreach ($values as $column => $value) {
                $this->assertEqualsWithDelta($value, $reverseAxis[$row][$column], 1e-12);
            }
        }
    }

    public function test_axis_magnitude_does_not_change_the_rotation(): void
    {
        $unitAxis = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 1], 37)['rotation_matrix'];
        $scaledAxis = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 12], 37)['rotation_matrix'];

        foreach ($unitAxis as $row => $values) {
            foreach ($values as $column => $value) {
                $this->assertEqualsWithDelta($value, $scaledAxis[$row][$column], 1e-12);
            }
        }
    }

    public function test_angle_is_periodic_without_defining_an_input_range(): void
    {
        $quarterTurn = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 1], 90)['rotation_matrix'];
        $wrappedTurn = AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 1], 450)['rotation_matrix'];

        foreach ($quarterTurn as $row => $values) {
            foreach ($values as $column => $value) {
                $this->assertEqualsWithDelta($value, $wrappedTurn[$row][$column], 1e-12);
            }
        }
    }

    public function test_zero_angle_returns_identity_for_a_valid_axis(): void
    {
        $result = AxisAngleRotation3D::matrixFromAxisAngleDeg([1, 2, 3], 0);
        $identity = [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]];

        foreach ($identity as $row => $values) {
            foreach ($values as $column => $value) {
                $this->assertEqualsWithDelta($value, $result['rotation_matrix'][$row][$column], 1e-12);
            }
        }
    }

    public function test_zero_or_malformed_axis_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 0], 90);
    }

    public function test_malformed_axis_shape_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 1], 90);
    }

    public function test_non_finite_axis_component_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AxisAngleRotation3D::matrixFromAxisAngleDeg([0, INF, 0], 90);
    }

    public function test_non_finite_angle_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AxisAngleRotation3D::matrixFromAxisAngleDeg([0, 0, 1], NAN);
    }
}
