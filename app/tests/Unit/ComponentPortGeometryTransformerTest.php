<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ComponentPortGeometryTransformer;
use App\Services\FramedRigidTransform3D;
use App\Services\LinearTwoPortComponentGeometryProfile;
use App\Services\ManualComponentOrientation3D;
use App\Services\StraightPipeGeometryProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ComponentPortGeometryTransformerTest extends TestCase
{
    public function test_straight_pipe_ports_resolve_in_model_frame_from_manual_pose(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(200.0);
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:pipe-42:local:v1',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [1000, 2000, 300],
            azimuthDeg: 90,
            inclinationDeg: 0,
            rollDeg: 0,
        );

        $resolved = ComponentPortGeometryTransformer::transform($profile, $pose);

        $this->assertSame('RESOLVED', $resolved['status']);
        $this->assertSame(StraightPipeGeometryProfile::PROFILE_ID, $resolved['profile_id']);
        $this->assertSame('1', $resolved['profile_revision']);
        $this->assertSame('project:model:v1', $resolved['model_frame_id']);
        $this->assertSame('MM', $resolved['coordinate_unit']);
        $this->assertVectorEquals([0, 0, 0], $resolved['anchor_local_mm']);
        $this->assertVectorEquals([1000, 2000, 300], $resolved['anchor_model']);
        $this->assertCount(2, $resolved['ports']);

        $this->assertSame([1, 2], array_column($resolved['ports'], 'seq'));
        $this->assertSame(['RUN', 'RUN'], array_column($resolved['ports'], 'role'));
        $this->assertVectorEquals([1000, 2000, 300], $resolved['ports'][0]['point_model']);
        $this->assertVectorEquals([1000, 2200, 300], $resolved['ports'][1]['point_model']);
        $this->assertVectorEquals([0, -1, 0], $resolved['ports'][0]['outward_direction_model']);
        $this->assertVectorEquals([0, 1, 0], $resolved['ports'][1]['outward_direction_model']);
    }

    public function test_explicit_length_nipple_has_its_own_profile_and_transformed_run_ports(): void
    {
        $profile = LinearTwoPortComponentGeometryProfile::resolve('NIPPLE', 180.0);
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:nipple-12:local:v1',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [100, 200, 300],
            azimuthDeg: 0,
            inclinationDeg: 0,
            rollDeg: 45,
        );

        $resolved = ComponentPortGeometryTransformer::transform($profile, $pose);

        $this->assertSame('STRAIGHT_NIPPLE_TWO_PORTS_V1', $resolved['profile_id']);
        $this->assertSame('RESOLVED', $resolved['status']);
        $this->assertSame(['RUN', 'RUN'], array_column($resolved['ports'], 'role'));
        $this->assertVectorEquals([100, 200, 300], $resolved['ports'][0]['point_model']);
        $this->assertVectorEquals([280, 200, 300], $resolved['ports'][1]['point_model']);
        $this->assertVectorEquals([-1, 0, 0], $resolved['ports'][0]['outward_direction_model']);
        $this->assertVectorEquals([1, 0, 0], $resolved['ports'][1]['outward_direction_model']);
    }

    public function test_missing_nipple_length_is_unresolved_without_inventing_port_two(): void
    {
        $profile = LinearTwoPortComponentGeometryProfile::resolve('NIPPLE', null);

        $this->assertSame('STRAIGHT_NIPPLE_TWO_PORTS_V1', $profile['profile_id']);
        $this->assertSame('UNRESOLVED', $profile['status']);
        $this->assertSame('MISSING_LENGTH', $profile['reason']);
        $this->assertSame([], $profile['ports']);
    }

    public function test_zero_length_nipple_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LinearTwoPortComponentGeometryProfile::resolve('NIPPLE', 0.0);
    }

    public function test_elbow_centre_to_end_value_is_not_guessed_as_a_straight_profile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LinearTwoPortComponentGeometryProfile::resolve('ELBOW', 180.0);
    }

    public function test_missing_pipe_length_is_reported_unresolved_without_inventing_port_two(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(null);
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:pipe-unknown-length:local:v1',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [10, 20, 30],
            azimuthDeg: 0,
            inclinationDeg: 0,
            rollDeg: 0,
        );

        $resolved = ComponentPortGeometryTransformer::transform($profile, $pose);

        $this->assertSame('UNRESOLVED', $profile['status']);
        $this->assertSame('MISSING_LENGTH', $profile['reason']);
        $this->assertSame([], $resolved['ports']);
        $this->assertSame('UNRESOLVED', $resolved['status']);
        $this->assertSame('MISSING_LENGTH', $resolved['reason']);
    }

    public function test_non_positive_pipe_length_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StraightPipeGeometryProfile::resolve(0.0);
    }

    public function test_pipe_port_geometry_is_independent_of_roll_for_round_pipe_profile(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(125.0);
        $makePose = static fn (float $roll): FramedRigidTransform3D => ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:pipe:local:v1',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [10, 20, 30],
            azimuthDeg: 32,
            inclinationDeg: 17,
            rollDeg: $roll,
        );

        $zeroRoll = ComponentPortGeometryTransformer::transform($profile, $makePose(0));
        $rolled = ComponentPortGeometryTransformer::transform($profile, $makePose(147));

        foreach ([0, 1] as $index) {
            $this->assertVectorEquals(
                $zeroRoll['ports'][$index]['point_model'],
                $rolled['ports'][$index]['point_model'],
            );
            $this->assertVectorEquals(
                $zeroRoll['ports'][$index]['outward_direction_model'],
                $rolled['ports'][$index]['outward_direction_model'],
            );
        }
    }

    public function test_profile_and_pose_units_must_match_exactly(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(100.0);
        $profile['coordinate_unit'] = 'IN';
        $pose = ManualComponentOrientation3D::framedTransform(
            'component:local',
            'project:model:v1',
            'MM',
            [0, 0, 0],
            0,
            0,
            0,
        );

        $this->expectException(InvalidArgumentException::class);
        ComponentPortGeometryTransformer::transform($profile, $pose);
    }

    public function test_profile_with_non_contractual_primary_axis_is_rejected(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(100.0);
        $profile['primary_axis_local'] = [0, 1, 0];
        $pose = ManualComponentOrientation3D::framedTransform(
            'component:local',
            'project:model:v1',
            'MM',
            [0, 0, 0],
            0,
            0,
            0,
        );

        $this->expectException(InvalidArgumentException::class);
        ComponentPortGeometryTransformer::transform($profile, $pose);
    }

    public function test_non_origin_local_anchor_is_rejected_instead_of_silently_ignored(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(100.0);
        $profile['anchor_local_mm'] = [1, 0, 0];
        $pose = ManualComponentOrientation3D::framedTransform(
            'component:local',
            'project:model:v1',
            'MM',
            [0, 0, 0],
            0,
            0,
            0,
        );

        $this->expectException(InvalidArgumentException::class);
        ComponentPortGeometryTransformer::transform($profile, $pose);
    }

    public function test_duplicate_port_sequences_are_rejected(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(100.0);
        $profile['ports'][1]['seq'] = 1;
        $pose = ManualComponentOrientation3D::framedTransform(
            'component:local',
            'project:model:v1',
            'MM',
            [0, 0, 0],
            0,
            0,
            0,
        );

        $this->expectException(InvalidArgumentException::class);
        ComponentPortGeometryTransformer::transform($profile, $pose);
    }

    public function test_non_unit_port_direction_is_rejected(): void
    {
        $profile = StraightPipeGeometryProfile::resolve(100.0);
        $profile['ports'][0]['outward_direction_local'] = [-2, 0, 0];
        $pose = ManualComponentOrientation3D::framedTransform(
            'component:local',
            'project:model:v1',
            'MM',
            [0, 0, 0],
            0,
            0,
            0,
        );

        $this->expectException(InvalidArgumentException::class);
        ComponentPortGeometryTransformer::transform($profile, $pose);
    }

    /** @param list<int|float> $expected @param list<int|float> $actual */
    private function assertVectorEquals(array $expected, array $actual, float $delta = 1e-10): void
    {
        $this->assertCount(3, $actual);
        foreach ($expected as $index => $value) {
            $this->assertEqualsWithDelta((float) $value, (float) $actual[$index], $delta);
        }
    }
}
