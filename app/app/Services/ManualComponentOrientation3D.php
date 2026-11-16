<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Resolve the ADR-061 azimuth/inclination/roll input into a local-to-model pose.
 *
 * The component geometry profile's local +X is its primary axis. In the
 * right-handed project model frame, +X is Easting, +Y is Northing and +Z is
 * elevation-up. Angles are degrees. The output matrix's columns are the model
 * directions of local +X, +Y and +Z, respectively.
 */
final class ManualComponentOrientation3D
{
    public const METHOD = 'PROJECT_MODEL_ENU_AZIMUTH_INCLINATION_ROLL_V1';

    /**
     * @return array{
     *   method:string,
     *   angles_deg:array{azimuth:float,inclination:float,roll:float},
     *   local_axes_in_model:array{
     *     '+X':array{0:float,1:float,2:float},
     *     '+Y':array{0:float,1:float,2:float},
     *     '+Z':array{0:float,1:float,2:float}
     *   },
     *   rotation_local_to_model:array{
     *     0:array{0:float,1:float,2:float},
     *     1:array{0:float,1:float,2:float},
     *     2:array{0:float,1:float,2:float}
     *   }
     * }
     */
    public static function resolveDeg(float $azimuthDeg, float $inclinationDeg, float $rollDeg): array
    {
        foreach (compact('azimuthDeg', 'inclinationDeg', 'rollDeg') as $name => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("$name must be finite.");
            }
        }

        if ($inclinationDeg < -90.0 || $inclinationDeg > 90.0) {
            throw new InvalidArgumentException('inclinationDeg must be between -90 and +90 degrees.');
        }

        $azimuth = self::normalizePeriodicDegrees($azimuthDeg);
        $inclination = $inclinationDeg === 0.0 ? 0.0 : $inclinationDeg;
        $roll = self::normalizePeriodicDegrees($rollDeg);

        $azimuthRad = deg2rad($azimuth);
        $inclinationRad = deg2rad($inclination);
        $rollRad = deg2rad($roll);

        $cosAzimuth = cos($azimuthRad);
        $sinAzimuth = sin($azimuthRad);
        $cosInclination = cos($inclinationRad);
        $sinInclination = sin($inclinationRad);
        $cosRoll = cos($rollRad);
        $sinRoll = sin($rollRad);

        // Local +X points along the component's primary axis. Inclination is
        // elevation from the horizontal plane; azimuth remains meaningful at
        // a vertical axis because it also defines the zero-clock transverse
        // datum.
        $x = [
            $cosInclination * $cosAzimuth,
            $cosInclination * $sinAzimuth,
            $sinInclination,
        ];
        $yZero = [-$sinAzimuth, $cosAzimuth, 0.0];
        $zZero = self::cross($x, $yZero);

        // Positive roll is right-handed about oriented local +X. It maps the
        // zero-clock local +Y toward local +Z.
        $y = [
            $cosRoll * $yZero[0] + $sinRoll * $zZero[0],
            $cosRoll * $yZero[1] + $sinRoll * $zZero[1],
            $cosRoll * $yZero[2] + $sinRoll * $zZero[2],
        ];
        $z = [
            -$sinRoll * $yZero[0] + $cosRoll * $zZero[0],
            -$sinRoll * $yZero[1] + $cosRoll * $zZero[1],
            -$sinRoll * $yZero[2] + $cosRoll * $zZero[2],
        ];

        return [
            'method' => self::METHOD,
            'angles_deg' => [
                'azimuth' => $azimuth,
                'inclination' => $inclination,
                'roll' => $roll,
            ],
            'local_axes_in_model' => [
                '+X' => $x,
                '+Y' => $y,
                '+Z' => $z,
            ],
            // Row-major storage; local basis vectors are the matrix columns.
            'rotation_local_to_model' => [
                [$x[0], $y[0], $z[0]],
                [$x[1], $y[1], $z[1]],
                [$x[2], $y[2], $z[2]],
            ],
        ];
    }

    /**
     * Build a frame- and unit-labelled rigid transform from the selected input.
     * The translation is the model-frame coordinate of the local geometry anchor.
     *
     * @param  list<int|float>  $anchorModel
     */
    public static function framedTransform(
        string $localFrameId,
        string $modelFrameId,
        string $coordinateUnit,
        array $anchorModel,
        float $azimuthDeg,
        float $inclinationDeg,
        float $rollDeg,
    ): FramedRigidTransform3D {
        $orientation = self::resolveDeg($azimuthDeg, $inclinationDeg, $rollDeg);

        return new FramedRigidTransform3D(
            sourceFrameId: $localFrameId,
            targetFrameId: $modelFrameId,
            coordinateUnit: $coordinateUnit,
            rotationSourceToTarget: $orientation['rotation_local_to_model'],
            translationTarget: $anchorModel,
        );
    }

    private static function normalizePeriodicDegrees(float $degrees): float
    {
        $normalized = fmod($degrees, 360.0);

        if ($normalized >= 180.0) {
            $normalized -= 360.0;
        } elseif ($normalized < -180.0) {
            $normalized += 360.0;
        }

        return $normalized === 0.0 ? 0.0 : $normalized;
    }

    /** @param array{0:float,1:float,2:float} $left @param array{0:float,1:float,2:float} $right @return array{0:float,1:float,2:float} */
    private static function cross(array $left, array $right): array
    {
        return [
            $left[1] * $right[2] - $left[2] * $right[1],
            $left[2] * $right[0] - $left[0] * $right[2],
            $left[0] * $right[1] - $left[1] * $right[0],
        ];
    }
}
