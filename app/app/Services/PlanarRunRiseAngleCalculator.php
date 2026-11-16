<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Calculate a signed planar angle from explicitly signed run/rise coordinates.
 *
 * The caller supplies orthogonal coordinates in one already-defined plane and
 * in one common length unit. The result is atan2(rise, run) in degrees: it is
 * relative to the supplied positive run direction and toward the supplied
 * positive rise direction. This helper does not map drawing signs to axes,
 * choose a plane or azimuth, or infer a 3D component pose, β, or θ.
 */
final class PlanarRunRiseAngleCalculator
{
    public const METHOD = 'SIGNED_PLANAR_RUN_RISE_ATAN2_V1';

    /**
     * @return array{method:string,angle_deg:float}
     */
    public static function fromSignedRunRiseDeg(float $run, float $rise): array
    {
        if (! is_finite($run) || ! is_finite($rise)) {
            throw new InvalidArgumentException('Run and rise must be finite signed coordinates.');
        }

        if ($run === 0.0 && $rise === 0.0) {
            throw new InvalidArgumentException('Run and rise cannot both be zero.');
        }

        return [
            'method' => self::METHOD,
            'angle_deg' => rad2deg(atan2($rise, $run)),
        ];
    }
}
