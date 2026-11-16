<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Build a right-handed active rotation matrix from an explicit axis and angle.
 *
 * The axis is a non-zero vector in one caller-defined basis; the angle is in
 * degrees. The returned row-major matrix acts on column vectors, matching
 * ComponentTransform3D. This names a mathematical operation only: it does not
 * choose a project/global axis, interpret drawing input, or define a stored
 * component-pose convention.
 */
final class AxisAngleRotation3D
{
    public const METHOD = 'RODRIGUES_RIGHT_HAND_ACTIVE_COLUMN_VECTOR_V1';

    /**
     * @param  list<int|float>  $axis  Axis vector in a caller-defined basis
     * @return array{
     *   method:string,
     *   rotation_matrix:array{
     *     0:array{0:float,1:float,2:float},
     *     1:array{0:float,1:float,2:float},
     *     2:array{0:float,1:float,2:float}
     *   }
     * }
     */
    public static function matrixFromAxisAngleDeg(array $axis, float $angleDeg): array
    {
        if (! array_is_list($axis) || count($axis) !== 3) {
            throw new InvalidArgumentException('axis must contain exactly three finite numeric values.');
        }

        $components = [];
        foreach ($axis as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('axis must contain exactly three finite numeric values.');
            }
            $components[] = (float) $value;
        }

        $scale = max(abs($components[0]), abs($components[1]), abs($components[2]));
        if ($scale === 0.0) {
            throw new InvalidArgumentException('axis must be non-zero.');
        }

        if (! is_finite($angleDeg)) {
            throw new InvalidArgumentException('angleDeg must be finite.');
        }

        // Scaling avoids overflow/underflow when normalizing a finite axis.
        $scaled = [
            $components[0] / $scale,
            $components[1] / $scale,
            $components[2] / $scale,
        ];
        $length = sqrt(
            $scaled[0] * $scaled[0]
            + $scaled[1] * $scaled[1]
            + $scaled[2] * $scaled[2],
        );
        $x = $scaled[0] / $length;
        $y = $scaled[1] / $length;
        $z = $scaled[2] / $length;

        // Rotation matrices are 360-degree periodic. Reducing only the trig
        // argument keeps large finite input angles numerically manageable; it
        // does not define an application/UI input range or persist a wrapped angle.
        $radians = deg2rad(fmod($angleDeg, 360.0));
        $cosine = cos($radians);
        $sine = sin($radians);
        $oneMinusCosine = 1.0 - $cosine;

        return [
            'method' => self::METHOD,
            'rotation_matrix' => [
                [
                    $cosine + $x * $x * $oneMinusCosine,
                    $x * $y * $oneMinusCosine - $z * $sine,
                    $x * $z * $oneMinusCosine + $y * $sine,
                ],
                [
                    $y * $x * $oneMinusCosine + $z * $sine,
                    $cosine + $y * $y * $oneMinusCosine,
                    $y * $z * $oneMinusCosine - $x * $sine,
                ],
                [
                    $z * $x * $oneMinusCosine - $y * $sine,
                    $z * $y * $oneMinusCosine + $x * $sine,
                    $cosine + $z * $z * $oneMinusCosine,
                ],
            ],
        ];
    }
}
