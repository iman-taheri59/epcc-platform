<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ButtWeldReducerGeometryProfile;
use App\Services\ComponentPortGeometryTransformer;
use App\Services\ManualComponentOrientation3D;
use App\Support\Reference\FittingDimensions;
use App\Support\Reference\PipeReference;
use PHPUnit\Framework\TestCase;

final class ButtWeldReducerGeometryProfileTest extends TestCase
{
    public function test_concentric_reducer_uses_the_tabulated_b16_9_h_length(): void
    {
        $profile = ButtWeldReducerGeometryProfile::resolve('6', '4', 'CONCENTRIC', $this->dimensions(), $this->pipe());

        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(ButtWeldReducerGeometryProfile::CONCENTRIC_PROFILE_ID, $profile['profile_id']);
        self::assertSame('1', $profile['profile_revision']);
        self::assertSame([1, 2], array_column($profile['ports'], 'seq'));
        self::assertSame(['LARGE', 'SMALL'], array_column($profile['ports'], 'role'));
        self::assertSame(140.0, $profile['profile_parameters']['end_to_end_h_mm']);
        self::assertSame('H', $profile['profile_parameters']['dimension_symbol']);
        self::assertSame('Table 6.1-11', $profile['profile_parameters']['source_table']);
        self::assertSame(2018, $profile['profile_parameters']['standard_edition_year']);
        self::assertVector([0, 0, 0], $profile['ports'][0]['point_local_mm']);
        self::assertVector([140, 0, 0], $profile['ports'][1]['point_local_mm']);
        self::assertVector([-1, 0, 0], $profile['ports'][0]['outward_direction_local']);
        self::assertVector([1, 0, 0], $profile['ports'][1]['outward_direction_local']);
        self::assertFalse($profile['roll_affects_port_geometry']);
    }

    public function test_eccentric_reducer_offsets_the_small_bore_by_half_the_od_difference(): void
    {
        $profile = ButtWeldReducerGeometryProfile::resolve('6', '4', 'ECCENTRIC', $this->dimensions(), $this->pipe());

        // 6in OD 168.3, 4in OD 114.3 => offset (168.3-114.3)/2 = 27.0
        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(ButtWeldReducerGeometryProfile::ECCENTRIC_PROFILE_ID, $profile['profile_id']);
        self::assertTrue($profile['roll_affects_port_geometry']);
        self::assertEqualsWithDelta(27.0, $profile['profile_parameters']['eccentric_offset_y_mm'], 1e-9);
        self::assertVector([140, 27, 0], $profile['ports'][1]['point_local_mm']);
        self::assertSame('LOCAL_XY', $profile['profile_parameters']['bend_plane_local']);
    }

    public function test_concentric_h_is_independent_of_small_size_for_same_large(): void
    {
        $a = ButtWeldReducerGeometryProfile::resolve('4', '3', 'CONCENTRIC', $this->dimensions(), $this->pipe());
        $b = ButtWeldReducerGeometryProfile::resolve('4', '2', 'CONCENTRIC', $this->dimensions(), $this->pipe());

        self::assertSame(102.0, $a['profile_parameters']['end_to_end_h_mm']);
        self::assertSame(102.0, $b['profile_parameters']['end_to_end_h_mm']);
        self::assertVector([102, 0, 0], $a['ports'][1]['point_local_mm']);
        self::assertVector([102, 0, 0], $b['ports'][1]['point_local_mm']);
    }

    public function test_missing_or_uncovered_variant_is_unresolved_not_guessed(): void
    {
        $cases = [
            [null, '4', 'CONCENTRIC', 'MISSING_LARGE_NPS'],
            ['6', '4', null, 'MISSING_QUALIFIER'],
            ['6', '4', 'UNKNOWN', 'QUALIFIER_NOT_COVERED'],
            ['50', '48', 'CONCENTRIC', 'PROJECT_SPEC_REQUIRED'],
            ['6', null, 'ECCENTRIC', 'ECCENTRIC_REQUIRES_SMALL_NPS'],
        ];

        foreach ($cases as [$large, $small, $qual, $reason]) {
            $profile = ButtWeldReducerGeometryProfile::resolve($large, $small, $qual, $this->dimensions(), $this->pipe());
            self::assertSame('UNRESOLVED', $profile['status']);
            self::assertSame($reason, $profile['reason']);
            self::assertSame([], $profile['ports']);
        }
    }

    public function test_manual_roll_does_not_move_concentric_small_port_but_moves_eccentric(): void
    {
        $concentric = ButtWeldReducerGeometryProfile::resolve('6', '4', 'CONCENTRIC', $this->dimensions(), $this->pipe());
        $eccentric = ButtWeldReducerGeometryProfile::resolve('6', '4', 'ECCENTRIC', $this->dimensions(), $this->pipe());

        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:reducer:local:v1',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [100, 200, 300],
            azimuthDeg: 0,
            inclinationDeg: 0,
            rollDeg: 90,
        );

        $resolvedC = ComponentPortGeometryTransformer::transform($concentric, $pose);
        $resolvedE = ComponentPortGeometryTransformer::transform($eccentric, $pose);

        // Concentric small port is on the axis, roll has no geometric effect on its position beyond the large port's axis.
        self::assertVector([240, 200, 300], $resolvedC['ports'][1]['point_model']);
        // Eccentric small port is offset along local +Y, after 90° roll it moves to +Z
        self::assertVector([240, 200, 327], $resolvedE['ports'][1]['point_model']);
        self::assertVector([0, 0, 1], $resolvedE['ports'][1]['outward_direction_model']); // direction is still along +X, unaffected by offset
    }

    public function test_every_concentric_reducer_row_resolves_h_without_small_size(): void
    {
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';
        $pack = json_decode(file_get_contents($referenceDirectory.'/fitting-dimensions.ref.json'), true, 512, JSON_THROW_ON_ERROR);
        $dimensions = $this->dimensions();
        $pipe = $this->pipe();

        $reducerRows = array_values(array_filter($pack['dimensions'], static fn (array $r): bool => ($r['fitting'] ?? null) === 'REDUCER' && ($r['qualifier'] ?? null) === 'CONCENTRIC'));
        self::assertCount(32, $reducerRows, 'Only the 32 large-size concentric reducer rows are present.');

        foreach ($reducerRows as $row) {
            $profile = ButtWeldReducerGeometryProfile::resolve($row['nps'], '1/2', 'CONCENTRIC', $dimensions, $pipe);
            self::assertSame('RESOLVED', $profile['status'], $row['nps']);
            self::assertSame((float) $row['mm'], $profile['profile_parameters']['end_to_end_h_mm'], $row['nps']);
            self::assertSame('H', $profile['profile_parameters']['dimension_symbol']);
        }
    }

    private function dimensions(): FittingDimensions
    {
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';

        return new FittingDimensions($referenceDirectory, new PipeReference($referenceDirectory));
    }

    private function pipe(): PipeReference
    {
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';

        return new PipeReference($referenceDirectory);
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
