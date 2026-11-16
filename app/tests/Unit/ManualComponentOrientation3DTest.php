<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ComponentTransform3D;
use App\Services\ManualComponentOrientation3D;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ManualComponentOrientation3DTest extends TestCase
{
    public function test_zero_angles_map_the_component_local_frame_to_project_model_axes(): void
    {
        $resolved = ManualComponentOrientation3D::resolveDeg(0, 0, 0);
        $identity = [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]];

        $this->assertSame(ManualComponentOrientation3D::METHOD, $resolved['method']);
        $this->assertSame([0.0, 0.0, 0.0], array_values($resolved['angles_deg']));
        foreach ($identity as $row => $values) {
            foreach ($values as $column => $value) {
                $this->assertEqualsWithDelta($value, $resolved['rotation_local_to_model'][$row][$column], 1e-12);
            }
        }
    }

    public function test_positive_azimuth_turns_local_x_from_easting_toward_northing(): void
    {
        $resolved = ManualComponentOrientation3D::resolveDeg(90, 0, 0);

        $this->assertVectorEquals([0, 1, 0], $resolved['local_axes_in_model']['+X']);
        $this->assertVectorEquals([-1, 0, 0], $resolved['local_axes_in_model']['+Y']);
        $this->assertVectorEquals([0, 0, 1], $resolved['local_axes_in_model']['+Z']);
    }

    public function test_positive_inclination_raises_the_primary_axis_toward_model_z(): void
    {
        $resolved = ManualComponentOrientation3D::resolveDeg(0, 30, 0);

        $this->assertVectorEquals([sqrt(3) / 2, 0, 0.5], $resolved['local_axes_in_model']['+X']);
    }

    public function test_positive_roll_is_right_handed_about_the_oriented_primary_axis(): void
    {
        $resolved = ManualComponentOrientation3D::resolveDeg(0, 0, 90);

        $this->assertVectorEquals([1, 0, 0], $resolved['local_axes_in_model']['+X']);
        $this->assertVectorEquals([0, 0, 1], $resolved['local_axes_in_model']['+Y']);
        $this->assertVectorEquals([0, -1, 0], $resolved['local_axes_in_model']['+Z']);
    }

    public function test_vertical_axis_retains_azimuth_as_its_transverse_clocking_datum(): void
    {
        $eastHeading = ManualComponentOrientation3D::resolveDeg(0, 90, 0);
        $northHeading = ManualComponentOrientation3D::resolveDeg(90, 90, 0);

        $this->assertVectorEquals([0, 0, 1], $eastHeading['local_axes_in_model']['+X']);
        $this->assertVectorEquals([0, 0, 1], $northHeading['local_axes_in_model']['+X']);
        $this->assertVectorEquals([0, 1, 0], $eastHeading['local_axes_in_model']['+Y']);
        $this->assertVectorEquals([-1, 0, 0], $northHeading['local_axes_in_model']['+Y']);
    }

    public function test_periodic_azimuth_and_roll_are_normalized_to_half_open_range(): void
    {
        $resolved = ManualComponentOrientation3D::resolveDeg(450, 45, -540);

        $this->assertSame(90.0, $resolved['angles_deg']['azimuth']);
        $this->assertSame(45.0, $resolved['angles_deg']['inclination']);
        $this->assertSame(-180.0, $resolved['angles_deg']['roll']);
    }

    public function test_periodic_roll_normalizes_to_negative_180_at_the_boundary(): void
    {
        $resolved = ManualComponentOrientation3D::resolveDeg(-180, 0, 540);

        $this->assertSame(-180.0, $resolved['angles_deg']['azimuth']);
        $this->assertSame(-180.0, $resolved['angles_deg']['roll']);
    }

    public function test_generated_matrix_is_accepted_by_the_rigid_transform_validator(): void
    {
        $resolved = ManualComponentOrientation3D::resolveDeg(-135, -42.5, 721.25);
        $transformed = ComponentTransform3D::transformDirection(
            [1, 0, 0],
            $resolved['rotation_local_to_model'],
        );

        $this->assertVectorEquals($resolved['local_axes_in_model']['+X'], $transformed);
    }

    public function test_framed_transform_places_a_local_point_at_the_selected_anchor_and_orientation(): void
    {
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:local',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [1000, 2000, 300],
            azimuthDeg: 90,
            inclinationDeg: 0,
            rollDeg: 0,
        );

        $point = $pose->transformPoint([25, 0, 0], 'component:local', 'MM');

        $this->assertSame('project:model:v1', $point['frame_id']);
        $this->assertSame('MM', $point['coordinate_unit']);
        $this->assertVectorEquals([1000, 2025, 300], $point['coordinates']);
    }

    public function test_inclination_outside_the_supported_range_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ManualComponentOrientation3D::resolveDeg(0, 90.001, 0);
    }

    public function test_non_finite_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ManualComponentOrientation3D::resolveDeg(0, NAN, 0);
    }

    /** @param list<int|float> $expected @param list<int|float> $actual */
    private function assertVectorEquals(array $expected, array $actual, float $delta = 1e-12): void
    {
        $this->assertCount(3, $actual);
        foreach ($expected as $index => $value) {
            $this->assertEqualsWithDelta((float) $value, (float) $actual[$index], $delta);
        }
    }
}
