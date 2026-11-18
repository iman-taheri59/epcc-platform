<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Component;
use App\Models\Port;
use App\Services\ComponentPortGeometryTransformer;
use App\Services\ComponentPoseService;
use App\Services\ManualComponentOrientation3D;
use App\Services\SinglePortCapGeometryProfile;
use App\Support\Reference\FittingDimensions;
use App\Support\Reference\PipeReference;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class SinglePortCapGeometryProfileTest extends TestCase
{
    public function test_open_end_port_resolves_without_claiming_cap_body_extent_when_thickness_is_absent(): void
    {
        $profile = SinglePortCapGeometryProfile::resolve('6', null, null, $this->fittingDimensions());
        $legacyProfile = $profile;
        $legacyProfile['profile_id'] = 'SINGLE_PORT_BW_CAP_GEOMETRY_V2';
        $legacyProfile['profile_revision'] = '2';
        $legacyProfile['profile_parameters']['geometry_contract'] = 'CAP_OPEN_END_PORT_CENTRE_AND_OPTIONAL_CLOSED_END_V2';
        unset($legacyProfile['profile_parameters']['body_extent_scope']);
        $profileHash = new ReflectionMethod(ComponentPoseService::class, 'profileHash');
        self::assertNotSame(
            $profileHash->invoke(null, $legacyProfile),
            $profileHash->invoke(null, $profile),
            'The contract clarification changes the fingerprint, so older V2 geometry cannot silently remain current.',
        );
        $pose = ManualComponentOrientation3D::framedTransform(
            localFrameId: 'component:cap-1:local:v3',
            modelFrameId: 'project:model:v1',
            coordinateUnit: 'MM',
            anchorModel: [1200.0, 3400.0, 250.0],
            azimuthDeg: 30.0,
            inclinationDeg: 20.0,
            rollDeg: 0.0,
        );

        $resolved = ComponentPortGeometryTransformer::transform($profile, $pose);

        self::assertSame('RESOLVED', $resolved['status']);
        self::assertSame('SINGLE_PORT_BW_CAP_GEOMETRY_V3', $resolved['profile_id']);
        self::assertSame('3', $resolved['profile_revision']);
        self::assertSame(
            'AXIAL_REFERENCE_LENGTH_AND_CLOSED_END_POINT_ONLY_NO_CONTOUR',
            $resolved['profile_parameters']['body_extent_scope'],
        );
        self::assertSame([1], array_column($resolved['ports'], 'seq'));
        self::assertSame(['RUN'], array_column($resolved['ports'], 'role'));
        self::assertEqualsWithDelta([1200.0, 3400.0, 250.0], $resolved['ports'][0]['point_model'], 1e-10);
        self::assertEqualsWithDelta([
            -cos(deg2rad(20.0)) * cos(deg2rad(30.0)),
            -cos(deg2rad(20.0)) * sin(deg2rad(30.0)),
            -sin(deg2rad(20.0)),
        ], $resolved['ports'][0]['outward_direction_model'], 1e-10);
        self::assertSame('UNRESOLVED', $resolved['profile_parameters']['body_extent_status']);
        self::assertSame('CAP_THICKNESS_REQUIRED', $resolved['profile_parameters']['body_extent_reason']);
        self::assertArrayNotHasKey('reference_points', $resolved);
    }

    public function test_b16_9_cap_length_uses_exact_e_or_e1_row_and_transforms_closed_end_point(): void
    {
        $profileHashes = [];
        foreach ([
            ['thickness' => 10.92, 'symbol' => 'E', 'length' => 89.0],
            ['thickness' => 10.920001, 'symbol' => 'E1', 'length' => 102.0],
        ] as $case) {
            $profile = SinglePortCapGeometryProfile::resolve(
                '6',
                $case['thickness'],
                'PMS-DOC-17 §4 / approved thickness note',
                $this->fittingDimensions(),
            );
            $profileHashes[] = (new ReflectionMethod(ComponentPoseService::class, 'profileHash'))
                ->invoke(null, $profile);
            $pose = ManualComponentOrientation3D::framedTransform(
                localFrameId: 'component:cap-1:local:v3',
                modelFrameId: 'project:model:v1',
                coordinateUnit: 'MM',
                anchorModel: [100.0, 200.0, 300.0],
                azimuthDeg: 90.0,
                inclinationDeg: 0.0,
                rollDeg: 123.0,
            );
            $resolved = ComponentPortGeometryTransformer::transform($profile, $pose);

            self::assertSame('RESOLVED', $resolved['profile_parameters']['body_extent_status']);
            self::assertSame(
                'AXIAL_REFERENCE_LENGTH_AND_CLOSED_END_POINT_ONLY_NO_CONTOUR',
                $resolved['profile_parameters']['body_extent_scope'],
            );
            self::assertSame($case['symbol'], $resolved['profile_parameters']['body_dimension_symbol']);
            self::assertEqualsWithDelta($case['length'], $resolved['profile_parameters']['body_length_mm'], 1e-10);
            self::assertSame('PMS-DOC-17 §4 / approved thickness note', $resolved['profile_parameters']['body_thickness_source']);
            self::assertSame('fitting-dimensions', $resolved['profile_parameters']['body_standard_reference']['refId']);
            self::assertEqualsWithDelta(
                [100.0, 200.0 + $case['length'], 300.0],
                $resolved['reference_points']['closed_end']['point_model'],
                1e-10,
            );
        }

        $sourceVariantProfile = SinglePortCapGeometryProfile::resolve(
            '6',
            10.92,
            'different thickness source reference',
            $this->fittingDimensions(),
        );
        $sourceVariantHash = (new ReflectionMethod(ComponentPoseService::class, 'profileHash'))
            ->invoke(null, $sourceVariantProfile);

        self::assertNotSame($profileHashes[0], $profileHashes[1], 'Selecting E1 rather than E changes the pose geometry fingerprint.');
        self::assertNotSame($profileHashes[0], $sourceVariantHash, 'Changing recorded thickness provenance changes the pose geometry fingerprint.');
    }

    public function test_b16_9_cap_thickness_boundary_selects_e_or_e1_for_every_covered_size_without_rounding(): void
    {
        $dimensions = $this->fittingDimensions();
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';
        $pack = json_decode(file_get_contents($referenceDirectory.'/fitting-dimensions.ref.json'), true, 512, JSON_THROW_ON_ERROR);
        $capRows = array_values(array_filter(
            $pack['dimensions'],
            static fn (array $row): bool => ($row['fitting'] ?? null) === 'CAP'
                && ($row['requiresManufacturerAgreement'] ?? true) === false,
        ));

        self::assertCount(21, $capRows, 'Only the twenty-one standard-size rows with explicit thickness limits are calculable.');
        self::assertSame(
            ['1/2', '3/4', '1', '1 1/4', '1 1/2', '2', '2 1/2', '3', '3 1/2', '4', '5', '6', '8', '10', '12', '14', '16', '18', '20', '22', '24'],
            array_column($capRows, 'nps'),
        );

        foreach ($capRows as $row) {
            $atBoundary = SinglePortCapGeometryProfile::resolve(
                $row['nps'],
                $row['limitingWallThicknessMm'],
                'explicit specified wall thickness source',
                $dimensions,
            );
            $aboveBoundary = SinglePortCapGeometryProfile::resolve(
                $row['nps'],
                $row['limitingWallThicknessMm'] + 0.000001,
                'explicit specified wall thickness source',
                $dimensions,
            );

            self::assertSame('RESOLVED', $atBoundary['profile_parameters']['body_extent_status'], $row['nps']);
            self::assertSame('E', $atBoundary['profile_parameters']['body_dimension_symbol'], $row['nps']);
            self::assertSame((float) $row['mm'], $atBoundary['profile_parameters']['body_length_mm'], $row['nps']);
            self::assertSame('Table 6.1-10', $atBoundary['profile_parameters']['body_source_table'], $row['nps']);
            self::assertSame('RESOLVED', $aboveBoundary['profile_parameters']['body_extent_status'], $row['nps']);
            self::assertSame('E1', $aboveBoundary['profile_parameters']['body_dimension_symbol'], $row['nps']);
            self::assertSame((float) $row['e1Mm'], $aboveBoundary['profile_parameters']['body_length_mm'], $row['nps']);
        }
    }

    public function test_cap_extent_requires_source_and_keeps_every_agreement_size_unresolved(): void
    {
        $dimensions = $this->fittingDimensions();
        $missingSource = SinglePortCapGeometryProfile::resolve('6', 10.92, null, $dimensions);
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';
        $pack = json_decode(file_get_contents($referenceDirectory.'/fitting-dimensions.ref.json'), true, 512, JSON_THROW_ON_ERROR);
        $agreementRows = array_values(array_filter(
            $pack['dimensions'],
            static fn (array $row): bool => ($row['fitting'] ?? null) === 'CAP'
                && ($row['requiresManufacturerAgreement'] ?? false) === true,
        ));

        self::assertCount(12, $agreementRows);
        self::assertSame(
            ['26', '28', '30', '32', '34', '36', '38', '40', '42', '44', '46', '48'],
            array_column($agreementRows, 'nps'),
        );
        self::assertSame('UNRESOLVED', $missingSource['profile_parameters']['body_extent_status']);
        self::assertSame('CAP_THICKNESS_SOURCE_REQUIRED', $missingSource['profile_parameters']['body_extent_reason']);
        self::assertArrayNotHasKey('reference_points', $missingSource);

        foreach ($agreementRows as $row) {
            $largeCap = SinglePortCapGeometryProfile::resolve(
                $row['nps'],
                20.0,
                'manufacturer document recorded but not verified',
                $dimensions,
            );

            self::assertSame('UNRESOLVED', $largeCap['profile_parameters']['body_extent_status'], $row['nps']);
            self::assertSame('CAP_MANUFACTURER_AGREEMENT_REQUIRED', $largeCap['profile_parameters']['body_extent_reason'], $row['nps']);
            self::assertArrayNotHasKey('reference_points', $largeCap, $row['nps']);
        }
    }

    public function test_pose_service_admits_only_the_declared_single_bw_cap_port_shape(): void
    {
        $service = (new ReflectionClass(ComponentPoseService::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(ComponentPoseService::class, 'fittingDimensions'))
            ->setValue($service, $this->fittingDimensions());

        $cap = new Component;
        $cap->setRawAttributes(['template_code' => 'CAP', 'nominal_size' => '6', 'schedule' => '40'], true);
        $cap->setRelation('ports', new Collection([$this->capPort('BW')]));

        self::assertTrue($this->invoke($service, 'supportsGeometry', $cap));
        self::assertTrue($this->invoke($service, 'profilePortShapeMatches', $cap));
        $profile = $this->invoke($service, 'resolveGeometryProfile', $cap);
        self::assertSame(SinglePortCapGeometryProfile::PROFILE_ID, $profile['profile_id']);
        self::assertSame('CAP_THICKNESS_REQUIRED', $profile['profile_parameters']['body_extent_reason']);

        $withExplicitThickness = $this->invoke($service, 'resolveGeometryProfile', $cap, [
            'cap_extent_inputs' => [
                'specified_wall_thickness_mm' => 10.92,
                'thickness_source' => 'explicit source reference',
            ],
        ]);
        self::assertSame('RESOLVED', $withExplicitThickness['profile_parameters']['body_extent_status']);
        self::assertSame(89.0, $withExplicitThickness['profile_parameters']['body_length_mm']);

        $nonBwCap = new Component;
        $nonBwCap->setRawAttributes(['template_code' => 'CAP'], true);
        $nonBwCap->setRelation('ports', new Collection([$this->capPort('SW')]));
        self::assertFalse($this->invoke($service, 'profilePortShapeMatches', $nonBwCap));
    }

    private function fittingDimensions(): FittingDimensions
    {
        $referenceDirectory = dirname(__DIR__, 3).'/standards-packs/reference';

        return new FittingDimensions($referenceDirectory, new PipeReference($referenceDirectory));
    }

    private function capPort(string $endType): Port
    {
        $port = new Port;
        $port->setRawAttributes([
            'seq' => 1,
            'role' => 'RUN',
            'end_type' => $endType,
        ], true);

        return $port;
    }

    private function invoke(ComponentPoseService $service, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod(ComponentPoseService::class, $method))->invoke($service, ...$arguments);
    }
}
