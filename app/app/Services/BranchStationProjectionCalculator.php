<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Project a branch-end point onto a straight header centreline.
 *
 * Both endpoint-based station candidates are returned because the piping
 * model does not identify which header port is upstream. The perpendicular
 * centreline distance is a point-to-axis measurement only: it does not use
 * branch_offset_mm to place a point, model a wall surface, or decide whether
 * the branch contacts the header.
 */
final class BranchStationProjectionCalculator
{
    /**
     * @param  array<int, int|float>  $headerPort1PointModel
     * @param  array<int, int|float>  $headerPort2PointModel
     * @param  array<int, int|float>  $branchPortPointModel
     * @return array{
     *   header_length_from_pose_mm:float,
     *   station_from_port_1_mm:float,
     *   station_from_port_2_mm:float,
     *   perpendicular_distance_to_header_axis_mm:float
     * }
     */
    public static function measure(
        array $headerPort1PointModel,
        array $headerPort2PointModel,
        array $branchPortPointModel,
    ): array {
        $port1 = self::vector3($headerPort1PointModel, 'header port 1');
        $port2 = self::vector3($headerPort2PointModel, 'header port 2');
        $branch = self::vector3($branchPortPointModel, 'branch port');

        $headerAxis = [
            $port2[0] - $port1[0],
            $port2[1] - $port1[1],
            $port2[2] - $port1[2],
        ];
        $headerLength = sqrt(
            $headerAxis[0] * $headerAxis[0]
            + $headerAxis[1] * $headerAxis[1]
            + $headerAxis[2] * $headerAxis[2],
        );

        // Numerical degeneracy guard only; it is not a dimensional or
        // engineering acceptance tolerance.
        if (! is_finite($headerLength) || $headerLength <= 1e-12) {
            throw new InvalidArgumentException('The header centreline endpoints do not define a usable axis.');
        }

        $branchFromPort1 = [
            $branch[0] - $port1[0],
            $branch[1] - $port1[1],
            $branch[2] - $port1[2],
        ];
        $stationFromPort1 = (
            $branchFromPort1[0] * $headerAxis[0]
            + $branchFromPort1[1] * $headerAxis[1]
            + $branchFromPort1[2] * $headerAxis[2]
        ) / $headerLength;
        $stationFromPort2 = $headerLength - $stationFromPort1;
        $perpendicularVector = [
            $branchFromPort1[0] - $stationFromPort1 * $headerAxis[0] / $headerLength,
            $branchFromPort1[1] - $stationFromPort1 * $headerAxis[1] / $headerLength,
            $branchFromPort1[2] - $stationFromPort1 * $headerAxis[2] / $headerLength,
        ];
        $perpendicularDistance = sqrt(
            $perpendicularVector[0] * $perpendicularVector[0]
            + $perpendicularVector[1] * $perpendicularVector[1]
            + $perpendicularVector[2] * $perpendicularVector[2],
        );

        if (! is_finite($stationFromPort1) || ! is_finite($stationFromPort2)
            || ! is_finite($perpendicularDistance)) {
            throw new InvalidArgumentException('The projected branch station and axis distance must be finite.');
        }

        return [
            'header_length_from_pose_mm' => $headerLength,
            'station_from_port_1_mm' => $stationFromPort1,
            'station_from_port_2_mm' => $stationFromPort2,
            'perpendicular_distance_to_header_axis_mm' => $perpendicularDistance,
        ];
    }

    /** @param array<int, int|float> $value @return array{0:float,1:float,2:float} */
    private static function vector3(array $value, string $field): array
    {
        if (! array_is_list($value) || count($value) !== 3) {
            throw new InvalidArgumentException("The $field model point must contain three finite coordinates.");
        }

        $vector = [];
        foreach ($value as $coordinate) {
            if ((! is_int($coordinate) && ! is_float($coordinate)) || ! is_finite((float) $coordinate)) {
                throw new InvalidArgumentException("The $field model point must contain three finite coordinates.");
            }
            $vector[] = (float) $coordinate;
        }

        return $vector;
    }
}
