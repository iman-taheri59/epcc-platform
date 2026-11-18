<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Reference\FittingDimensions;
use JsonException;
use RuntimeException;

/**
 * Connection-point geometry for a one-port BW cap.
 *
 * The local origin is the open-end port centre. Local +X points from that
 * connection into the blind cap body, so the port outward normal is -X. Cap
 * body extent is emitted only when B16.9 applicability can be resolved from
 * explicitly supplied specified thickness and its source; schedule thickness
 * is never substituted. NPS 26-48 remains unresolved pending agreement data.
 */
final class SinglePortCapGeometryProfile
{
    public const PROFILE_ID = 'SINGLE_PORT_BW_CAP_GEOMETRY_V3';

    public const PROFILE_REVISION = '3';

    /** @return array<string, mixed> */
    public static function resolve(
        ?string $nps,
        mixed $specifiedWallThicknessMm,
        ?string $thicknessSource,
        FittingDimensions $dimensions,
    ): array {
        $parameters = [
            'geometry_contract' => 'CAP_OPEN_END_PORT_CENTRE_AND_OPTIONAL_CLOSED_END_V3',
            'body_extent_scope' => 'AXIAL_REFERENCE_LENGTH_AND_CLOSED_END_POINT_ONLY_NO_CONTOUR',
            'anchor_port_seq' => 1,
            'body_extent_status' => 'UNRESOLVED',
            'body_extent_reason' => 'CAP_THICKNESS_REQUIRED',
        ];
        $profile = [
            'profile_id' => self::PROFILE_ID,
            'profile_revision' => self::PROFILE_REVISION,
            'coordinate_unit' => 'MM',
            'anchor_local_mm' => [0.0, 0.0, 0.0],
            'primary_axis_local' => [1.0, 0.0, 0.0],
            'clocking_datum_local' => [0.0, 1.0, 0.0],
            'roll_affects_port_geometry' => false,
            'profile_parameters' => $parameters,
            'status' => 'RESOLVED',
            'ports' => [[
                'seq' => 1,
                'role' => 'RUN',
                'point_local_mm' => [0.0, 0.0, 0.0],
                'outward_direction_local' => [-1.0, 0.0, 0.0],
            ]],
        ];

        if ($nps === null || trim($nps) === '') {
            $profile['profile_parameters']['body_extent_reason'] = 'CAP_NPS_REQUIRED';

            return $profile;
        }
        if ($specifiedWallThicknessMm === null) {
            return $profile;
        }
        if ((! is_int($specifiedWallThicknessMm) && ! is_float($specifiedWallThicknessMm))
            || ! is_finite((float) $specifiedWallThicknessMm)
            || (float) $specifiedWallThicknessMm <= 0.0) {
            $profile['profile_parameters']['body_extent_reason'] = 'CAP_THICKNESS_INVALID';

            return $profile;
        }
        if ($thicknessSource === null || trim($thicknessSource) === '') {
            $profile['profile_parameters']['body_extent_reason'] = 'CAP_THICKNESS_SOURCE_REQUIRED';

            return $profile;
        }

        try {
            $lookup = $dimensions->lookup('CAP', null, $nps, (float) $specifiedWallThicknessMm);
        } catch (RuntimeException|JsonException) {
            $profile['profile_parameters']['body_extent_reason'] = 'CAP_DIMENSION_REFERENCE_INVALID_OR_UNAVAILABLE';

            return $profile;
        }

        if (($lookup['status'] ?? null) !== 'AVAILABLE' || ! is_array($lookup['row'] ?? null)) {
            $profile['profile_parameters']['body_extent_reason'] = (string) ($lookup['reason'] ?? 'CAP_DIMENSION_NOT_AVAILABLE');

            return $profile;
        }

        $row = $lookup['row'];
        $lengthMm = $row['mm'] ?? null;
        if ((! is_int($lengthMm) && ! is_float($lengthMm))
            || ! is_finite((float) $lengthMm)
            || (float) $lengthMm <= 0.0
            || ($row['dimension'] ?? null) !== 'LENGTH'
            || ! in_array($row['symbol'] ?? null, ['E', 'E1'], true)
            || ($row['sourceTable'] ?? null) !== 'Table 6.1-10') {
            $profile['profile_parameters']['body_extent_reason'] = 'CAP_DIMENSION_ROW_INVALID';

            return $profile;
        }

        $profile['profile_parameters'] = array_replace($profile['profile_parameters'], [
            'body_extent_status' => 'RESOLVED',
            'body_extent_reason' => null,
            'body_length_mm' => (float) $lengthMm,
            'body_dimension_symbol' => $row['symbol'],
            'body_dimension' => $row['dimension'],
            'body_source_table' => $row['sourceTable'],
            'body_thickness_selection' => $lookup['selection'] ?? null,
            'body_thickness_source' => trim($thicknessSource),
            'body_standard_reference' => $lookup['source'] ?? null,
        ]);
        $profile['reference_points'] = [
            'closed_end' => [(float) $lengthMm, 0.0, 0.0],
        ];

        return $profile;
    }
}
