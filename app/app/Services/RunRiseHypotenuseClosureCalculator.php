<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Report the raw closure residual for signed run/rise coordinates and a measured
 * hypotenuse, without deciding whether that residual is acceptable.
 *
 * All lengths must already use one common unit. The caller supplies the plane
 * and coordinate signs. The returned residual is measured minus calculated,
 * in the same length unit. No engineering tolerance is selected here.
 */
final class RunRiseHypotenuseClosureCalculator
{
    public const METHOD = 'RUN_RISE_HYPOTENUSE_RESIDUAL_V1';

    /**
     * @return array{
     *   method:string,
     *   calculated_hypotenuse:float,
     *   measured_hypotenuse:float,
     *   closure_residual:float
     * }
     */
    public static function measure(
        float $run,
        float $rise,
        float $measuredHypotenuse,
    ): array {
        if (! is_finite($run) || ! is_finite($rise)) {
            throw new InvalidArgumentException('Run and rise must be finite signed coordinates.');
        }

        if (! is_finite($measuredHypotenuse) || $measuredHypotenuse <= 0.0) {
            throw new InvalidArgumentException('The measured hypotenuse must be finite and positive.');
        }

        if ($run === 0.0 && $rise === 0.0) {
            throw new InvalidArgumentException('Run and rise cannot both be zero.');
        }

        $calculatedHypotenuse = hypot($run, $rise);
        if (! is_finite($calculatedHypotenuse)) {
            throw new InvalidArgumentException('The calculated hypotenuse is outside the finite numeric range.');
        }

        return [
            'method' => self::METHOD,
            'calculated_hypotenuse' => $calculatedHypotenuse,
            'measured_hypotenuse' => $measuredHypotenuse,
            'closure_residual' => $measuredHypotenuse - $calculatedHypotenuse,
        ];
    }
}
