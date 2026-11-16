<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ComponentPortGeometryTransformer;
use App\Services\ForgedFittingElbowGeometryProfile;
use App\Services\ManualComponentOrientation3D;
use App\Support\Reference\ForgedFittingElbowDimensions;
use App\Support\Reference\PipeReference;
use PHPUnit\Framework\TestCase;

final class ForgedFittingElbowGeometryProfileTest extends TestCase
{
    public function test_socket_weld_uses_table_a_at_the_explicit_socket_bottom_port_datum_and_keeps_j_separate(): void
    {
        $profile = ForgedFittingElbowGeometryProfile::resolve(
            'SW', 3000, '90', '1/2', $this->dimensions(),
        );

        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(ForgedFittingElbowGeometryProfile::PROFILE_ID, $profile['profile_id']);
        self::assertSame('B16.11', $profile['profile_parameters']['standard_code']);
        self::assertSame(2021, $profile['profile_parameters']['standard_edition_year']);
        self::assertSame(3000, $profile['profile_parameters']['class_designation']);
        self::assertSame('SW', $profile['profile_parameters']['end_type']);
        self::assertSame(90.0, $profile['profile_parameters']['bend_angle_deg']);
        self::assertSame('A', $profile['profile_parameters']['dimension_symbol']);
        self::assertSame('CENTRE_TO_BOTTOM_OF_SOCKET', $profile['profile_parameters']['dimension']);
        self::assertSame(15.5, $profile['profile_parameters']['dimension_mm']);
        self::assertSame(9.5, $profile['profile_parameters']['socket_depth_min_mm']);
        self::assertSame('SOCKET_BOTTOM', $profile['profile_parameters']['port_datum']);
        self::assertSame('SOCKET_BOTTOM_AT_TABLE_A_NO_A_MINUS_J_OFFSET', $profile['profile_parameters']['socket_port_datum_contract']);
        self::assertFalse($profile['profile_parameters']['pre_weld_gap_applied']);
        self::assertVector([0, 0, 0], $profile['ports'][0]['point_local_mm']);
        self::assertVector([15.5, 15.5, 0], $profile['ports'][1]['point_local_mm']);
        self::assertVector([-1, 0, 0], $profile['ports'][0]['outward_direction_local']);
        self::assertVector([0, 1, 0], $profile['ports'][1]['outward_direction_local']);
    }

    public function test_socket_weld_45_degree_small_size_uses_a_directly_even_when_a_minus_j_is_negative(): void
    {
        $profile = ForgedFittingElbowGeometryProfile::resolve(
            'SW', 3000, '45', '1/8', $this->dimensions(),
        );
        $a = 8.0;
        $cos45 = sqrt(0.5);

        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(9.5, $profile['profile_parameters']['socket_depth_min_mm']);
        self::assertSame($a, $profile['profile_parameters']['port_axis_distance_mm']);
        self::assertSame('SOCKET_BOTTOM_AT_TABLE_A_NO_A_MINUS_J_OFFSET', $profile['profile_parameters']['socket_port_datum_contract']);
        self::assertVector([
            $a * (1.0 + $cos45),
            $a * $cos45,
            0.0,
        ], $profile['ports'][1]['point_local_mm']);
        self::assertVector([$cos45, $cos45, 0], $profile['ports'][1]['outward_direction_local']);
    }

    public function test_threaded_90_and_45_use_the_exact_tabulated_a_and_c_center_to_end_values(): void
    {
        $dimensions = $this->dimensions();
        $elbow90 = ForgedFittingElbowGeometryProfile::resolve('SC', 2000, '90', '1/8', $dimensions);
        $elbow45 = ForgedFittingElbowGeometryProfile::resolve('SC', 2000, '45', '1/8', $dimensions);

        self::assertSame('RESOLVED', $elbow90['status']);
        self::assertSame('A', $elbow90['profile_parameters']['dimension_symbol']);
        self::assertSame('CENTRE_TO_END', $elbow90['profile_parameters']['dimension']);
        self::assertSame(21.0, $elbow90['profile_parameters']['center_to_end_mm']);
        self::assertSame('THREADED_END_PLANE', $elbow90['profile_parameters']['port_datum']);
        self::assertVector([21.0, 21.0, 0], $elbow90['ports'][1]['point_local_mm']);

        self::assertSame('RESOLVED', $elbow45['status']);
        self::assertSame('C', $elbow45['profile_parameters']['dimension_symbol']);
        self::assertSame(17.0, $elbow45['profile_parameters']['center_to_end_mm']);
        self::assertSame(45.0, $elbow45['profile_parameters']['bend_angle_deg']);
        self::assertVector([
            17.0 * (1.0 + sqrt(0.5)),
            17.0 * sqrt(0.5),
            0.0,
        ], $elbow45['ports'][1]['point_local_mm']);
    }

    public function test_a_class_size_pair_absent_from_the_reference_is_unresolved_not_interpolated(): void
    {
        $profile = ForgedFittingElbowGeometryProfile::resolve(
            'SW', 2000, '90', '1/2', $this->dimensions(),
        );

        self::assertSame('UNRESOLVED', $profile['status']);
        self::assertSame('SIZE_CLASS_NOT_COVERED', $profile['reason']);
        self::assertSame([], $profile['ports']);
    }

    public function test_b16_11_does_not_accept_b16_9_lr_aliases_or_unsupported_end_configurations(): void
    {
        $dimensions = $this->dimensions();
        $lrAlias = ForgedFittingElbowGeometryProfile::resolve('SW', 3000, '90LR', '1/2', $dimensions);
        $unsupportedEnd = ForgedFittingElbowGeometryProfile::resolve('BW', 3000, '90', '1/2', $dimensions);

        self::assertSame('UNRESOLVED', $lrAlias['status']);
        self::assertSame('ANGLE_NOT_COVERED', $lrAlias['reason']);
        self::assertSame('UNRESOLVED', $unsupportedEnd['status']);
        self::assertSame('END_CONFIGURATION_NOT_COVERED', $unsupportedEnd['reason']);
    }

    public function test_roll_is_explicit_and_transforms_the_sourced_b16_11_port_points(): void
    {
        $profile = ForgedFittingElbowGeometryProfile::resolve('SC', 2000, '90', '1/8', $this->dimensions());
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:forged-elbow:local:v1',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [100, 200, 300],
            azimuthDeg: 0,
            inclinationDeg: 0,
            rollDeg: 90,
        );

        $resolved = ComponentPortGeometryTransformer::transform($profile, $pose);

        self::assertSame('RESOLVED', $resolved['status']);
        self::assertVector([100, 200, 300], $resolved['ports'][0]['point_model']);
        self::assertVector([121, 200, 321], $resolved['ports'][1]['point_model']);
        self::assertVector([0, 0, 1], $resolved['ports'][1]['outward_direction_model']);
    }

    private function dimensions(): ForgedFittingElbowDimensions
    {
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';

        return new ForgedFittingElbowDimensions(
            $referenceDirectory,
            new PipeReference($referenceDirectory),
        );
    }

    /** @param list<int|float> $expected @param list<int|float> $actual */
    private static function assertVector(array $expected, array $actual): void
    {
        self::assertCount(3, $actual);
        foreach ($expected as $index => $value) {
            self::assertEqualsWithDelta((float) $value, (float) $actual[$index], 1e-9);
        }
    }
}
