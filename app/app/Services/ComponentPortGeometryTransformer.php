<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Transform a complete local component port profile through a framed pose. */
final class ComponentPortGeometryTransformer
{
    private const VECTOR_TOLERANCE = 1e-8;

    /**
     * @param  array<string,mixed>  $profile
     * @return array{
     *   status:string,
     *   reason?:string,
     *   profile_id:string,
     *   profile_revision:string,
     *   model_frame_id:string,
     *   coordinate_unit:string,
     *   anchor_local_mm:list<float>,
     *   anchor_model:list<int|float>,
     *   profile_parameters?:array<string,mixed>,
     *   ports:list<array{
     *     seq:int,
     *     role:string,
     *     point_local_mm:list<float>,
     *     outward_direction_local:list<float>,
     *     point_model:list<float>,
     *     outward_direction_model:list<float>
     *   }>
     * }
     */
    public static function transform(array $profile, FramedRigidTransform3D $pose): array
    {
        $profileId = $profile['profile_id'] ?? null;
        $profileRevision = $profile['profile_revision'] ?? null;
        $profileStatus = $profile['status'] ?? null;
        $reason = $profile['reason'] ?? null;
        $unit = $profile['coordinate_unit'] ?? null;
        $ports = $profile['ports'] ?? null;
        $profileParameters = $profile['profile_parameters'] ?? null;

        if (! is_string($profileId) || trim($profileId) === '') {
            throw new InvalidArgumentException('A non-empty geometry profile_id is required.');
        }
        if (! is_string($profileRevision) || trim($profileRevision) === '') {
            throw new InvalidArgumentException('A non-empty geometry profile_revision is required.');
        }
        if (! in_array($profileStatus, ['RESOLVED', 'UNRESOLVED'], true)) {
            throw new InvalidArgumentException('The geometry profile must declare status RESOLVED or UNRESOLVED.');
        }
        if (! is_string($unit) || trim($unit) === '') {
            throw new InvalidArgumentException('The local geometry profile must declare its coordinate_unit.');
        }
        if ($profileParameters !== null && ! is_array($profileParameters)) {
            throw new InvalidArgumentException('Optional profile_parameters must be a structured array.');
        }
        if ($unit !== 'MM') {
            throw new InvalidArgumentException('This ADR-061 profile contract requires local coordinates in MM; convert explicitly before transforming.');
        }
        if ($unit !== $pose->coordinateUnit) {
            throw new InvalidArgumentException('Local geometry and component pose units must match exactly; convert explicitly first.');
        }

        $primaryAxis = self::vector3($profile['primary_axis_local'] ?? null, 'primary_axis_local');
        if (max(abs($primaryAxis[0] - 1.0), abs($primaryAxis[1]), abs($primaryAxis[2])) > self::VECTOR_TOLERANCE) {
            throw new InvalidArgumentException('The ADR-061 component primary axis must be local +X.');
        }
        $clockingDatum = self::vector3($profile['clocking_datum_local'] ?? null, 'clocking_datum_local');
        if (max(abs($clockingDatum[0]), abs($clockingDatum[1] - 1.0), abs($clockingDatum[2])) > self::VECTOR_TOLERANCE) {
            throw new InvalidArgumentException('The ADR-061 zero-clock datum must be local +Y.');
        }

        // By contract, the pose translation is the model location of the local
        // geometry anchor, and that anchor is the local-frame origin.
        $anchor = self::vector3($profile['anchor_local_mm'] ?? null, 'anchor_local_mm');
        if (max(abs($anchor[0]), abs($anchor[1]), abs($anchor[2])) > self::VECTOR_TOLERANCE) {
            throw new InvalidArgumentException('The component geometry anchor must be the local-frame origin [0, 0, 0].');
        }

        if ($profileStatus === 'UNRESOLVED') {
            if (! is_string($reason) || trim($reason) === '') {
                throw new InvalidArgumentException('An unresolved geometry profile must declare a non-empty reason.');
            }
            if ($ports !== []) {
                throw new InvalidArgumentException('An unresolved geometry profile cannot publish resolved port coordinates.');
            }

            $resolved = [
                'status' => 'UNRESOLVED',
                'reason' => $reason,
                'profile_id' => $profileId,
                'profile_revision' => $profileRevision,
                'model_frame_id' => $pose->targetFrameId,
                'coordinate_unit' => $pose->coordinateUnit,
                'anchor_local_mm' => $anchor,
                'anchor_model' => $pose->translationTarget,
                'ports' => [],
            ];
            if (is_array($profileParameters)) {
                $resolved['profile_parameters'] = $profileParameters;
            }

            return $resolved;
        }

        if (! is_array($ports) || ! array_is_list($ports) || $ports === []) {
            throw new InvalidArgumentException('A local geometry profile must contain a non-empty list of ports.');
        }

        $seenSequences = [];
        $transformedPorts = [];

        foreach ($ports as $index => $port) {
            if (! is_array($port)) {
                throw new InvalidArgumentException("ports[$index] must be an object.");
            }

            $seq = $port['seq'] ?? null;
            $role = $port['role'] ?? null;
            if (! is_int($seq) || $seq < 1) {
                throw new InvalidArgumentException("ports[$index].seq must be a positive integer.");
            }
            if (isset($seenSequences[$seq])) {
                throw new InvalidArgumentException("Port sequence $seq is duplicated in the local geometry profile.");
            }
            if (! is_string($role) || trim($role) === '') {
                throw new InvalidArgumentException("ports[$index].role must be non-empty text.");
            }

            $point = self::vector3($port['point_local_mm'] ?? null, "ports[$index].point_local_mm");
            $direction = self::vector3($port['outward_direction_local'] ?? null, "ports[$index].outward_direction_local");
            $directionLength = sqrt(
                $direction[0] * $direction[0]
                + $direction[1] * $direction[1]
                + $direction[2] * $direction[2],
            );
            if (abs($directionLength - 1.0) > self::VECTOR_TOLERANCE) {
                throw new InvalidArgumentException("ports[$index].outward_direction_local must be a unit vector.");
            }

            $modelPoint = $pose->transformPoint($point, $pose->sourceFrameId, $unit);
            $modelDirection = $pose->transformDirection($direction, $pose->sourceFrameId);

            $seenSequences[$seq] = true;
            $transformedPorts[] = [
                'seq' => $seq,
                'role' => $role,
                'point_local_mm' => $point,
                'outward_direction_local' => $direction,
                'point_model' => $modelPoint['coordinates'],
                'outward_direction_model' => $modelDirection['coordinates'],
            ];
        }

        $resolved = [
            'status' => 'RESOLVED',
            'profile_id' => $profileId,
            'profile_revision' => $profileRevision,
            'model_frame_id' => $pose->targetFrameId,
            'coordinate_unit' => $pose->coordinateUnit,
            'anchor_local_mm' => $anchor,
            'anchor_model' => $pose->translationTarget,
            'ports' => $transformedPorts,
        ];
        if (is_array($profileParameters)) {
            $resolved['profile_parameters'] = $profileParameters;
        }

        return $resolved;
    }

    /** @return array{0:float,1:float,2:float} */
    private static function vector3(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 3) {
            throw new InvalidArgumentException("$field must contain exactly three finite numeric values.");
        }

        $vector = [];
        foreach ($value as $component) {
            if ((! is_int($component) && ! is_float($component)) || ! is_finite((float) $component)) {
                throw new InvalidArgumentException("$field must contain exactly three finite numeric values.");
            }
            $vector[] = (float) $component;
        }

        return $vector;
    }
}
