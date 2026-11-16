<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Measures the existing P2P β contract from two explicitly oriented 3D axes.
 *
 * β is the smaller angle between the *unoriented axis lines*, so
 * β = acos(abs(hat(header) · hat(branch))) and lies in [0°, 90°]. Reversing
 * either input axis leaves β unchanged. The signed dot product and angle
 * between the oriented input vectors are returned separately because they
 * distinguish opposite axial senses that β deliberately collapses.
 *
 * The axes must already be expressed in the same frame. This class does not
 * construct component pose, infer an axis from station/offset, select a
 * circumferential zero, enforce the B31.3 45° minimum, or feed PAD geometry.
 */
final class BranchAxisAngleCalculator
{
    public const METHOD = 'SMALLER_UNORIENTED_AXIS_ANGLE_V1';

    private const TRANSVERSE_EPSILON = 1e-12;

    /**
     * @param  list<int|float>  $headerAxis  Oriented header/run axis in a shared frame
     * @param  list<int|float>  $branchAxis  Oriented branch axis in the same frame
     * @return array{
     *   method:string,
     *   beta_deg:float,
     *   angle_between_oriented_vectors_deg:float,
     *   signed_header_axis_projection:float,
     *   header_axis_unit_vector:array{0:float,1:float,2:float},
     *   branch_axis_unit_vector:array{0:float,1:float,2:float},
     *   branch_transverse_unit_vector:array{0:float,1:float,2:float}|null
     * }
     */
    public static function measure(array $headerAxis, array $branchAxis): array
    {
        $header = self::unitAxis($headerAxis, 'headerAxis');
        $branch = self::unitAxis($branchAxis, 'branchAxis');
        $signedProjection = self::clamp(self::dot($header, $branch), -1.0, 1.0);
        $transverse = [
            $branch[0] - $signedProjection * $header[0],
            $branch[1] - $signedProjection * $header[1],
            $branch[2] - $signedProjection * $header[2],
        ];
        $transverseLength = sqrt(self::dot($transverse, $transverse));
        $transverseUnit = null;

        if ($transverseLength > self::TRANSVERSE_EPSILON) {
            $transverseUnit = [
                $transverse[0] / $transverseLength,
                $transverse[1] / $transverseLength,
                $transverse[2] / $transverseLength,
            ];
        }

        return [
            'method' => self::METHOD,
            'beta_deg' => rad2deg(acos(abs($signedProjection))),
            'angle_between_oriented_vectors_deg' => rad2deg(acos($signedProjection)),
            'signed_header_axis_projection' => $signedProjection,
            'header_axis_unit_vector' => $header,
            'branch_axis_unit_vector' => $branch,
            'branch_transverse_unit_vector' => $transverseUnit,
        ];
    }

    /** @param list<int|float> $axis @return array{0:float,1:float,2:float} */
    private static function unitAxis(array $axis, string $name): array
    {
        if (! array_is_list($axis) || count($axis) !== 3) {
            throw new InvalidArgumentException("$name must contain exactly three finite numeric values.");
        }

        $values = [];
        foreach ($axis as $value) {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException("$name must contain exactly three finite numeric values.");
            }
            $values[] = (float) $value;
        }

        // Scale first so finite very large/small inputs do not overflow/underflow
        // while their direction is normalized.
        $scale = max(abs($values[0]), abs($values[1]), abs($values[2]));
        if ($scale === 0.0) {
            throw new InvalidArgumentException("$name must be a non-zero axis vector.");
        }

        $scaled = [$values[0] / $scale, $values[1] / $scale, $values[2] / $scale];
        $length = sqrt(self::dot($scaled, $scaled));

        return [$scaled[0] / $length, $scaled[1] / $length, $scaled[2] / $length];
    }

    /** @param array{0:float,1:float,2:float} $left @param array{0:float,1:float,2:float} $right */
    private static function dot(array $left, array $right): float
    {
        return $left[0] * $right[0] + $left[1] * $right[1] + $left[2] * $right[2];
    }

    private static function clamp(float $value, float $minimum, float $maximum): float
    {
        return min($maximum, max($minimum, $value));
    }
}
