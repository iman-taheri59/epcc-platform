<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\Joint;
use App\Models\Port;

/**
 * Read-only diagnostics for explicit two-port joints (ADR-061).
 *
 * This service never creates or changes topology, ownership, β, θ, or PAD
 * geometry. It reports raw endpoint residuals only; without an applicable
 * engineering acceptance rule the result remains UNASSESSED.
 */
final class JointPortGeometryDiagnosticService
{
    public const METHOD = 'EXPLICIT_TWO_PORT_PORT_GAP_AXIS_DEVIATION_V1';

    /**
     * Calculate diagnostics for only the joints the caller has already loaded
     * and authorized. Geometry is supplied by ComponentPoseService so all
     * endpoints are compared against the same current project-frame snapshot.
     *
     * @param  iterable<Joint>  $joints
     * @param  array<string, array<string, mixed>>  $geometryByComponentId
     * @return array<string, array<string, mixed>> keyed by joint UUID
     */
    public function forJoints(iterable $joints, array $geometryByComponentId): array
    {
        $diagnostics = [];

        foreach ($joints as $joint) {
            $inputSnapshot = $this->inputSnapshot($joint, $geometryByComponentId);
            $diagnostic = $this->forJoint($joint, $geometryByComponentId);
            $diagnostic['method_code'] = self::METHOD;
            $diagnostic['source_fingerprint'] = hash(
                'sha256',
                json_encode($inputSnapshot, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            );
            $diagnostic['input_snapshot'] = $inputSnapshot;
            $diagnostics[$joint->id] = $diagnostic;
        }

        return $diagnostics;
    }

    /** @param array<string, array<string, mixed>> $geometryByComponentId */
    private function forJoint(Joint $joint, array $geometryByComponentId): array
    {
        $portA = $joint->portA;
        $portB = $joint->portB;

        if (! $portA instanceof Port || ! $portB instanceof Port) {
            return [
                'status' => 'UNRESOLVED',
                'reason' => $joint->isOnWall()
                    ? 'WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY'
                    : 'SECOND_PORT_NOT_REPRESENTED',
            ];
        }

        if ($portA->owner_kind !== Port::OWNER_COMPONENT
            || $portB->owner_kind !== Port::OWNER_COMPONENT
            || $portA->component === null
            || $portB->component === null) {
            return [
                'status' => 'UNRESOLVED',
                'reason' => 'ENDPOINT_PROFILE_UNSUPPORTED',
            ];
        }

        $componentA = $portA->component;
        $componentB = $portB->component;
        $geometryA = $geometryByComponentId[$componentA->id] ?? null;
        $geometryB = $geometryByComponentId[$componentB->id] ?? null;

        if (! is_array($geometryA) || ! is_array($geometryB)) {
            return [
                'status' => 'UNRESOLVED',
                'reason' => 'ENDPOINT_GEOMETRY_NOT_AVAILABLE',
            ];
        }

        if (($geometryA['status'] ?? null) !== 'RESOLVED'
            || ($geometryB['status'] ?? null) !== 'RESOLVED') {
            $endpointA = [
                'status' => $geometryA['status'] ?? 'UNRESOLVED',
                'reason' => $geometryA['reason'] ?? null,
            ];
            $endpointB = [
                'status' => $geometryB['status'] ?? 'UNRESOLVED',
                'reason' => $geometryB['reason'] ?? null,
            ];
            $stale = $endpointA['status'] === 'STALE' || $endpointB['status'] === 'STALE';

            return [
                'status' => $stale ? 'STALE' : 'UNRESOLVED',
                'reason' => $stale ? 'ENDPOINT_GEOMETRY_STALE' : 'ENDPOINT_GEOMETRY_UNRESOLVED',
                'endpoint_a' => $endpointA,
                'endpoint_b' => $endpointB,
            ];
        }

        if (($geometryA['model_frame_id'] ?? null) !== ($geometryB['model_frame_id'] ?? null)
            || ($geometryA['coordinate_unit'] ?? null) !== ($geometryB['coordinate_unit'] ?? null)) {
            return [
                'status' => 'STALE',
                'reason' => 'ENDPOINT_FRAME_OR_UNIT_MISMATCH',
            ];
        }

        $portGeometryA = $this->portGeometry($geometryA, $portA);
        $portGeometryB = $this->portGeometry($geometryB, $portB);
        if ($portGeometryA === null || $portGeometryB === null) {
            return [
                'status' => 'UNRESOLVED',
                'reason' => 'PORT_GEOMETRY_NOT_AVAILABLE',
            ];
        }

        $pointA = $portGeometryA['point_model'];
        $pointB = $portGeometryB['point_model'];
        $axisA = $portGeometryA['outward_direction_model'];
        $axisB = $portGeometryB['outward_direction_model'];

        $dx = $pointA[0] - $pointB[0];
        $dy = $pointA[1] - $pointB[1];
        $dz = $pointA[2] - $pointB[2];
        $gap = sqrt($dx * $dx + $dy * $dy + $dz * $dz);

        // At a coincident two-port connection the two outward vectors point
        // away from one another. This is the angular deviation from that
        // ideal opposition, not branch β and not an acceptance tolerance.
        $dot = $axisA[0] * $axisB[0] + $axisA[1] * $axisB[1] + $axisA[2] * $axisB[2];
        $cosDeviation = max(-1.0, min(1.0, -$dot));
        $axisDeviationDeg = rad2deg(acos($cosDeviation));

        return [
            'status' => 'UNASSESSED',
            'reason' => 'NO_ACCEPTANCE_RULE',
            'gap_mm' => $gap,
            'outward_axis_deviation_deg' => $axisDeviationDeg,
            'model_frame_id' => $geometryA['model_frame_id'],
            'coordinate_unit' => $geometryA['coordinate_unit'],
            'pose_revision_a' => $geometryA['pose_revision'] ?? null,
            'pose_revision_b' => $geometryB['pose_revision'] ?? null,
        ];
    }

    /**
     * Capture every explicit joint/endpoint source used by this diagnostic.
     * Component pose ids and current source fingerprints bind the inputs to
     * immutable pose/frame revisions without changing topology or ownership.
     *
     * @param  array<string, array<string, mixed>>  $geometryByComponentId
     * @return array<string, mixed>
     */
    private function inputSnapshot(Joint $joint, array $geometryByComponentId): array
    {
        return [
            'schema' => 'JOINT_GEOMETRY_INPUT_V1',
            'method_code' => self::METHOD,
            'joint' => [
                'id' => $joint->id,
                'project_id' => $joint->project_id,
                'company_id' => $joint->company_id,
                'piping_line_id' => $joint->piping_line_id,
                'joint_type' => $joint->joint_type,
                'branch_kind' => $joint->branch_kind,
                'port_a_id' => $joint->port_a_id,
                'port_b_id' => $joint->port_b_id,
                'branch_header_component_id' => $joint->branch_header_component_id,
                'branch_header_line_id' => $joint->branch_header_line_id,
                'branch_wall_component_id' => $joint->branch_wall_component_id,
                'branch_station_mm' => $joint->branch_station_mm === null ? null : (float) $joint->branch_station_mm,
                'branch_offset_mm' => $joint->branch_offset_mm === null ? null : (float) $joint->branch_offset_mm,
                'branch_longitudinal_angle_deg' => $joint->branch_longitudinal_angle_deg === null
                    ? null : (float) $joint->branch_longitudinal_angle_deg,
                'branch_root_joint_id' => $joint->branch_root_joint_id,
                'branch_pad_id' => $joint->branch_pad_id,
                'branch_pad_side' => $joint->branch_pad_side,
            ],
            'endpoint_a' => $this->portInputSnapshot($joint->portA, $geometryByComponentId),
            'endpoint_b' => $this->portInputSnapshot($joint->portB, $geometryByComponentId),
            'wall_header_component' => $this->componentInputSnapshot(
                $joint->branchHeaderComponent,
                $geometryByComponentId,
            ),
            'wall_target_component' => $this->componentInputSnapshot(
                $joint->branchWallComponent,
                $geometryByComponentId,
            ),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $geometryByComponentId
     * @return array<string, mixed>|null
     */
    private function portInputSnapshot(?Port $port, array $geometryByComponentId): ?array
    {
        if (! $port instanceof Port) {
            return null;
        }

        return [
            'port' => [
                'id' => $port->id,
                'project_id' => $port->project_id,
                'company_id' => $port->company_id,
                'owner_kind' => $port->owner_kind,
                'component_id' => $port->component_id,
                'nozzle_id' => $port->nozzle_id,
                'seq' => (int) $port->seq,
                'role' => (string) $port->role,
                'nominal_size' => $port->nominal_size,
                'nominal_size_decimal' => $port->nominal_size_decimal === null
                    ? null : (float) $port->nominal_size_decimal,
                'schedule' => $port->schedule,
                'end_type' => $port->end_type,
                'is_connected' => (bool) $port->is_connected,
            ],
            'component' => $this->componentInputSnapshot($port->component, $geometryByComponentId),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $geometryByComponentId
     * @return array<string, mixed>|null
     */
    private function componentInputSnapshot(?Component $component, array $geometryByComponentId): ?array
    {
        if (! $component instanceof Component) {
            return null;
        }

        $geometry = $geometryByComponentId[$component->id] ?? null;

        return [
            'component' => [
                'id' => $component->id,
                'project_id' => $component->project_id,
                'company_id' => $component->company_id,
                'piping_line_id' => $component->piping_line_id,
                'sequence' => (int) $component->sequence,
                'template_code' => $component->template_code,
                'fitting_qualifier' => $component->fitting_qualifier,
                'nominal_size' => $component->nominal_size,
                'nominal_size_decimal' => $component->nominal_size_decimal === null
                    ? null : (float) $component->nominal_size_decimal,
                'schedule' => $component->schedule,
                'length_mm' => $component->length_mm === null ? null : (float) $component->length_mm,
            ],
            'geometry_source_fingerprint' => is_array($geometry)
                ? ($geometry['geometry_source_fingerprint'] ?? null)
                : null,
            'geometry' => $geometry,
        ];
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array{point_model: array{0:float,1:float,2:float}, outward_direction_model: array{0:float,1:float,2:float}}|null
     */
    private function portGeometry(array $geometry, Port $port): ?array
    {
        foreach ($geometry['ports'] ?? [] as $candidate) {
            if (! is_array($candidate) || ($candidate['seq'] ?? null) !== $port->seq) {
                continue;
            }

            $point = $candidate['point_model'] ?? null;
            $axis = $candidate['outward_direction_model'] ?? null;
            if (! $this->isFiniteVector3($point) || ! $this->isFiniteVector3($axis)) {
                return null;
            }

            return [
                'point_model' => [(float) $point[0], (float) $point[1], (float) $point[2]],
                'outward_direction_model' => [(float) $axis[0], (float) $axis[1], (float) $axis[2]],
            ];
        }

        return null;
    }

    private function isFiniteVector3(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== 3) {
            return false;
        }

        foreach ($value as $coordinate) {
            if ((! is_int($coordinate) && ! is_float($coordinate)) || ! is_finite((float) $coordinate)) {
                return false;
            }
        }

        return true;
    }
}
