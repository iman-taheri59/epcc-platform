<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Reference\ForgedFittingElbowDimensions;
use InvalidArgumentException;

/**
 * Port-only geometry for the covered two-ended ASME B16.11-2021 elbows.
 *
 * B16.11 angle labels are 45 and 90; they are not B16.9 LR/SR qualifiers.
 * Threaded ports use the table's centre-to-end dimension directly. For SW,
 * this profile explicitly locates each port datum at the socket bottom and
 * uses table dimension A directly; J is retained as source provenance. This is
 * an application geometry contract, not a CAD body or a weld-gap model.
 */
final class ForgedFittingElbowGeometryProfile
{
    public const PROFILE_ID = 'ASME_B16_11_FORGED_ELBOW_TWO_PORTS_V1';

    public const PROFILE_REVISION = '1';

    public const SUPPORTED_ANGLES = ['45', '90'];

    /**
     * Resolve a supported B16.11 two-end elbow without inventing missing data.
     *
     * @return array<string,mixed>
     */
    public static function resolve(
        ?string $endType,
        ?int $classDesignation,
        ?string $angle,
        ?string $nps,
        ForgedFittingElbowDimensions $dimensions,
    ): array {
        $endType = $endType === null || trim($endType) === '' ? null : strtoupper(trim($endType));
        $classDesignation = $classDesignation !== null && $classDesignation > 0 ? $classDesignation : null;
        $angle = $angle === null || trim($angle) === '' ? null : trim($angle);
        $nps = $nps === null || trim($nps) === '' ? null : trim($nps);

        $base = [
            'profile_id' => self::PROFILE_ID,
            'profile_revision' => self::PROFILE_REVISION,
            'coordinate_unit' => 'MM',
            'anchor_local_mm' => [0.0, 0.0, 0.0],
            'primary_axis_local' => [1.0, 0.0, 0.0],
            'clocking_datum_local' => [0.0, 1.0, 0.0],
            'roll_affects_port_geometry' => true,
            'profile_parameters' => [
                'geometry_contract' => $endType === 'SW'
                    ? 'B16_11_SW_SOCKET_BOTTOM_PORTS_A_DIRECT_V1'
                    : ($endType === 'SC'
                        ? 'B16_11_THREADED_END_PLANE_PORTS_A_OR_C_DIRECT_V1'
                        : 'B16_11_ELBOW_PORT_DATUM_UNRESOLVED_V1'),
                'fitting_standard' => 'B16.11',
                'standard_code' => 'B16.11',
                'standard_edition_year' => 2021,
                'end_type' => $endType,
                'end_configuration' => match ($endType) {
                    'SW' => 'SOCKET_WELD_BOTH_ENDS',
                    'SC' => 'THREADED_BOTH_ENDS',
                    default => null,
                },
                'class_designation' => $classDesignation,
                'bend_angle_deg' => in_array($angle, self::SUPPORTED_ANGLES, true) ? (float) $angle : null,
                'bend_angle_label' => in_array($angle, self::SUPPORTED_ANGLES, true) ? $angle.'°' : null,
                'radius_qualifier' => null,
                'bend_plane_local' => 'LOCAL_XY',
                'anchor_port_seq' => 1,
                'reference_source' => $dimensions->source(),
            ],
        ];

        $lookup = $dimensions->lookup($endType, $classDesignation, $nps, $angle);
        if (($lookup['status'] ?? null) !== 'AVAILABLE' || ! is_array($lookup['row'] ?? null)) {
            return self::unresolved($base, (string) ($lookup['reason'] ?? 'REFERENCE_DATA_NOT_LOADED'));
        }

        $row = $lookup['row'];
        $angleValue = $row['bendDimensions'][$angle] ?? null;
        if (! is_array($angleValue)) {
            return self::unresolved($base, 'ANGLE_DIMENSION_NOT_IN_REFERENCE_ROW');
        }

        if ($endType === 'SC') {
            if (($angleValue['dimension'] ?? null) !== 'CENTRE_TO_END'
                || ($angleValue['symbol'] ?? null) !== ($angle === '90' ? 'A' : 'C')) {
                throw new InvalidArgumentException('The B16.11 threaded elbow row does not contain the expected centre-to-end A/C dimension.');
            }
            $distanceMm = self::positiveFinite($angleValue['mm'] ?? null, 'The B16.11 threaded centre-to-end dimension');
            $portDatum = 'THREADED_END_PLANE';
        } elseif ($endType === 'SW') {
            if (($angleValue['dimension'] ?? null) !== 'CENTRE_TO_BOTTOM_OF_SOCKET'
                || ($angleValue['symbol'] ?? null) !== 'A') {
                throw new InvalidArgumentException('The B16.11 socket-weld elbow row must contain the centre-to-bottom-of-socket A dimension.');
            }
            $distanceMm = self::positiveFinite($angleValue['mm'] ?? null, 'The B16.11 socket-weld centre-to-bottom dimension A');
            $socketDepthMm = self::positiveFinite($row['socketDepthMinMm'] ?? null, 'The B16.11 minimum socket depth J');
            $portDatum = 'SOCKET_BOTTOM';
        } else {
            return self::unresolved($base, 'END_CONFIGURATION_NOT_COVERED');
        }

        $radians = deg2rad((float) $angle);
        $cosine = self::cleanTrigValue(cos($radians));
        $sine = self::cleanTrigValue(sin($radians));

        $base['profile_parameters'] += [
            'nps' => $row['nps'],
            'port_datum' => $portDatum,
            'port_axis_distance_mm' => $distanceMm,
            'dimension' => $angleValue['dimension'],
            'dimension_symbol' => $angleValue['symbol'],
            'dimension_mm' => (float) $angleValue['mm'],
            'source_table' => $row['sourceTable'],
            'reference_row' => $row,
        ];
        if ($endType === 'SC') {
            $base['profile_parameters']['center_to_end_mm'] = $distanceMm;
        } else {
            $base['profile_parameters'] += [
                'socket_depth_min_mm' => $socketDepthMm,
                'socket_depth_symbol' => 'J',
                'socket_port_datum_contract' => 'SOCKET_BOTTOM_AT_TABLE_A_NO_A_MINUS_J_OFFSET',
                'pre_weld_gap_applied' => false,
            ];
        }

        return $base + [
            'status' => 'RESOLVED',
            'ports' => [
                [
                    'seq' => 1,
                    'role' => 'RUN',
                    'point_local_mm' => [0.0, 0.0, 0.0],
                    'outward_direction_local' => [-1.0, 0.0, 0.0],
                ],
                [
                    'seq' => 2,
                    'role' => 'RUN',
                    'point_local_mm' => [
                        $distanceMm * (1.0 + $cosine),
                        $distanceMm * $sine,
                        0.0,
                    ],
                    'outward_direction_local' => [$cosine, $sine, 0.0],
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $profile @return array<string,mixed> */
    private static function unresolved(array $profile, string $reason): array
    {
        return $profile + [
            'status' => 'UNRESOLVED',
            'reason' => $reason,
            'ports' => [],
        ];
    }

    private static function positiveFinite(mixed $value, string $label): float
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) || (float) $value <= 0.0) {
            throw new InvalidArgumentException($label.' must be finite and positive.');
        }

        return (float) $value;
    }

    /** Only suppress floating-point noise at exact right angles. */
    private static function cleanTrigValue(float $value): float
    {
        return abs($value) < 1e-15 ? 0.0 : $value;
    }
}
