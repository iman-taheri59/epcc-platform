<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ComponentTransform3D;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ComponentTransform3DTest extends TestCase
{
    public function test_identity_transform_preserves_a_local_point_and_direction(): void
    {
        $identity = [
            [1.0, 0.0, 0.0],
            [0.0, 1.0, 0.0],
            [0.0, 0.0, 1.0],
        ];

        $this->assertSame([1.0, 2.0, 3.0], ComponentTransform3D::transformPoint([1, 2, 3], $identity, [0, 0, 0]));
        $this->assertSame([1.0, 2.0, 3.0], ComponentTransform3D::transformDirection([1, 2, 3], $identity));
    }

    public function test_positive_right_hand_quarter_turn_about_model_z_maps_local_x_to_model_y(): void
    {
        $rotation = [
            [0.0, -1.0, 0.0],
            [1.0, 0.0, 0.0],
            [0.0, 0.0, 1.0],
        ];

        $point = ComponentTransform3D::transformPoint([1, 0, 0], $rotation, [10, 20, 30]);
        $direction = ComponentTransform3D::transformDirection([1, 0, 0], $rotation);

        $this->assertEqualsWithDelta(10.0, $point[0], 1e-12);
        $this->assertEqualsWithDelta(21.0, $point[1], 1e-12);
        $this->assertEqualsWithDelta(30.0, $point[2], 1e-12);
        $this->assertEqualsWithDelta(0.0, $direction[0], 1e-12);
        $this->assertEqualsWithDelta(1.0, $direction[1], 1e-12);
        $this->assertEqualsWithDelta(0.0, $direction[2], 1e-12);
    }

    public function test_negative_right_hand_quarter_turn_about_model_z_maps_local_x_to_negative_model_y(): void
    {
        $rotation = [
            [0.0, 1.0, 0.0],
            [-1.0, 0.0, 0.0],
            [0.0, 0.0, 1.0],
        ];

        $direction = ComponentTransform3D::transformDirection([1, 0, 0], $rotation);

        $this->assertEqualsWithDelta(0.0, $direction[0], 1e-12);
        $this->assertEqualsWithDelta(-1.0, $direction[1], 1e-12);
        $this->assertEqualsWithDelta(0.0, $direction[2], 1e-12);
    }

    public function test_rotation_must_be_orthonormal_without_scale_or_shear(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentTransform3D::transformDirection([1, 0, 0], [
            [2.0, 0.0, 0.0],
            [0.0, 1.0, 0.0],
            [0.0, 0.0, 1.0],
        ]);
    }

    public function test_reflection_is_not_accepted_as_a_component_rotation(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ComponentTransform3D::transformDirection([1, 0, 0], [
            [-1.0, 0.0, 0.0],
            [0.0, 1.0, 0.0],
            [0.0, 0.0, 1.0],
        ]);
    }

    public function test_malformed_or_non_finite_vectors_are_refused(): void
    {
        try {
            ComponentTransform3D::transformPoint([1, 2], [[1, 0, 0], [0, 1, 0], [0, 0, 1]], [0, 0, 0]);
            $this->fail('A two-coordinate point was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('localPoint', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        ComponentTransform3D::transformDirection([NAN, 0, 1], [[1, 0, 0], [0, 1, 0], [0, 0, 1]]);
    }
}
