<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Calculate one unsigned internal angle from three explicitly labeled sides.
 *
 * The result is the angle between sideA and sideB; oppositeSide is the side
 * opposite that angle. This accepts true positive lengths in one common unit,
 * not lengths measured from a projected drawing. It does not assign an axis,
 * plane, sign, component pose, or rotation datum, and does not imply that a
 * drawing's hatch/triangle convention has been identified.
 */
final class TriangleAngleCalculator
{
    public const METHOD = 'LAW_OF_COSINES_INCLUDED_ANGLE_V1';

    /**
     * @return array{method:string,angle_deg:float}
     */
    public static function includedAngleDeg(
        float $sideA,
        float $sideB,
        float $oppositeSide,
    ): array {
        foreach (compact('sideA', 'sideB', 'oppositeSide') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }

        // Scale before additions, products, and squares to avoid overflow for
        // large engineering dimensions and underflow for small unit systems.
        $scale = max($sideA, $sideB, $oppositeSide);
        $a = $sideA / $scale;
        $b = $sideB / $scale;
        $c = $oppositeSide / $scale;

        if ($a + $b <= $c || $a + $c <= $b || $b + $c <= $a) {
            throw new InvalidArgumentException('The three side lengths must form a non-degenerate triangle.');
        }

        $cosine = ($a * $a + $b * $b - $c * $c) / (2.0 * $a * $b);
        $cosine = min(1.0, max(-1.0, $cosine));

        return [
            'method' => self::METHOD,
            'angle_deg' => rad2deg(acos($cosine)),
        ];
    }
}
