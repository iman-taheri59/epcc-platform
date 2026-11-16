<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Reference\FittingDimensions;
use InvalidArgumentException;

/**
 * Source-backed local port geometry for the two covered ASME B16.9 BW LR elbows.
 *
 * The local origin is port 1's centre. Local +X points from port 1 into the
 * fitting toward the intersection of the extended port centre-lines; local +Y
 * selects the positive side of the bend plane, and local +Z is +X cross +Y.
 * The B16.9 centre-to-end value is used on each tangent axis to locate port 2.
 * It is not a straight length or a CAD solid model.
 */
final class ButtWeldElbowGeometryProfile
{
    public const PROFILE_ID = 'ASME_B16_9_BW_LR_ELBOW_TWO_PORTS_V1';

    public const PROFILE_REVISION = '1';

    public const SUPPORTED_QUALIFIERS = ['90LR', '45LR'];

    /**
     * Resolve one BW elbow instance from the pinned B16.9 dimension reference.
     * Missing or unsupported engineering inputs remain explicitly unresolved.
     *
     * @return array<string, mixed>
     */
    public static function resolve(
        ?string $qualifier,
        ?string $nps,
        FittingDimensions $dimensions,
    ): array {
        $qualifier = $qualifier === null || trim($qualifier) === ''
            ? null
            : strtoupper(trim($qualifier));
        $nps = $nps === null || trim($nps) === '' ? null : trim($nps);
        $angle = match ($qualifier) {
            '90LR' => 90.0,
            '45LR' => 45.0,
            default => null,
        };

        $profile = [
            'profile_id' => self::PROFILE_ID,
            'profile_revision' => self::PROFILE_REVISION,
            'coordinate_unit' => 'MM',
            'anchor_local_mm' => [0.0, 0.0, 0.0],
            'primary_axis_local' => [1.0, 0.0, 0.0],
            'clocking_datum_local' => [0.0, 1.0, 0.0],
            'roll_affects_port_geometry' => true,
            'profile_parameters' => [
                'geometry_contract' => 'B16_9_BW_ELBOW_TANGENT_PORT_CENTRES_V1',
                'fitting_qualifier' => $qualifier,
                'bend_angle_deg' => $angle,
                'bend_plane_local' => 'LOCAL_XY',
                'anchor_port_seq' => 1,
                'reference_source' => $dimensions->source(),
            ],
        ];

        if ($qualifier === null) {
            return self::unresolved($profile, 'MISSING_QUALIFIER');
        }
        if ($angle === null) {
            return self::unresolved($profile, $qualifier === '45'
                ? 'RADIUS_UNSPECIFIED'
                : 'QUALIFIER_NOT_COVERED');
        }
        if ($nps === null) {
            return self::unresolved($profile, 'MISSING_NPS');
        }

        $lookup = $dimensions->lookup('ELBOW', $qualifier, $nps);
        if (($lookup['status'] ?? null) !== 'AVAILABLE' || ! is_array($lookup['row'] ?? null)) {
            return self::unresolved($profile, (string) ($lookup['reason'] ?? 'REFERENCE_DATA_NOT_LOADED'));
        }

        $row = $lookup['row'];
        $centerToEndMm = $row['mm'] ?? null;
        if ((! is_int($centerToEndMm) && ! is_float($centerToEndMm))
            || ! is_finite((float) $centerToEndMm)
            || (float) $centerToEndMm <= 0.0) {
            throw new InvalidArgumentException('The B16.9 elbow centre-to-end reference value must be finite and positive.');
        }
        if (($row['dimension'] ?? null) !== 'CENTRE_TO_END'
            || ! in_array($row['symbol'] ?? null, ['A', 'B'], true)
            || ($row['sourceTable'] ?? null) !== 'Table 6.1-1') {
            throw new InvalidArgumentException('The B16.9 elbow geometry profile requires Table 6.1-1 centre-to-end row A or B.');
        }

        $distance = (float) $centerToEndMm;
        $radians = deg2rad($angle);
        $cosine = self::cleanTrigValue(cos($radians));
        $sine = self::cleanTrigValue(sin($radians));

        $profile['profile_parameters'] += [
            'nps' => $row['nps'],
            'center_to_end_mm' => $distance,
            'dimension' => $row['dimension'],
            'dimension_symbol' => $row['symbol'],
            'source_table' => $row['sourceTable'],
            'standard_code' => $lookup['source']['standard']['code'] ?? 'B16.9',
            'standard_edition_year' => $lookup['source']['standard']['editionYear'] ?? null,
            'reference_row' => $row,
        ];

        return $profile + [
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
                        $distance * (1.0 + $cosine),
                        $distance * $sine,
                        0.0,
                    ],
                    'outward_direction_local' => [$cosine, $sine, 0.0],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    private static function unresolved(array $profile, string $reason): array
    {
        return $profile + [
            'status' => 'UNRESOLVED',
            'reason' => $reason,
            'ports' => [],
        ];
    }

    /** Only suppress floating-point noise at exact right angles. */
    private static function cleanTrigValue(float $value): float
    {
        return abs($value) < 1e-15 ? 0.0 : $value;
    }
}
