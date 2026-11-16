<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Local port geometry for supported straight, round, two-RUN-port items.
 *
 * PIPE length is its explicitly modelled cut length. For NIPPLE, the explicit
 * length_mm input is defined as the end-to-end distance between port centre
 * planes. Neither profile derives length from schedule, thread engagement,
 * take-out, or a fitting dimension table. NIPPLE thread engagement, wrench
 * flats, and external solid are outside this port-centreline profile. The local
 * anchor is port 1's centre and local +X points toward port 2. Both profiles
 * are rotationally symmetric, so roll has no effect on their port geometry.
 */
final class LinearTwoPortComponentGeometryProfile
{
    public const PIPE_PROFILE_ID = 'STRAIGHT_PIPE_TWO_PORTS_V1';

    public const NIPPLE_PROFILE_ID = 'STRAIGHT_NIPPLE_TWO_PORTS_V1';

    public const SUPPORTED_TEMPLATES = ['PIPE', 'NIPPLE'];

    /**
     * Resolve a supported straight-component profile from its explicit length
     * in millimetres. Missing length is unresolved; no second port is guessed.
     *
     * @return array{
     *   status:string,
     *   reason?:string,
     *   profile_id:string,
     *   profile_revision:string,
     *   coordinate_unit:string,
     *   length_mm:?float,
     *   anchor_local_mm:array{0:float,1:float,2:float},
     *   primary_axis_local:array{0:float,1:float,2:float},
     *   clocking_datum_local:array{0:float,1:float,2:float},
     *   roll_affects_port_geometry:bool,
     *   ports:list<array{
     *     seq:int,
     *     role:string,
     *     point_local_mm:array{0:float,1:float,2:float},
     *     outward_direction_local:array{0:float,1:float,2:float}
     *   }>
     * }
     */
    public static function resolve(string $templateCode, ?float $lengthMm): array
    {
        $profileId = match (strtoupper(trim($templateCode))) {
            'PIPE' => self::PIPE_PROFILE_ID,
            'NIPPLE' => self::NIPPLE_PROFILE_ID,
            default => throw new InvalidArgumentException(
                'A straight two-port geometry profile is not defined for this component template.',
            ),
        };

        if ($lengthMm !== null && (! is_finite($lengthMm) || $lengthMm <= 0.0)) {
            throw new InvalidArgumentException('A straight two-port profile requires a finite positive length in mm.');
        }

        $profile = [
            'profile_id' => $profileId,
            'profile_revision' => '1',
            'coordinate_unit' => 'MM',
            'length_mm' => $lengthMm,
            'anchor_local_mm' => [0.0, 0.0, 0.0],
            'primary_axis_local' => [1.0, 0.0, 0.0],
            'clocking_datum_local' => [0.0, 1.0, 0.0],
            'roll_affects_port_geometry' => false,
        ];

        if ($lengthMm === null) {
            return $profile + [
                'status' => 'UNRESOLVED',
                'reason' => 'MISSING_LENGTH',
                'ports' => [],
            ];
        }

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
                    'point_local_mm' => [$lengthMm, 0.0, 0.0],
                    'outward_direction_local' => [1.0, 0.0, 0.0],
                ],
            ],
        ];
    }
}
