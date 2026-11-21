<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ButtWeldTeeGeometryProfile;
use App\Services\ComponentPortGeometryTransformer;
use App\Services\ManualComponentOrientation3D;
use App\Support\Reference\FittingDimensions;
use App\Support\Reference\PipeReference;
use PHPUnit\Framework\TestCase;

final class ButtWeldTeeGeometryProfileTest extends TestCase
{
    public function test_straight_tee_uses_the_tabulated_b16_9_c_and_m_values(): void
    {
        $profile = ButtWeldTeeGeometryProfile::resolve('6', $this->dimensions());

        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(ButtWeldTeeGeometryProfile::PROFILE_ID, $profile['profile_id']);
        self::assertSame('1', $profile['profile_revision']);
        self::assertSame([1, 2, 3], array_column($profile['ports'], 'seq'));
        self::assertSame(['RUN', 'RUN', 'BRANCH'], array_column($profile['ports'], 'role'));
        self::assertSame(143.0, $profile['profile_parameters']['run_centre_to_end_mm']);
        self::assertSame(143.0, $profile['profile_parameters']['branch_centre_to_end_mm']);
        self::assertSame('C', $profile['profile_parameters']['dimension_symbol_run']);
        self::assertSame('M', $profile['profile_parameters']['dimension_symbol_branch']);
        self::assertSame('Table 6.1-7', $profile['profile_parameters']['source_table']);
        self::assertSame(2018, $profile['profile_parameters']['standard_edition_year']);
        self::assertVector([0, 0, 0], $profile['ports'][0]['point_local_mm']);
        self::assertVector([286, 0, 0], $profile['ports'][1]['point_local_mm']);
        self::assertVector([143, 143, 0], $profile['ports'][2]['point_local_mm']);
        self::assertVector([-1, 0, 0], $profile['ports'][0]['outward_direction_local']);
        self::assertVector([1, 0, 0], $profile['ports'][1]['outward_direction_local']);
        self::assertVector([0, 1, 0], $profile['ports'][2]['outward_direction_local']);
    }

    public function test_large_tee_where_c_differs_from_m_is_not_averaged(): void
    {
        // NPS 42 has C=762, M=711 per Table 6.1-7; the profile must not average them.
        $profile = ButtWeldTeeGeometryProfile::resolve('42', $this->dimensions());

        self::assertSame('RESOLVED', $profile['status']);
        self::assertSame(762.0, $profile['profile_parameters']['run_centre_to_end_mm']);
        self::assertSame(711.0, $profile['profile_parameters']['branch_centre_to_end_mm']);
        self::assertVector([1524, 0, 0], $profile['ports'][1]['point_local_mm']);
        self::assertVector([762, 711, 0], $profile['ports'][2]['point_local_mm']);
    }

    public function test_manual_roll_clocks_the_branch_around_the_run_axis(): void
    {
        $profile = ButtWeldTeeGeometryProfile::resolve('6', $this->dimensions());
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:tee:local:v1',
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
        // Run port 2 is along +X, roll does not affect it (coaxial)
        self::assertVector([386, 200, 300], $resolved['ports'][1]['point_model']);
        // Branch port was +Y, after 90° roll it should be +Z
        self::assertVector([243, 200, 443], $resolved['ports'][2]['point_model']);
        self::assertVector([0, 0, 1], $resolved['ports'][2]['outward_direction_model']);
    }

    public function test_missing_or_uncovered_size_is_unresolved_not_guessed(): void
    {
        $dimensions = $this->dimensions();
        $cases = [
            [null, 'MISSING_NPS'],
            ['50', 'PROJECT_SPEC_REQUIRED'],
        ];

        foreach ($cases as [$nps, $reason]) {
            $profile = ButtWeldTeeGeometryProfile::resolve($nps, $dimensions);
            self::assertSame('UNRESOLVED', $profile['status']);
            self::assertSame($reason, $profile['reason']);
            self::assertSame([], $profile['ports']);
        }
    }

    public function test_every_straight_tee_row_resolves_both_c_and_m_without_inference(): void
    {
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';
        $pack = json_decode(file_get_contents($referenceDirectory.'/fitting-dimensions.ref.json'), true, 512, JSON_THROW_ON_ERROR);
        $dimensions = $this->dimensions();

        $teeRows = array_values(array_filter($pack['dimensions'], static fn (array $r): bool => ($r['fitting'] ?? null) === 'TEE'));
        self::assertCount(33, $teeRows, 'Only the 33 straight-tee NPS rows are present.');

        foreach ($teeRows as $row) {
            $profile = ButtWeldTeeGeometryProfile::resolve($row['nps'], $dimensions);
            self::assertSame('RESOLVED', $profile['status'], $row['nps']);
            self::assertSame((float) $row['mm'], $profile['profile_parameters']['run_centre_to_end_mm'], $row['nps']);
            self::assertSame((float) $row['branchCentreToEndMm'], $profile['profile_parameters']['branch_centre_to_end_mm'], $row['nps']);
            self::assertSame('C', $profile['profile_parameters']['dimension_symbol_run']);
            self::assertSame('M', $profile['profile_parameters']['dimension_symbol_branch']);
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
