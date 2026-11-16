<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FramedRigidTransform3D;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FramedRigidTransform3DTest extends TestCase
{
    public function test_transform_requires_matching_source_frame_and_unit_and_returns_target_tags(): void
    {
        $transform = $this->localToModel();

        $result = $transform->transformPoint([1, 0, 0], 'component-local', 'MM');

        $this->assertSame('model-main', $result['frame_id']);
        $this->assertSame('MM', $result['coordinate_unit']);
        $this->assertEqualsWithDelta(10.0, $result['coordinates'][0], 1e-12);
        $this->assertEqualsWithDelta(21.0, $result['coordinates'][1], 1e-12);
    }

    public function test_point_and_direction_are_frame_tagged_but_only_point_gets_translation(): void
    {
        $transform = $this->localToModel();

        $point = $transform->transformPoint([1, 0, 0], 'component-local', 'MM');
        $direction = $transform->transformDirection([1, 0, 0], 'component-local');

        $this->assertEqualsWithDelta($point['coordinates'][0], 10.0, 1e-12);
        $this->assertEqualsWithDelta($point['coordinates'][1], 21.0, 1e-12);
        $this->assertSame('model-main', $direction['frame_id']);
        $this->assertEqualsWithDelta(0.0, $direction['coordinates'][0], 1e-12);
        $this->assertEqualsWithDelta(1.0, $direction['coordinates'][1], 1e-12);
    }

    public function test_transform_composition_checks_intermediate_frame_and_computes_a_to_c(): void
    {
        $localToStation = new FramedRigidTransform3D(
            sourceFrameId: 'component-local',
            targetFrameId: 'line-station',
            coordinateUnit: 'MM',
            rotationSourceToTarget: [[0, -1, 0], [1, 0, 0], [0, 0, 1]],
            translationTarget: [1, 0, 0],
        );
        $stationToModel = new FramedRigidTransform3D(
            sourceFrameId: 'line-station',
            targetFrameId: 'model-main',
            coordinateUnit: 'MM',
            rotationSourceToTarget: [[0, 1, 0], [-1, 0, 0], [0, 0, 1]],
            translationTarget: [0, 2, 0],
        );

        $combined = $localToStation->then($stationToModel);
        $result = $combined->transformPoint([1, 0, 0], 'component-local', 'MM');

        $this->assertSame('component-local', $combined->sourceFrameId);
        $this->assertSame('model-main', $combined->targetFrameId);
        $this->assertEqualsWithDelta(1.0, $result['coordinates'][0], 1e-12);
        $this->assertEqualsWithDelta(1.0, $result['coordinates'][1], 1e-12);
    }

    public function test_inverse_transform_returns_a_point_to_its_source_frame(): void
    {
        $forward = $this->localToModel();
        $modelPoint = $forward->transformPoint([2, 3, 4], 'component-local', 'MM');

        $localPoint = $forward->inverse()->transformPoint(
            $modelPoint['coordinates'],
            'model-main',
            'MM',
        );

        $this->assertSame('component-local', $localPoint['frame_id']);
        $this->assertEqualsWithDelta(2.0, $localPoint['coordinates'][0], 1e-12);
        $this->assertEqualsWithDelta(3.0, $localPoint['coordinates'][1], 1e-12);
        $this->assertEqualsWithDelta(4.0, $localPoint['coordinates'][2], 1e-12);
    }

    public function test_point_from_a_different_frame_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->localToModel()->transformPoint([1, 0, 0], 'another-frame', 'MM');
    }

    public function test_point_in_a_different_unit_is_rejected_without_implicit_conversion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->localToModel()->transformPoint([1, 0, 0], 'component-local', 'INCH');
    }

    public function test_composition_rejects_frame_or_unit_mismatches(): void
    {
        $first = new FramedRigidTransform3D(
            'local-A', 'frame-B', 'MM',
            [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
            [0, 0, 0],
        );
        $wrongFrame = new FramedRigidTransform3D(
            'frame-C', 'model', 'MM',
            [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
            [0, 0, 0],
        );
        $wrongUnit = new FramedRigidTransform3D(
            'frame-B', 'model', 'INCH',
            [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
            [0, 0, 0],
        );

        try {
            $first->then($wrongFrame);
            $this->fail('A mismatched intermediate frame was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('frame', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $first->then($wrongUnit);
    }

    public function test_empty_source_frame_identifier_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FramedRigidTransform3D(
            sourceFrameId: ' ',
            targetFrameId: 'model-main',
            coordinateUnit: 'MM',
            rotationSourceToTarget: [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
            translationTarget: [0, 0, 0],
        );
    }

    public function test_empty_coordinate_unit_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FramedRigidTransform3D(
            sourceFrameId: 'component-local',
            targetFrameId: 'model-main',
            coordinateUnit: '',
            rotationSourceToTarget: [[1, 0, 0], [0, 1, 0], [0, 0, 1]],
            translationTarget: [0, 0, 0],
        );
    }

    private function localToModel(): FramedRigidTransform3D
    {
        return new FramedRigidTransform3D(
            sourceFrameId: 'component-local',
            targetFrameId: 'model-main',
            coordinateUnit: 'MM',
            rotationSourceToTarget: [[0, -1, 0], [1, 0, 0], [0, 0, 1]],
            translationTarget: [10, 20, 30],
        );
    }
}
