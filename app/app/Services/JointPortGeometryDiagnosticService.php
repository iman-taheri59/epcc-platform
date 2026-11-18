<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\Joint;
use App\Models\Port;
use App\Support\Reference\Exceptions\UnknownSizeException;
use App\Support\Reference\PipeReference;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

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

    public const BRANCH_BETA_METHOD = 'POSE_DERIVED_P2P_BRANCH_BETA_V1';

    public const BRANCH_STATION_METHOD = 'POSE_DERIVED_P2P_BRANCH_STATION_PROJECTION_V3';

    public const BRANCH_HOST_SURFACE_METHOD = 'POSE_DERIVED_P2P_BRANCH_HOST_PIPE_NOMINAL_CYLINDER_RESIDUAL_V1';

    public function __construct(private readonly ?PipeReference $pipeReference = null) {}

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
            $diagnostic = [
                'status' => 'UNRESOLVED',
                'reason' => $joint->isOnWall()
                    ? 'WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY'
                    : 'SECOND_PORT_NOT_REPRESENTED',
            ];

            if ($this->isP2pWallRoot($joint)) {
                $diagnostic['branch_beta_geometry'] = $this->branchBetaGeometry($joint, $geometryByComponentId);
                $stationGeometry = $this->branchStationGeometry($joint, $geometryByComponentId);
                $diagnostic['branch_station_geometry'] = $stationGeometry;
                $diagnostic['branch_host_surface_geometry'] = $this->branchHostSurfaceGeometry(
                    $joint,
                    $geometryByComponentId,
                    $stationGeometry,
                );
            }

            return $diagnostic;
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

    private function isP2pWallRoot(Joint $joint): bool
    {
        return $joint->port_b_id === null
            && $joint->branch_header_component_id !== null
            && in_array($joint->joint_type, [Joint::TYPE_BRANCH_STUB_IN, Joint::TYPE_BRANCH_STUB_ON], true);
    }

    /** @param array<string, array<string, mixed>> $geometryByComponentId @return array<string, mixed> */
    private function branchBetaGeometry(Joint $joint, array $geometryByComponentId): array
    {
        $branchPort = $joint->portA;
        $header = $joint->branchHeaderComponent;
        $branch = $branchPort?->component;

        if (! $branchPort instanceof Port
            || ! $header instanceof Component
            || ! $branch instanceof Component) {
            return $this->unresolvedBranchBeta('P2P_WALL_ROOT_ENDPOINT_NOT_AVAILABLE');
        }

        if ($header->template_code !== 'PIPE'
            || $branch->template_code !== 'PIPE'
            || $branchPort->role !== 'RUN') {
            return $this->unresolvedBranchBeta('P2P_WALL_ROOT_PIPE_PROFILE_UNSUPPORTED');
        }

        $headerGeometry = $geometryByComponentId[$header->id] ?? null;
        $branchGeometry = $geometryByComponentId[$branch->id] ?? null;
        if (! is_array($headerGeometry) || ! is_array($branchGeometry)) {
            return $this->unresolvedBranchBeta('BRANCH_BETA_GEOMETRY_NOT_AVAILABLE');
        }

        $headerStatus = $headerGeometry['status'] ?? 'UNRESOLVED';
        $branchStatus = $branchGeometry['status'] ?? 'UNRESOLVED';
        if ($headerStatus !== 'RESOLVED' || $branchStatus !== 'RESOLVED') {
            $stale = $headerStatus === 'STALE' || $branchStatus === 'STALE';

            return $this->unresolvedBranchBeta(
                $stale ? 'BRANCH_BETA_GEOMETRY_STALE' : 'BRANCH_BETA_GEOMETRY_UNRESOLVED',
                $stale ? 'STALE' : 'UNRESOLVED',
            );
        }

        if (($headerGeometry['model_frame_id'] ?? null) !== ($branchGeometry['model_frame_id'] ?? null)
            || ($headerGeometry['coordinate_unit'] ?? null) !== ($branchGeometry['coordinate_unit'] ?? null)
            || ($headerGeometry['coordinate_unit'] ?? null) !== 'MM') {
            return $this->unresolvedBranchBeta('BRANCH_BETA_FRAME_OR_UNIT_MISMATCH', 'STALE');
        }

        if (($headerGeometry['profile_id'] ?? null) !== LinearTwoPortComponentGeometryProfile::PIPE_PROFILE_ID
            || ($branchGeometry['profile_id'] ?? null) !== LinearTwoPortComponentGeometryProfile::PIPE_PROFILE_ID) {
            return $this->unresolvedBranchBeta('BRANCH_BETA_PIPE_PROFILE_UNSUPPORTED');
        }

        $headerPort = $this->portGeometryBySequence($headerGeometry, 1);
        $branchPortGeometry = $this->portGeometry($branchGeometry, $branchPort);
        if ($headerPort === null || $branchPortGeometry === null
            || $headerPort['role'] !== 'RUN' || $branchPortGeometry['role'] !== 'RUN') {
            return $this->unresolvedBranchBeta('BRANCH_BETA_AXIS_NOT_AVAILABLE');
        }

        try {
            $measured = BranchAxisAngleCalculator::measure(
                $headerPort['outward_direction_model'],
                $branchPortGeometry['outward_direction_model'],
            );
        } catch (InvalidArgumentException) {
            return $this->unresolvedBranchBeta('BRANCH_BETA_AXIS_INVALID');
        }

        $recorded = $joint->branch_longitudinal_angle_deg;
        $recorded = (is_int($recorded) || is_float($recorded)) && is_finite((float) $recorded)
            ? (float) $recorded
            : null;

        return [
            'method_code' => self::BRANCH_BETA_METHOD,
            'status' => 'UNASSESSED',
            'reason' => 'NO_BETA_ACCEPTANCE_RULE',
            'beta_geometry_deg' => $measured['beta_deg'],
            'beta_recorded_deg' => $recorded,
            'delta_geometry_minus_recorded_deg' => $recorded === null
                ? null
                : $measured['beta_deg'] - $recorded,
            'model_frame_id' => $headerGeometry['model_frame_id'],
            'coordinate_unit' => $headerGeometry['coordinate_unit'],
            'pose_revision_header' => $headerGeometry['pose_revision'] ?? null,
            'pose_revision_branch' => $branchGeometry['pose_revision'] ?? null,
        ];
    }

    /**
     * Compare the branch-port model point with the recorded wall station
     * without choosing which header endpoint is upstream or using the offset
     * as a circumferential/contact-surface direction.
     *
     * @param  array<string, array<string, mixed>>  $geometryByComponentId
     * @return array<string, mixed>
     */
    private function branchStationGeometry(Joint $joint, array $geometryByComponentId): array
    {
        $branchPort = $joint->portA;
        $header = $joint->branchHeaderComponent;
        $branch = $branchPort?->component;

        if (! $branchPort instanceof Port
            || ! $header instanceof Component
            || ! $branch instanceof Component) {
            return $this->unresolvedBranchStation('P2P_WALL_ROOT_ENDPOINT_NOT_AVAILABLE');
        }

        if ($header->template_code !== 'PIPE'
            || $branch->template_code !== 'PIPE'
            || $branchPort->role !== 'RUN') {
            return $this->unresolvedBranchStation('P2P_WALL_ROOT_PIPE_PROFILE_UNSUPPORTED');
        }

        $headerGeometry = $geometryByComponentId[$header->id] ?? null;
        $branchGeometry = $geometryByComponentId[$branch->id] ?? null;
        if (! is_array($headerGeometry) || ! is_array($branchGeometry)) {
            return $this->unresolvedBranchStation('BRANCH_STATION_GEOMETRY_NOT_AVAILABLE');
        }

        $headerStatus = $headerGeometry['status'] ?? 'UNRESOLVED';
        $branchStatus = $branchGeometry['status'] ?? 'UNRESOLVED';
        if ($headerStatus !== 'RESOLVED' || $branchStatus !== 'RESOLVED') {
            $stale = $headerStatus === 'STALE' || $branchStatus === 'STALE';

            return $this->unresolvedBranchStation(
                $stale ? 'BRANCH_STATION_GEOMETRY_STALE' : 'BRANCH_STATION_GEOMETRY_UNRESOLVED',
                $stale ? 'STALE' : 'UNRESOLVED',
            );
        }

        if (($headerGeometry['model_frame_id'] ?? null) !== ($branchGeometry['model_frame_id'] ?? null)
            || ($headerGeometry['coordinate_unit'] ?? null) !== ($branchGeometry['coordinate_unit'] ?? null)
            || ($headerGeometry['coordinate_unit'] ?? null) !== 'MM') {
            return $this->unresolvedBranchStation('BRANCH_STATION_FRAME_OR_UNIT_MISMATCH', 'STALE');
        }

        if (($headerGeometry['profile_id'] ?? null) !== LinearTwoPortComponentGeometryProfile::PIPE_PROFILE_ID
            || ($branchGeometry['profile_id'] ?? null) !== LinearTwoPortComponentGeometryProfile::PIPE_PROFILE_ID) {
            return $this->unresolvedBranchStation('BRANCH_STATION_PIPE_PROFILE_UNSUPPORTED');
        }

        $headerPort1 = $this->portGeometryBySequence($headerGeometry, 1);
        $headerPort2 = $this->portGeometryBySequence($headerGeometry, 2);
        $branchPortGeometry = $this->portGeometry($branchGeometry, $branchPort);
        if ($headerPort1 === null || $headerPort2 === null || $branchPortGeometry === null
            || $headerPort1['role'] !== 'RUN'
            || $headerPort2['role'] !== 'RUN'
            || $branchPortGeometry['role'] !== 'RUN') {
            return $this->unresolvedBranchStation('BRANCH_STATION_PORT_GEOMETRY_NOT_AVAILABLE');
        }

        try {
            $projection = BranchStationProjectionCalculator::measure(
                $headerPort1['point_model'],
                $headerPort2['point_model'],
                $branchPortGeometry['point_model'],
            );
        } catch (InvalidArgumentException) {
            return $this->unresolvedBranchStation('BRANCH_STATION_AXIS_INVALID');
        }

        $recorded = $joint->branch_station_mm;
        $recorded = (is_int($recorded) || is_float($recorded)) && is_finite((float) $recorded)
            ? (float) $recorded
            : null;

        return [
            'method_code' => self::BRANCH_STATION_METHOD,
            'status' => 'UNASSESSED',
            'reason' => 'UPSTREAM_ENDPOINT_NOT_IDENTIFIED',
            'station_recorded_mm' => $recorded,
            'header_length_from_pose_mm' => $projection['header_length_from_pose_mm'],
            'station_from_port_1_mm' => $projection['station_from_port_1_mm'],
            'station_from_port_2_mm' => $projection['station_from_port_2_mm'],
            'perpendicular_distance_to_header_axis_mm' => $projection['perpendicular_distance_to_header_axis_mm'],
            'station_recorded_within_pose_length' => $recorded === null
                ? null
                : $recorded >= 0.0 && $recorded <= $projection['header_length_from_pose_mm'],
            'station_recorded_overrun_pose_length_mm' => $recorded === null
                ? null
                : (float) max(
                    0.0,
                    -$recorded,
                    $recorded - $projection['header_length_from_pose_mm'],
                ),
            'axial_projection_within_endpoint_span' => $projection['axial_projection_within_endpoint_span'],
            'axial_overrun_beyond_port_1_mm' => $projection['axial_overrun_beyond_port_1_mm'],
            'axial_overrun_beyond_port_2_mm' => $projection['axial_overrun_beyond_port_2_mm'],
            'delta_from_port_1_mm' => $recorded === null
                ? null
                : $projection['station_from_port_1_mm'] - $recorded,
            'delta_from_port_2_mm' => $recorded === null
                ? null
                : $projection['station_from_port_2_mm'] - $recorded,
            'model_frame_id' => $headerGeometry['model_frame_id'],
            'coordinate_unit' => $headerGeometry['coordinate_unit'],
            'pose_revision_header' => $headerGeometry['pose_revision'] ?? null,
            'pose_revision_branch' => $branchGeometry['pose_revision'] ?? null,
        ];
    }

    /**
     * Report a root-port point's radial residual to a standards-sized,
     * pose-framed circular header pipe. This is not a branch-surface/contact
     * assessment; that remains unresolved without branch surface geometry and
     * an acceptance basis.
     *
     * @param  array<string, array<string, mixed>>  $geometryByComponentId
     * @param  array<string, mixed>  $stationGeometry
     * @return array<string, mixed>
     */
    private function branchHostSurfaceGeometry(
        Joint $joint,
        array $geometryByComponentId,
        array $stationGeometry,
    ): array {
        $method = self::BRANCH_HOST_SURFACE_METHOD;
        if (($stationGeometry['status'] ?? null) !== 'UNASSESSED') {
            $status = ($stationGeometry['status'] ?? null) === 'STALE' ? 'STALE' : 'UNRESOLVED';

            return [
                'method_code' => $method,
                'status' => $status,
                'reason' => 'BRANCH_STATION_GEOMETRY_NOT_READY',
            ];
        }

        if (($stationGeometry['axial_projection_within_endpoint_span'] ?? null) !== true) {
            return [
                'method_code' => $method,
                'status' => 'UNRESOLVED',
                'reason' => 'BRANCH_ROOT_PROJECTION_OUTSIDE_HOST_PIPE_SPAN',
            ];
        }

        $header = $joint->branchHeaderComponent;
        if (! $header instanceof Component || $header->template_code !== 'PIPE') {
            return [
                'method_code' => $method,
                'status' => 'UNRESOLVED',
                'reason' => 'HOST_PIPE_PROFILE_NOT_AVAILABLE',
            ];
        }

        if ($this->pipeReference === null) {
            return [
                'method_code' => $method,
                'status' => 'UNRESOLVED',
                'reason' => 'PIPE_SIZE_REFERENCE_NOT_AVAILABLE',
            ];
        }

        try {
            $size = $this->pipeReference->size((string) $header->nominal_size);
            $sizeSource = $this->pipeReference->sizeProvenance();
        } catch (UnknownSizeException) {
            return [
                'method_code' => $method,
                'status' => 'UNRESOLVED',
                'reason' => 'HOST_PIPE_SIZE_NOT_RESOLVED_IN_REFERENCE',
                'host_nominal_size' => $header->nominal_size,
            ];
        } catch (RuntimeException|JsonException) {
            return [
                'method_code' => $method,
                'status' => 'UNRESOLVED',
                'reason' => 'PIPE_SIZE_REFERENCE_INVALID_OR_UNAVAILABLE',
            ];
        }

        $geometry = $geometryByComponentId[$header->id] ?? null;
        if (! is_array($geometry)
            || ! is_string($geometry['model_frame_id'] ?? null)
            || ($geometry['coordinate_unit'] ?? null) !== 'MM') {
            return [
                'method_code' => $method,
                'status' => 'UNRESOLVED',
                'reason' => 'HOST_PIPE_FRAME_OR_UNIT_NOT_AVAILABLE',
            ];
        }

        $outerDiameter = $size->odMm;
        $radius = $outerDiameter / 2.0;
        $radialDistance = $stationGeometry['perpendicular_distance_to_header_axis_mm'] ?? null;
        if (! is_int($radialDistance) && ! is_float($radialDistance)
            || ! is_finite((float) $radialDistance)) {
            return [
                'method_code' => $method,
                'status' => 'UNRESOLVED',
                'reason' => 'ROOT_PORT_RADIAL_DISTANCE_NOT_AVAILABLE',
            ];
        }

        return [
            'method_code' => $method,
            'status' => 'UNASSESSED',
            'reason' => 'BRANCH_SURFACE_AND_CONTACT_ACCEPTANCE_RULE_NOT_DEFINED',
            'host_pipe_nps' => $size->nps,
            'host_pipe_size_reference' => $sizeSource,
            'host_pipe_outer_diameter_mm' => $outerDiameter,
            'host_pipe_outer_radius_mm' => $radius,
            'root_port_radial_distance_to_axis_mm' => (float) $radialDistance,
            'root_port_signed_radial_residual_to_outer_surface_mm' => (float) $radialDistance - $radius,
            'model_frame_id' => $geometry['model_frame_id'],
            'coordinate_unit' => $geometry['coordinate_unit'],
            'pose_revision_header' => $geometry['pose_revision'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function unresolvedBranchStation(string $reason, string $status = 'UNRESOLVED'): array
    {
        return [
            'method_code' => self::BRANCH_STATION_METHOD,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function unresolvedBranchBeta(string $reason, string $status = 'UNRESOLVED'): array
    {
        return [
            'method_code' => self::BRANCH_BETA_METHOD,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /** @return array<string, mixed> */
    private function hostPipeSurfaceReference(?Component $header): array
    {
        if (! $header instanceof Component || $header->template_code !== 'PIPE') {
            return ['status' => 'UNRESOLVED', 'reason' => 'HOST_PIPE_PROFILE_NOT_AVAILABLE'];
        }
        if ($this->pipeReference === null) {
            return ['status' => 'UNRESOLVED', 'reason' => 'PIPE_SIZE_REFERENCE_NOT_AVAILABLE'];
        }

        try {
            $size = $this->pipeReference->size((string) $header->nominal_size);

            return [
                'status' => 'RESOLVED',
                'nps' => $size->nps,
                'outer_diameter_mm' => $size->odMm,
                'reference' => $this->pipeReference->sizeProvenance(),
            ];
        } catch (UnknownSizeException) {
            return [
                'status' => 'UNRESOLVED',
                'reason' => 'HOST_PIPE_SIZE_NOT_RESOLVED_IN_REFERENCE',
                'nominal_size' => $header->nominal_size,
            ];
        } catch (RuntimeException|JsonException) {
            return [
                'status' => 'UNRESOLVED',
                'reason' => 'PIPE_SIZE_REFERENCE_INVALID_OR_UNAVAILABLE',
            ];
        }
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
        $isP2pWallRoot = $this->isP2pWallRoot($joint);
        $input = [
            'schema' => $isP2pWallRoot ? 'JOINT_GEOMETRY_INPUT_V4' : 'JOINT_GEOMETRY_INPUT_V2',
            'method_code' => self::METHOD,
            'branch_beta_method_code' => $isP2pWallRoot ? self::BRANCH_BETA_METHOD : null,
        ];
        if ($isP2pWallRoot) {
            $input['branch_station_method_code'] = self::BRANCH_STATION_METHOD;
            $input['branch_host_surface_method_code'] = self::BRANCH_HOST_SURFACE_METHOD;
            $input['host_pipe_surface_reference'] = $this->hostPipeSurfaceReference($joint->branchHeaderComponent);
        }

        return $input + [
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
     * @return array{role:string,point_model: array{0:float,1:float,2:float}, outward_direction_model: array{0:float,1:float,2:float}}|null
     */
    private function portGeometry(array $geometry, Port $port): ?array
    {
        return $this->portGeometryBySequence($geometry, (int) $port->seq);
    }

    /**
     * @param  array<string, mixed>  $geometry
     * @return array{role:string,point_model: array{0:float,1:float,2:float}, outward_direction_model: array{0:float,1:float,2:float}}|null
     */
    private function portGeometryBySequence(array $geometry, int $sequence): ?array
    {
        foreach ($geometry['ports'] ?? [] as $candidate) {
            if (! is_array($candidate) || ($candidate['seq'] ?? null) !== $sequence) {
                continue;
            }

            $role = $candidate['role'] ?? null;
            $point = $candidate['point_model'] ?? null;
            $axis = $candidate['outward_direction_model'] ?? null;
            if (! is_string($role) || trim($role) === ''
                || ! $this->isFiniteVector3($point)
                || ! $this->isFiniteVector3($axis)) {
                return null;
            }

            return [
                'role' => $role,
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
