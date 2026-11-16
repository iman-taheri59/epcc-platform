<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ButtWeldElbowGeometryProfile;
use App\Services\ComponentPortGeometryTransformer;
use App\Services\ManualComponentOrientation3D;
use App\Support\Reference\FittingDimensions;
use App\Support\Reference\PipeReference;
use PHPUnit\Framework\TestCase;

final class ButtWeldElbowGeometryProfileTest extends TestCase
{
    public function test_90lr_profile_uses_the_tabulated_b16_9_centre_to_end_value(): void
    {
        $profile = ButtWeldElbowGeometryProfile::resolve('90LR', '6', $this->dimensions());

        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(ButtWeldElbowGeometryProfile::PROFILE_ID, $profile['profile_id']);
        self::assertSame('1', $profile['profile_revision']);
        self::assertSame([1, 2], array_column($profile['ports'], 'seq'));
        self::assertSame(['RUN', 'RUN'], array_column($profile['ports'], 'role'));
        self::assertSame(229.0, $profile['profile_parameters']['center_to_end_mm']);
        self::assertSame('A', $profile['profile_parameters']['dimension_symbol']);
        self::assertSame('Table 6.1-1', $profile['profile_parameters']['source_table']);
        self::assertSame(2018, $profile['profile_parameters']['standard_edition_year']);
        self::assertVector([0, 0, 0], $profile['ports'][0]['point_local_mm']);
        self::assertVector([229, 229, 0], $profile['ports'][1]['point_local_mm']);
        self::assertVector([-1, 0, 0], $profile['ports'][0]['outward_direction_local']);
        self::assertVector([0, 1, 0], $profile['ports'][1]['outward_direction_local']);
    }

    public function test_45lr_profile_uses_b16_9_symbol_b_and_the_explicit_bend_angle(): void
    {
        $profile = ButtWeldElbowGeometryProfile::resolve('45LR', '6', $this->dimensions());
        $dimension = 95.0;
        $cos45 = sqrt(0.5);

        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(45.0, $profile['profile_parameters']['bend_angle_deg']);
        self::assertSame(95.0, $profile['profile_parameters']['center_to_end_mm']);
        self::assertSame('B', $profile['profile_parameters']['dimension_symbol']);
        self::assertVector([
            $dimension * (1 + $cos45),
            $dimension * $cos45,
            0,
        ], $profile['ports'][1]['point_local_mm']);
        self::assertVector([$cos45, $cos45, 0], $profile['ports'][1]['outward_direction_local']);
    }

    public function test_manual_roll_clocks_the_bend_plane_and_transforms_both_ports(): void
    {
        $profile = ButtWeldElbowGeometryProfile::resolve('90LR', '6', $this->dimensions());
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:elbow:local:v1',
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
        self::assertVector([329, 200, 529], $resolved['ports'][1]['point_model']);
        self::assertVector([0, 0, 1], $resolved['ports'][1]['outward_direction_model']);
        self::assertSame('Table 6.1-1', $resolved['profile_parameters']['source_table']);
    }

    public function test_missing_or_uncovered_variant_and_size_are_unresolved_not_guessed(): void
    {
        $dimensions = $this->dimensions();
        $cases = [
            [null, '6', 'MISSING_QUALIFIER'],
            ['45', '6', 'RADIUS_UNSPECIFIED'],
            ['90SR', '6', 'QUALIFIER_NOT_COVERED'],
            ['180LR', '6', 'QUALIFIER_NOT_COVERED'],
            ['90LR', null, 'MISSING_NPS'],
            ['90LR', '50', 'PROJECT_SPEC_REQUIRED'],
        ];

        foreach ($cases as [$qualifier, $nps, $reason]) {
            $profile = ButtWeldElbowGeometryProfile::resolve($qualifier, $nps, $dimensions);
            self::assertSame('UNRESOLVED', $profile['status']);
            self::assertSame($reason, $profile['reason']);
            self::assertSame([], $profile['ports']);
        }
    }

    private function dimensions(): FittingDimensions
    {
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';

        return new FittingDimensions($referenceDirectory, new PipeReference($referenceDirectory));
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
