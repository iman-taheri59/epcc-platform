<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Reference\FittingDimensions;
use InvalidArgumentException;

/**
 * Source-backed local port geometry for ASME B16.9 straight (equal) tees.
 *
 * The local origin is the centre of run port 1. Local +X points along the run
 * toward port 2; local +Y points toward the branch port. The B16.9
 * centre-to-end values are read from Table 6.1-7: C is the run centre-to-end
 * and M is the branch centre-to-end. For the current straight-tee slice the
 * run and branch NPS are the same (EQUAL), so a single NPS selects the row.
 * Reducing outlet tees (Table 6.1-8) are not covered — they require a second
 * nominal size and a different row.
 *
 * Ports: seq1 RUN at [0,0,0] outward -X, seq2 RUN at [2*C,0,0] outward +X,
 * seq3 BRANCH at [C,M,0] outward +Y. The anchor is port 1. Roll therefore
 * clocks the branch around the run axis.
 */
final class ButtWeldTeeGeometryProfile
{
    public const PROFILE_ID = 'ASME_B16_9_BW_TEE_STRAIGHT_THREE_PORTS_V1';

    public const PROFILE_REVISION = '1';

    public const SUPPORTED_QUALIFIER = 'STRAIGHT';

    /**
     * Resolve one straight tee instance from the pinned B16.9 dimension reference.
     * Missing or unsupported inputs remain explicitly unresolved.
     *
     * @return array<string, mixed>
     */
    public static function resolve(
        ?string $nps,
        FittingDimensions $dimensions,
    ): array {
        $nps = $nps === null || trim($nps) === '' ? null : trim($nps);

        $profile = [
            'profile_id' => self::PROFILE_ID,
            'profile_revision' => self::PROFILE_REVISION,
            'coordinate_unit' => 'MM',
            'anchor_local_mm' => [0.0, 0.0, 0.0],
            'primary_axis_local' => [1.0, 0.0, 0.0],
            'clocking_datum_local' => [0.0, 1.0, 0.0],
            'roll_affects_port_geometry' => true,
            'profile_parameters' => [
                'geometry_contract' => 'B16_9_BW_TEE_STRAIGHT_RUN_AND_BRANCH_CENTRE_TO_END_V1',
                'fitting_qualifier' => self::SUPPORTED_QUALIFIER,
                'anchor_port_seq' => 1,
                'run_port_sequences' => [1, 2],
                'branch_port_seq' => 3,
                'bend_plane_local' => 'LOCAL_XY',
                'reference_source' => $dimensions->source(),
            ],
        ];

        if ($nps === null) {
            return self::unresolved($profile, 'MISSING_NPS');
        }

        // Straight tee is EQUAL — use STRAIGHT row. Reducing outlet tees need a second size.
        $lookup = $dimensions->lookup('TEE', 'STRAIGHT', $nps);
        if (($lookup['status'] ?? null) !== 'AVAILABLE' || ! is_array($lookup['row'] ?? null)) {
            return self::unresolved($profile, (string) ($lookup['reason'] ?? 'REFERENCE_DATA_NOT_LOADED'));
        }

        $row = $lookup['row'];
        $cMm = $row['mm'] ?? null;
        $mMm = $row['branchCentreToEndMm'] ?? null;

        if ((! is_int($cMm) && ! is_float($cMm)) || ! is_finite((float) $cMm) || (float) $cMm <= 0.0) {
            throw new InvalidArgumentException('The B16.9 tee run centre-to-end reference value must be finite and positive.');
        }
        if ((! is_int($mMm) && ! is_float($mMm)) || ! is_finite((float) $mMm) || (float) $mMm <= 0.0) {
            throw new InvalidArgumentException('The B16.9 tee branch centre-to-end reference value must be finite and positive.');
        }
        if (($row['dimension'] ?? null) !== 'CENTRE_TO_END'
            || ($row['symbol'] ?? null) !== 'C'
            || ($row['branchSymbol'] ?? null) !== 'M'
            || ($row['sourceTable'] ?? null) !== 'Table 6.1-7') {
            throw new InvalidArgumentException('The B16.9 tee geometry profile requires Table 6.1-7 C/M row.');
        }

        $c = (float) $cMm;
        $m = (float) $mMm;

        $profile['profile_parameters'] += [
            'nps' => $row['nps'],
            'run_centre_to_end_mm' => $c,
            'branch_centre_to_end_mm' => $m,
            'dimension' => $row['dimension'],
            'dimension_symbol_run' => $row['symbol'],
            'dimension_symbol_branch' => $row['branchSymbol'],
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
                    'point_local_mm' => [$c * 2.0, 0.0, 0.0],
                    'outward_direction_local' => [1.0, 0.0, 0.0],
                ],
                [
                    'seq' => 3,
                    'role' => 'BRANCH',
                    'point_local_mm' => [$c, $m, 0.0],
                    'outward_direction_local' => [0.0, 1.0, 0.0],
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
