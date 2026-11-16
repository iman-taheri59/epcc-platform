<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Adapt an explicitly dimensioned signed run/rise triangle to ADR-061's
 * azimuth/inclination/roll convention. This does not define a plane, sign, or
 * clocking datum; the caller must provide azimuth and roll explicitly.
 */
final class DimensionedTriangleOrientation3D
{
    public const METHOD = 'AZIMUTH_SIGNED_RUN_RISE_ROLL_V1';

    /**
     * @return array{
     *   method:string,
     *   azimuth_deg:float,
     *   inclination_deg:float,
     *   roll_deg:float,
     *   calculated_hypotenuse_mm:float,
     *   measured_hypotenuse_mm:?float,
     *   closure_residual_mm:?float,
     *   input_geometry:array<string, int|float|string|null>
     * }
     */
    public static function resolveDeg(
        float $azimuthDeg,
        float $rollDeg,
        float $runMm,
        float $riseMm,
        ?float $measuredHypotenuseMm = null,
    ): array {
        foreach (compact('azimuthDeg', 'rollDeg', 'runMm', 'riseMm') as $name => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("$name must be finite.");
            }
        }

        if ($runMm < 0.0) {
            throw new InvalidArgumentException('runMm must be non-negative along the declared azimuth.');
        }

        $inclination = PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg($runMm, $riseMm)['angle_deg'];
        $calculatedHypotenuse = hypot($runMm, $riseMm);
        if (! is_finite($calculatedHypotenuse)) {
            throw new InvalidArgumentException('The calculated hypotenuse is outside the finite numeric range.');
        }

        $closureResidual = null;
        if ($measuredHypotenuseMm !== null) {
            $closure = RunRiseHypotenuseClosureCalculator::measure(
                $runMm,
                $riseMm,
                $measuredHypotenuseMm,
            );
            $calculatedHypotenuse = $closure['calculated_hypotenuse'];
            $closureResidual = $closure['closure_residual'];
        }

        // Validate azimuth/inclination/roll together using the selected
        // convention. In particular, azimuth is still mandatory when run=0
        // and the component is vertical.
        ManualComponentOrientation3D::resolveDeg($azimuthDeg, $inclination, $rollDeg);

        return [
            'method' => self::METHOD,
            'azimuth_deg' => $azimuthDeg,
            'inclination_deg' => $inclination,
            'roll_deg' => $rollDeg,
            'calculated_hypotenuse_mm' => $calculatedHypotenuse,
            'measured_hypotenuse_mm' => $measuredHypotenuseMm,
            'closure_residual_mm' => $closureResidual,
            'input_geometry' => [
                'input_mode' => 'DIMENSIONED_TRIANGLE',
                'adapter_method' => self::METHOD,
                'angle_calculation_method' => PlanarRunRiseAngleCalculator::METHOD,
                'closure_calculation_method' => $measuredHypotenuseMm === null
                    ? null
                    : RunRiseHypotenuseClosureCalculator::METHOD,
                'angle_unit' => 'DEG',
                'length_unit' => 'MM',
                'azimuth_plane_deg' => $azimuthDeg,
                'roll_deg' => $rollDeg,
                'run_mm' => $runMm,
                'rise_mm' => $riseMm,
                'measured_hypotenuse_mm' => $measuredHypotenuseMm,
                'calculated_hypotenuse_mm' => $calculatedHypotenuse,
                'closure_residual_mm' => $closureResidual,
            ],
        ];
    }
}
