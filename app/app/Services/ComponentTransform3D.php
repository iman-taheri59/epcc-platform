<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Rigid-transform primitive for component-local geometry.
 *
 * The caller supplies a proper orthonormal matrix R that already maps local
 * column vectors into a common model frame, plus the model-frame translation
 * t. The matrix is a precondition of this low-level operation, not a
 * project-wide frame decision. This class deliberately does not construct R
 * from Euler angles, quaternions, slope fields, or a project-wide axis convention.
 *
 * Points use p_model = R * p_local + t. Directions use d_model = R * d_local;
 * translation never affects an axis or port direction. All coordinates must
 * use one consistent unit, chosen by the caller.
 */
final class ComponentTransform3D
{
    private const ROTATION_TOLERANCE = 1e-8;

    /**
     * @param  list<int|float>  $localPoint  Local-frame [x, y, z]
     * @param  list<list<int|float>>  $rotationLocalToModel  Row-major local-to-model R
     * @param  list<int|float>  $translationModel  Model-frame [x, y, z]
     * @return array{0:float,1:float,2:float}
     */
    public static function transformPoint(array $localPoint, array $rotationLocalToModel, array $translationModel): array
    {
        $point = self::vector3($localPoint, 'localPoint');
        $translation = self::vector3($translationModel, 'translationModel');
        $rotation = self::properRotation($rotationLocalToModel);
        $rotated = self::multiply($rotation, $point);

        return [
            self::finiteResult($rotated[0] + $translation[0]),
            self::finiteResult($rotated[1] + $translation[1]),
            self::finiteResult($rotated[2] + $translation[2]),
        ];
    }

    /**
     * Transform a local vector such as a port axis, without applying translation.
     * Its magnitude is preserved; callers that need an axis unit vector should
     * normalize explicitly rather than changing the meaning of a direction.
     *
     * @param  list<int|float>  $localDirection
     * @param  list<list<int|float>>  $rotationLocalToModel
     * @return array{0:float,1:float,2:float}
     */
    public static function transformDirection(array $localDirection, array $rotationLocalToModel): array
    {
        $direction = self::vector3($localDirection, 'localDirection');
        $rotation = self::properRotation($rotationLocalToModel);
        $transformed = self::multiply($rotation, $direction);

        return [
            self::finiteResult($transformed[0]),
            self::finiteResult($transformed[1]),
            self::finiteResult($transformed[2]),
        ];
    }

    /**
     * @param  list<list<int|float>>  $matrix
     * @return array{0:array{0:float,1:float,2:float},1:array{0:float,1:float,2:float},2:array{0:float,1:float,2:float}}
     */
    private static function properRotation(array $matrix): array
    {
        if (! array_is_list($matrix) || count($matrix) !== 3) {
            throw new InvalidArgumentException('rotationLocalToModel must be a 3x3 row-major matrix.');
        }

        $rotation = [];
        foreach ($matrix as $index => $row) {
            $rotation[] = self::vector3($row, "rotationLocalToModel[$index]");
        }

        // R * R^T = I: reject scale, shear, and numerically invalid matrices.
        for ($row = 0; $row < 3; $row++) {
            for ($other = 0; $other < 3; $other++) {
                $dot = 0.0;
                for ($column = 0; $column < 3; $column++) {
                    $dot += $rotation[$row][$column] * $rotation[$other][$column];
                }
                $expected = $row === $other ? 1.0 : 0.0;
                if (abs($dot - $expected) > self::ROTATION_TOLERANCE) {
                    throw new InvalidArgumentException('rotationLocalToModel must be orthonormal; scale and shear are not supported.');
                }
            }
        }

        $determinant =
            $rotation[0][0] * ($rotation[1][1] * $rotation[2][2] - $rotation[1][2] * $rotation[2][1])
            - $rotation[0][1] * ($rotation[1][0] * $rotation[2][2] - $rotation[1][2] * $rotation[2][0])
            + $rotation[0][2] * ($rotation[1][0] * $rotation[2][1] - $rotation[1][1] * $rotation[2][0]);

        if (! is_finite($determinant) || abs($determinant - 1.0) > self::ROTATION_TOLERANCE) {
            throw new InvalidArgumentException('rotationLocalToModel must be a proper right-handed rotation; reflections are not supported.');
        }

        return $rotation;
    }

    /** @param list<int|float> $vector @return array{0:float,1:float,2:float} */
    private static function vector3(array $vector, string $name): array
    {
        if (! array_is_list($vector) || count($vector) !== 3) {
            throw new InvalidArgumentException("$name must contain exactly three finite numeric values.");
        }

        $values = [];
        foreach ($vector as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException("$name must contain exactly three finite numeric values.");
            }
            $values[] = (float) $value;
        }

        return $values;
    }

    /** @param array{0:array{0:float,1:float,2:float},1:array{0:float,1:float,2:float},2:array{0:float,1:float,2:float}} $matrix @param array{0:float,1:float,2:float} $vector @return array{0:float,1:float,2:float} */
    private static function multiply(array $matrix, array $vector): array
    {
        return [
            $matrix[0][0] * $vector[0] + $matrix[0][1] * $vector[1] + $matrix[0][2] * $vector[2],
            $matrix[1][0] * $vector[0] + $matrix[1][1] * $vector[1] + $matrix[1][2] * $vector[2],
            $matrix[2][0] * $vector[0] + $matrix[2][1] * $vector[1] + $matrix[2][2] * $vector[2],
        ];
    }

    private static function finiteResult(float $value): float
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('The transformed coordinate is not finite.');
        }

        return $value;
    }
}
