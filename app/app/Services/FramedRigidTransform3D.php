<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * A rigid transform guarded by opaque source/target frame and unit identifiers.
 *
 * This is an in-memory calculation value, not a project frame registry or
 * persisted pose. The IDs and unit label are compared exactly; this class does
 * not define their axes, origin, handedness, lifecycle, or conversion rules.
 * Coordinates in both frames must already use the same declared unit.
 *
 * `rotationSourceToTarget` is the row-major local-to-target R accepted by
 * ComponentTransform3D. Points map as p_target = R*p_source + t_target;
 * directions map as d_target = R*d_source. Composition follows the `then`
 * order: A-to-B followed by B-to-C yields A-to-C.
 */
final readonly class FramedRigidTransform3D
{
    /**
     * @param  list<list<int|float>>  $rotationSourceToTarget
     * @param  list<int|float>  $translationTarget
     */
    public function __construct(
        public string $sourceFrameId,
        public string $targetFrameId,
        public string $coordinateUnit,
        public array $rotationSourceToTarget,
        public array $translationTarget,
    ) {
        if (trim($sourceFrameId) === '' || trim($targetFrameId) === '') {
            throw new InvalidArgumentException('Source and target frame identifiers must not be empty.');
        }

        if (trim($coordinateUnit) === '') {
            throw new InvalidArgumentException('A coordinate unit identifier is required; units are never inferred.');
        }

        // Reuse the low-level validation so this framed value only accepts a
        // finite proper rigid rotation and finite three-coordinate translation.
        ComponentTransform3D::transformPoint(
            [0.0, 0.0, 0.0],
            $rotationSourceToTarget,
            $translationTarget,
        );
    }

    /**
     * Transform a point after verifying its exact source-frame and unit tags.
     *
     * @param  list<int|float>  $point
     * @return array{coordinates:array{0:float,1:float,2:float},frame_id:string,coordinate_unit:string}
     */
    public function transformPoint(array $point, string $pointFrameId, string $pointUnit): array
    {
        $this->assertSourceFrame($pointFrameId);
        $this->assertUnit($pointUnit);

        return [
            'coordinates' => ComponentTransform3D::transformPoint(
                $point,
                $this->rotationSourceToTarget,
                $this->translationTarget,
            ),
            'frame_id' => $this->targetFrameId,
            'coordinate_unit' => $this->coordinateUnit,
        ];
    }

    /**
     * Transform a vector such as a port axis; translation does not apply.
     * Direction vectors carry a frame tag but no length unit.
     *
     * @param  list<int|float>  $direction
     * @return array{coordinates:array{0:float,1:float,2:float},frame_id:string}
     */
    public function transformDirection(array $direction, string $directionFrameId): array
    {
        $this->assertSourceFrame($directionFrameId);

        return [
            'coordinates' => ComponentTransform3D::transformDirection(
                $direction,
                $this->rotationSourceToTarget,
            ),
            'frame_id' => $this->targetFrameId,
        ];
    }

    /**
     * Compose this transform A→B followed by $next B→C, yielding A→C.
     *
     * @throws InvalidArgumentException when the intermediate frame or unit differs
     */
    public function then(self $next): self
    {
        if ($this->targetFrameId !== $next->sourceFrameId) {
            throw new InvalidArgumentException('Transform composition requires the first target frame to match the next source frame.');
        }

        if ($this->coordinateUnit !== $next->coordinateUnit) {
            throw new InvalidArgumentException('Transform composition requires identical coordinate units; convert units explicitly first.');
        }

        $rotation = self::multiplyMatrices(
            $next->rotationSourceToTarget,
            $this->rotationSourceToTarget,
        );
        $rotatedTranslation = ComponentTransform3D::transformDirection(
            $this->translationTarget,
            $next->rotationSourceToTarget,
        );
        $translation = [
            $rotatedTranslation[0] + $next->translationTarget[0],
            $rotatedTranslation[1] + $next->translationTarget[1],
            $rotatedTranslation[2] + $next->translationTarget[2],
        ];

        return new self(
            sourceFrameId: $this->sourceFrameId,
            targetFrameId: $next->targetFrameId,
            coordinateUnit: $this->coordinateUnit,
            rotationSourceToTarget: $rotation,
            translationTarget: $translation,
        );
    }

    /** Return the reverse transform B→A. */
    public function inverse(): self
    {
        $inverseRotation = self::transpose($this->rotationSourceToTarget);
        $rotatedTranslation = ComponentTransform3D::transformDirection(
            $this->translationTarget,
            $inverseRotation,
        );

        return new self(
            sourceFrameId: $this->targetFrameId,
            targetFrameId: $this->sourceFrameId,
            coordinateUnit: $this->coordinateUnit,
            rotationSourceToTarget: $inverseRotation,
            translationTarget: [
                -$rotatedTranslation[0],
                -$rotatedTranslation[1],
                -$rotatedTranslation[2],
            ],
        );
    }

    private function assertSourceFrame(string $frameId): void
    {
        if ($frameId !== $this->sourceFrameId) {
            throw new InvalidArgumentException('The coordinate frame does not match the transform source frame.');
        }
    }

    private function assertUnit(string $unit): void
    {
        if ($unit !== $this->coordinateUnit) {
            throw new InvalidArgumentException('The coordinate unit does not match the transform unit; convert units explicitly first.');
        }
    }

    /**
     * @param  list<list<int|float>>  $left
     * @param  list<list<int|float>>  $right
     * @return list<list<float>>
     */
    private static function multiplyMatrices(array $left, array $right): array
    {
        $result = [];
        for ($row = 0; $row < 3; $row++) {
            $result[$row] = [];
            for ($column = 0; $column < 3; $column++) {
                $value = 0.0;
                for ($inner = 0; $inner < 3; $inner++) {
                    $value += (float) $left[$row][$inner] * (float) $right[$inner][$column];
                }
                $result[$row][$column] = $value;
            }
        }

        return $result;
    }

    /** @param list<list<int|float>> $matrix @return list<list<int|float>> */
    private static function transpose(array $matrix): array
    {
        return [
            [$matrix[0][0], $matrix[1][0], $matrix[2][0]],
            [$matrix[0][1], $matrix[1][1], $matrix[2][1]],
            [$matrix[0][2], $matrix[1][2], $matrix[2][2]],
        ];
    }
}
