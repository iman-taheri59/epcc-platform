<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Reference\FittingDimensions;
use App\Support\Reference\PipeReference;
use InvalidArgumentException;

/**
 * Source-backed local port geometry for ASME B16.9 concentric (and limited
 * eccentric) reducers.
 *
 * The local origin is the centre of the large end (port 1, LARGE). Local +X
 * points toward the small end (port 2, SMALL). The B16.9 end-to-end length H
 * is read from Table 6.1-11 for the large NPS. H is independent of the small
 * size for a given large size — that is the standard's own dimension, not an
 * inference. For concentric reducers the two ports are coaxial; roll therefore
 * has no effect. For eccentric reducers the small bore is offset by
 * (OD_large - OD_small)/2 along +Y with the flat side at +Y, but the current
 * slice keeps that offset calculation separate and requires both port sizes.
 *
 * This profile always uses the large-end NPS for the H lookup. A second
 * nominal size is required only for the eccentric offset validation; a pure
 * concentric reducer needs only the large size. Reducing outlet tees are not
 * handled here.
 */
final class ButtWeldReducerGeometryProfile
{
    public const CONCENTRIC_PROFILE_ID = 'ASME_B16_9_BW_REDUCER_CONCENTRIC_TWO_PORTS_V1';

    public const ECCENTRIC_PROFILE_ID = 'ASME_B16_9_BW_REDUCER_ECCENTRIC_TWO_PORTS_V1';

    public const PROFILE_REVISION = '1';

    public const SUPPORTED_QUALIFIERS = ['CONCENTRIC', 'ECCENTRIC'];

    /**
     * Resolve one reducer instance from the pinned B16.9 dimension reference.
     *
     * For CONCENTRIC, only the large-end NPS is required for length H; the
     * small size is validated separately by the component service. For
     * ECCENTRIC, both sizes are used to compute the flat-side offset via the
     * pipe OD reference — if the small size is absent the profile is
     * UNRESOLVED.
     *
     * @return array<string, mixed>
     */
    public static function resolve(
        ?string $largeNps,
        ?string $smallNps,
        ?string $qualifier,
        FittingDimensions $dimensions,
        PipeReference $pipe,
    ): array {
        $largeNps = $largeNps === null || trim($largeNps) === '' ? null : trim($largeNps);
        $smallNps = $smallNps === null || trim($smallNps) === '' ? null : trim($smallNps);
        $qualifier = $qualifier === null || trim($qualifier) === '' ? null : strtoupper(trim($qualifier));

        $profileId = match ($qualifier) {
            'ECCENTRIC' => self::ECCENTRIC_PROFILE_ID,
            default => self::CONCENTRIC_PROFILE_ID,
        };

        $profile = [
            'profile_id' => $profileId,
            'profile_revision' => self::PROFILE_REVISION,
            'coordinate_unit' => 'MM',
            'anchor_local_mm' => [0.0, 0.0, 0.0],
            'primary_axis_local' => [1.0, 0.0, 0.0],
            'clocking_datum_local' => [0.0, 1.0, 0.0],
            'roll_affects_port_geometry' => $qualifier === 'ECCENTRIC',
            'profile_parameters' => [
                'geometry_contract' => $qualifier === 'ECCENTRIC'
                    ? 'B16_9_BW_REDUCER_ECCENTRIC_H_WITH_FLAT_OFFSET_V1'
                    : 'B16_9_BW_REDUCER_CONCENTRIC_H_V1',
                'fitting_qualifier' => $qualifier,
                'anchor_port_seq' => 1,
                'anchor_port_role' => 'LARGE',
                'bend_plane_local' => $qualifier === 'ECCENTRIC' ? 'LOCAL_XY' : null,
                'reference_source' => $dimensions->source(),
            ],
        ];

        if ($qualifier === null || ! in_array($qualifier, self::SUPPORTED_QUALIFIERS, true)) {
            return self::unresolved($profile, $qualifier === null ? 'MISSING_QUALIFIER' : 'QUALIFIER_NOT_COVERED');
        }
        if ($largeNps === null) {
            return self::unresolved($profile, 'MISSING_LARGE_NPS');
        }

        $lookup = $dimensions->lookup('REDUCER', $qualifier, $largeNps);
        if (($lookup['status'] ?? null) !== 'AVAILABLE' || ! is_array($lookup['row'] ?? null)) {
            return self::unresolved($profile, (string) ($lookup['reason'] ?? 'REFERENCE_DATA_NOT_LOADED'));
        }

        $row = $lookup['row'];
        $hMm = $row['mm'] ?? null;
        if ((! is_int($hMm) && ! is_float($hMm)) || ! is_finite((float) $hMm) || (float) $hMm <= 0.0) {
            throw new InvalidArgumentException('The B16.9 reducer length H must be finite and positive.');
        }
        if (($row['dimension'] ?? null) !== 'LENGTH'
            || ($row['symbol'] ?? null) !== 'H'
            || ($row['sourceTable'] ?? null) !== 'Table 6.1-11') {
            throw new InvalidArgumentException('The B16.9 reducer geometry profile requires Table 6.1-11 H row.');
        }

        $h = (float) $hMm;
        $profile['profile_parameters'] += [
            'large_nps' => $row['nps'],
            'small_nps' => $smallNps,
            'end_to_end_h_mm' => $h,
            'dimension' => $row['dimension'],
            'dimension_symbol' => $row['symbol'],
            'source_table' => $row['sourceTable'],
            'standard_code' => $lookup['source']['standard']['code'] ?? 'B16.9',
            'standard_edition_year' => $lookup['source']['standard']['editionYear'] ?? null,
            'reference_row' => $row,
        ];

        // Eccentric offset needs both ODs.
        $offsetY = 0.0;
        if ($qualifier === 'ECCENTRIC') {
            if ($smallNps === null) {
                return self::unresolved($profile, 'ECCENTRIC_REQUIRES_SMALL_NPS');
            }
            $largeSize = $pipe->resolveLabel($largeNps);
            $smallSize = $pipe->resolveLabel($smallNps);
            if ($largeSize === null || $smallSize === null) {
                return self::unresolved($profile, 'ECCENTRIC_SIZE_NOT_IN_REFERENCE');
            }
            if ($smallSize->odMm >= $largeSize->odMm) {
                return self::unresolved($profile, 'ECCENTRIC_SMALL_MUST_BE_SMALLER');
            }
            $offsetY = ($largeSize->odMm - $smallSize->odMm) / 2.0;
            $profile['profile_parameters']['eccentric_offset_y_mm'] = $offsetY;
            $profile['profile_parameters']['large_od_mm'] = $largeSize->odMm;
            $profile['profile_parameters']['small_od_mm'] = $smallSize->odMm;
        }

        return $profile + [
            'status' => 'RESOLVED',
            'ports' => [
                [
                    'seq' => 1,
                    'role' => 'LARGE',
                    'point_local_mm' => [0.0, 0.0, 0.0],
                    'outward_direction_local' => [-1.0, 0.0, 0.0],
                ],
                [
                    'seq' => 2,
                    'role' => 'SMALL',
                    'point_local_mm' => [$h, $offsetY, 0.0],
                    'outward_direction_local' => [1.0, 0.0, 0.0],
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
}
