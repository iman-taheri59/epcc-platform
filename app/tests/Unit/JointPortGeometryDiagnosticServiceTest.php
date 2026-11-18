<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Component;
use App\Models\Joint;
use App\Models\JointGeometryDiagnosticRevision;
use App\Models\Port;
use App\Services\JointGeometryDiagnosticRevisionService;
use App\Services\JointPortGeometryDiagnosticService;
use App\Services\LinearTwoPortComponentGeometryProfile;
use App\Support\Piping\JointBranchKind;
use App\Support\Reference\PipeReference;
use PHPUnit\Framework\TestCase;

final class JointPortGeometryDiagnosticServiceTest extends TestCase
{
    public function test_p2p_wall_root_reports_pose_beta_separately_from_the_explicit_joint_value(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);

        $diagnostic = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];
        $branchBeta = $diagnostic['branch_beta_geometry'];

        self::assertSame('UNRESOLVED', $diagnostic['status']);
        self::assertSame('WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY', $diagnostic['reason']);
        self::assertArrayNotHasKey('gap_mm', $diagnostic, 'Branch-axis geometry must not imply resolved wall-contact geometry.');
        self::assertSame(JointPortGeometryDiagnosticService::BRANCH_BETA_METHOD, $branchBeta['method_code']);
        self::assertSame('UNASSESSED', $branchBeta['status']);
        self::assertSame('NO_BETA_ACCEPTANCE_RULE', $branchBeta['reason']);
        self::assertEqualsWithDelta(60.0, $branchBeta['beta_geometry_deg'], 1e-10);
        self::assertSame(45.0, $branchBeta['beta_recorded_deg']);
        self::assertEqualsWithDelta(15.0, $branchBeta['delta_geometry_minus_recorded_deg'], 1e-10);
        self::assertSame('project-frame-r1', $branchBeta['model_frame_id']);
        self::assertSame('MM', $branchBeta['coordinate_unit']);
        self::assertSame(3, $branchBeta['pose_revision_header']);
        self::assertSame(7, $branchBeta['pose_revision_branch']);
        self::assertSame(45.0, $joint->branch_longitudinal_angle_deg, 'The recorded β is never overwritten.');
        self::assertSame('JOINT_GEOMETRY_INPUT_V4', $diagnostic['input_snapshot']['schema']);
        self::assertSame('UNRESOLVED', $diagnostic['branch_host_surface_geometry']['status']);
        self::assertSame('PIPE_SIZE_REFERENCE_NOT_AVAILABLE', $diagnostic['branch_host_surface_geometry']['reason']);
        self::assertSame(
            JointPortGeometryDiagnosticService::BRANCH_BETA_METHOD,
            $diagnostic['input_snapshot']['branch_beta_method_code'],
        );
        self::assertSame(
            JointPortGeometryDiagnosticService::BRANCH_STATION_METHOD,
            $diagnostic['input_snapshot']['branch_station_method_code'],
        );
        self::assertSame(
            JointPortGeometryDiagnosticService::BRANCH_HOST_SURFACE_METHOD,
            $diagnostic['input_snapshot']['branch_host_surface_method_code'],
        );
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $diagnostic['source_fingerprint']);
    }

    public function test_stub_on_wall_root_uses_the_same_separate_pose_beta_diagnostic(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $joint->setAttribute('joint_type', Joint::TYPE_BRANCH_STUB_ON);
        $joint->setAttribute('branch_kind', JointBranchKind::STUB_ON);

        $diagnostic = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];

        self::assertSame('UNASSESSED', $diagnostic['branch_beta_geometry']['status']);
        self::assertEqualsWithDelta(60.0, $diagnostic['branch_beta_geometry']['beta_geometry_deg'], 1e-10);
        self::assertEqualsWithDelta(15.0, $diagnostic['branch_beta_geometry']['delta_geometry_minus_recorded_deg'], 1e-10);
    }

    public function test_branch_station_projection_reports_both_endpoint_candidates_without_using_offset_or_resolving_wall_contact(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $branchRootPoint = [2500.0, 125.0, 35.0];
        $branchAxisX = cos(deg2rad(60.0));
        $branchAxisY = sin(deg2rad(60.0));
        $geometry['branch-pipe']['ports'][0]['point_model'] = [
            $branchRootPoint[0] - 1000.0 * $branchAxisX,
            $branchRootPoint[1] - 1000.0 * $branchAxisY,
            $branchRootPoint[2],
        ];
        $geometry['branch-pipe']['ports'][1]['point_model'] = $branchRootPoint;
        $joint->setAttribute('branch_offset_mm', -175.0);

        $diagnostic = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];
        $station = $diagnostic['branch_station_geometry'];

        self::assertSame('UNRESOLVED', $diagnostic['status'], 'A station projection does not resolve wall contact.');
        self::assertSame('WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY', $diagnostic['reason']);
        self::assertArrayNotHasKey('gap_mm', $diagnostic);
        self::assertSame('POSE_DERIVED_P2P_BRANCH_STATION_PROJECTION_V3', $station['method_code']);
        self::assertSame('POSE_DERIVED_P2P_BRANCH_STATION_PROJECTION_V3', $diagnostic['input_snapshot']['branch_station_method_code']);
        self::assertSame('UNASSESSED', $station['status']);
        self::assertSame('UPSTREAM_ENDPOINT_NOT_IDENTIFIED', $station['reason']);
        self::assertSame(2500.0, $station['station_recorded_mm']);
        self::assertEqualsWithDelta(6000.0, $station['header_length_from_pose_mm'], 1e-10);
        self::assertEqualsWithDelta(2500.0, $station['station_from_port_1_mm'], 1e-10);
        self::assertEqualsWithDelta(3500.0, $station['station_from_port_2_mm'], 1e-10);
        self::assertEqualsWithDelta(0.0, $station['delta_from_port_1_mm'], 1e-10);
        self::assertEqualsWithDelta(1000.0, $station['delta_from_port_2_mm'], 1e-10);
        self::assertEqualsWithDelta(sqrt(125.0 ** 2 + 35.0 ** 2), $station['perpendicular_distance_to_header_axis_mm'], 1e-10);
        self::assertTrue($station['station_recorded_within_pose_length']);
        self::assertSame(0.0, $station['station_recorded_overrun_pose_length_mm']);
        self::assertTrue($station['axial_projection_within_endpoint_span']);
        self::assertSame(0.0, $station['axial_overrun_beyond_port_1_mm']);
        self::assertSame(0.0, $station['axial_overrun_beyond_port_2_mm']);
        self::assertArrayNotHasKey('branch_offset_mm', $station);
        self::assertArrayNotHasKey('contact_surface', $station);
        self::assertArrayNotHasKey('wall_contact', $station);
    }

    public function test_host_pipe_outer_cylinder_residual_uses_sourced_od_without_resolving_contact(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $rootPoint = [2500.0, 84.15, 0.0];
        $branchAxis = [cos(deg2rad(60.0)), sin(deg2rad(60.0)), 0.0];
        $geometry['branch-pipe']['ports'][0]['point_model'] = [
            $rootPoint[0] - 1000.0 * $branchAxis[0],
            $rootPoint[1] - 1000.0 * $branchAxis[1],
            $rootPoint[2],
        ];
        $geometry['branch-pipe']['ports'][1]['point_model'] = $rootPoint;

        $diagnostic = (new JointPortGeometryDiagnosticService($this->pipeReference()))
            ->forJoints([$joint], $geometry)[$joint->id];
        $surface = $diagnostic['branch_host_surface_geometry'];

        self::assertSame('UNRESOLVED', $diagnostic['status'], 'The raw host-cylinder residual is not contact resolution.');
        self::assertSame('WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY', $diagnostic['reason']);
        self::assertSame(JointPortGeometryDiagnosticService::BRANCH_HOST_SURFACE_METHOD, $surface['method_code']);
        self::assertSame('UNASSESSED', $surface['status']);
        self::assertSame('BRANCH_SURFACE_AND_CONTACT_ACCEPTANCE_RULE_NOT_DEFINED', $surface['reason']);
        self::assertSame('6', $surface['host_pipe_nps']);
        self::assertSame(168.3, $surface['host_pipe_outer_diameter_mm']);
        self::assertSame(84.15, $surface['host_pipe_outer_radius_mm']);
        self::assertEqualsWithDelta(84.15, $surface['root_port_radial_distance_to_axis_mm'], 1e-10);
        self::assertEqualsWithDelta(0.0, $surface['root_port_signed_radial_residual_to_outer_surface_mm'], 1e-10);
        self::assertSame('project-frame-r1', $surface['model_frame_id']);
        self::assertSame('MM', $surface['coordinate_unit']);
        self::assertSame('pipe-sizes', $surface['host_pipe_size_reference']['ref_id']);
        self::assertArrayNotHasKey('theta_deg', $surface);
        self::assertArrayNotHasKey('contact', $surface);
        self::assertSame(
            'RESOLVED',
            $diagnostic['input_snapshot']['host_pipe_surface_reference']['status'],
        );
    }

    public function test_host_pipe_signed_radial_residual_distinguishes_inside_from_outside_points(): void
    {
        foreach ([
            ['radius_mm' => 80.0, 'residual_mm' => -4.15],
            ['radius_mm' => 90.0, 'residual_mm' => 5.85],
        ] as $case) {
            [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
            $rootPoint = [2500.0, $case['radius_mm'], 0.0];
            $branchAxis = [cos(deg2rad(60.0)), sin(deg2rad(60.0)), 0.0];
            $geometry['branch-pipe']['ports'][0]['point_model'] = [
                $rootPoint[0] - 1000.0 * $branchAxis[0],
                $rootPoint[1] - 1000.0 * $branchAxis[1],
                $rootPoint[2],
            ];
            $geometry['branch-pipe']['ports'][1]['point_model'] = $rootPoint;

            $diagnostic = (new JointPortGeometryDiagnosticService($this->pipeReference()))
                ->forJoints([$joint], $geometry)[$joint->id];
            $surface = $diagnostic['branch_host_surface_geometry'];

            self::assertSame('UNRESOLVED', $diagnostic['status']);
            self::assertSame('UNASSESSED', $surface['status']);
            self::assertEqualsWithDelta($case['radius_mm'], $surface['root_port_radial_distance_to_axis_mm'], 1e-10);
            self::assertEqualsWithDelta($case['residual_mm'], $surface['root_port_signed_radial_residual_to_outer_surface_mm'], 1e-10);
        }
    }

    public function test_host_pipe_cylinder_residual_is_invariant_under_common_rigid_pose_transform(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $rootPoint = [2500.0, 84.15, 0.0];
        $branchAxis = [cos(deg2rad(60.0)), sin(deg2rad(60.0)), 0.0];
        $geometry['branch-pipe']['ports'][0]['point_model'] = [
            $rootPoint[0] - 1000.0 * $branchAxis[0],
            $rootPoint[1] - 1000.0 * $branchAxis[1],
            $rootPoint[2],
        ];
        $geometry['branch-pipe']['ports'][1]['point_model'] = $rootPoint;
        $service = new JointPortGeometryDiagnosticService($this->pipeReference());

        $baseline = $service->forJoints([$joint], $geometry)[$joint->id]['branch_host_surface_geometry'];
        $transformed = $service->forJoints(
            [$joint],
            $this->rotateAndTranslateGeometry($geometry),
        )[$joint->id]['branch_host_surface_geometry'];

        self::assertSame('UNASSESSED', $baseline['status']);
        self::assertSame('UNASSESSED', $transformed['status']);
        self::assertEqualsWithDelta(
            $baseline['root_port_radial_distance_to_axis_mm'],
            $transformed['root_port_radial_distance_to_axis_mm'],
            1e-10,
        );
        self::assertEqualsWithDelta(
            $baseline['root_port_signed_radial_residual_to_outer_surface_mm'],
            $transformed['root_port_signed_radial_residual_to_outer_surface_mm'],
            1e-10,
        );
        self::assertEqualsWithDelta(0.0, $transformed['root_port_signed_radial_residual_to_outer_surface_mm'], 1e-10);
        self::assertSame('UNRESOLVED', $service->forJoints(
            [$joint],
            $this->rotateAndTranslateGeometry($geometry),
        )[$joint->id]['status'], 'A rigidly transformed raw point diagnostic never resolves wall contact.');
    }

    public function test_host_surface_metric_is_unresolved_outside_finite_header_span_or_for_unknown_nps(): void
    {
        $reference = $this->pipeReference();
        foreach ([
            ['root' => [6200.0, 84.15, 0.0], 'nps' => '6', 'reason' => 'BRANCH_ROOT_PROJECTION_OUTSIDE_HOST_PIPE_SPAN'],
            ['root' => [2500.0, 84.15, 0.0], 'nps' => '99', 'reason' => 'HOST_PIPE_SIZE_NOT_RESOLVED_IN_REFERENCE'],
        ] as $case) {
            [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
            $joint->branchHeaderComponent->setAttribute('nominal_size', $case['nps']);
            $branchAxis = [cos(deg2rad(60.0)), sin(deg2rad(60.0)), 0.0];
            $geometry['branch-pipe']['ports'][0]['point_model'] = [
                $case['root'][0] - 1000.0 * $branchAxis[0],
                $case['root'][1] - 1000.0 * $branchAxis[1],
                $case['root'][2],
            ];
            $geometry['branch-pipe']['ports'][1]['point_model'] = $case['root'];

            $diagnostic = (new JointPortGeometryDiagnosticService($reference))
                ->forJoints([$joint], $geometry)[$joint->id];

            self::assertSame('UNRESOLVED', $diagnostic['branch_host_surface_geometry']['status']);
            self::assertSame($case['reason'], $diagnostic['branch_host_surface_geometry']['reason']);
            self::assertArrayNotHasKey(
                'root_port_signed_radial_residual_to_outer_surface_mm',
                $diagnostic['branch_host_surface_geometry'],
            );
        }
    }

    public function test_missing_pipe_size_reference_fails_closed_without_fabricating_a_surface_residual(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $missingReference = new PipeReference(
            sys_get_temp_dir().'/epcc-missing-reference-'.bin2hex(random_bytes(8)),
        );

        $diagnostic = (new JointPortGeometryDiagnosticService($missingReference))
            ->forJoints([$joint], $geometry)[$joint->id];

        self::assertSame('UNRESOLVED', $diagnostic['status']);
        self::assertSame('UNRESOLVED', $diagnostic['branch_host_surface_geometry']['status']);
        self::assertSame(
            'PIPE_SIZE_REFERENCE_INVALID_OR_UNAVAILABLE',
            $diagnostic['branch_host_surface_geometry']['reason'],
        );
        self::assertSame(
            'UNRESOLVED',
            $diagnostic['input_snapshot']['host_pipe_surface_reference']['status'],
        );
        self::assertSame(
            'PIPE_SIZE_REFERENCE_INVALID_OR_UNAVAILABLE',
            $diagnostic['input_snapshot']['host_pipe_surface_reference']['reason'],
        );
        self::assertArrayNotHasKey(
            'root_port_signed_radial_residual_to_outer_surface_mm',
            $diagnostic['branch_host_surface_geometry'],
        );
    }

    public function test_malformed_pipe_size_pack_fails_closed_as_unresolved(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $referenceDirectory = sys_get_temp_dir().'/epcc-invalid-reference-'.bin2hex(random_bytes(8));
        mkdir($referenceDirectory);
        file_put_contents($referenceDirectory.'/pipe-sizes.ref.json', '{invalid json');

        try {
            $diagnostic = (new JointPortGeometryDiagnosticService(new PipeReference($referenceDirectory)))
                ->forJoints([$joint], $geometry)[$joint->id];
        } finally {
            unlink($referenceDirectory.'/pipe-sizes.ref.json');
            rmdir($referenceDirectory);
        }

        self::assertSame('UNRESOLVED', $diagnostic['status']);
        self::assertSame('UNRESOLVED', $diagnostic['branch_host_surface_geometry']['status']);
        self::assertSame(
            'PIPE_SIZE_REFERENCE_INVALID_OR_UNAVAILABLE',
            $diagnostic['branch_host_surface_geometry']['reason'],
        );
        self::assertSame(
            'PIPE_SIZE_REFERENCE_INVALID_OR_UNAVAILABLE',
            $diagnostic['input_snapshot']['host_pipe_surface_reference']['reason'],
        );
        self::assertArrayNotHasKey(
            'root_port_signed_radial_residual_to_outer_surface_mm',
            $diagnostic['branch_host_surface_geometry'],
        );
    }

    public function test_changed_host_nps_stales_the_captured_surface_metric_in_unit_scope(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $service = new JointPortGeometryDiagnosticService($this->pipeReference());
        $captured = $service->forJoints([$joint], $geometry)[$joint->id];
        $capturedSurface = $captured['branch_host_surface_geometry'];

        $revision = new JointGeometryDiagnosticRevision;
        $revision->setRawAttributes([
            'method_code' => JointPortGeometryDiagnosticService::METHOD,
            'source_fingerprint' => $captured['source_fingerprint'],
            'revision' => 1,
            'diagnostic_status' => $captured['status'],
            'diagnostic_reason' => $captured['reason'],
            'diagnostic_snapshot' => json_encode([
                'status' => $captured['status'],
                'reason' => $captured['reason'],
                'branch_host_surface_geometry' => $capturedSurface,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ], true);

        $joint->branchHeaderComponent->setAttribute('nominal_size', '8');
        $changed = $service->forJoints([$joint], $geometry)[$joint->id];
        $revisionService = (new \ReflectionClass(JointGeometryDiagnosticRevisionService::class))
            ->newInstanceWithoutConstructor();
        $display = $revisionService->forDisplay(
            [$joint->id => $changed],
            [$joint->id => $revision],
        )[$joint->id];

        self::assertNotSame($captured['source_fingerprint'], $changed['source_fingerprint']);
        self::assertSame('UNASSESSED', $changed['branch_host_surface_geometry']['status']);
        self::assertSame('8', $changed['branch_host_surface_geometry']['host_pipe_nps']);
        self::assertSame(219.1, $changed['branch_host_surface_geometry']['host_pipe_outer_diameter_mm']);
        self::assertSame('STALE', $display['snapshot_state']);
        self::assertSame('STALE', $display['status']);
        self::assertSame('SOURCE_FINGERPRINT_CHANGED', $display['reason']);
        self::assertSame('UNRESOLVED', $display['captured_status']);
        self::assertSame('UNASSESSED', $display['branch_host_surface_geometry']['status']);
        self::assertSame(168.3, $display['branch_host_surface_geometry']['host_pipe_outer_diameter_mm']);
    }

    public function test_changed_host_pose_stales_capture_without_replacing_the_captured_residual(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $rootPoint = [2500.0, 84.15, 0.0];
        $branchAxis = [cos(deg2rad(60.0)), sin(deg2rad(60.0)), 0.0];
        $geometry['branch-pipe']['ports'][0]['point_model'] = [
            $rootPoint[0] - 1000.0 * $branchAxis[0],
            $rootPoint[1] - 1000.0 * $branchAxis[1],
            $rootPoint[2],
        ];
        $geometry['branch-pipe']['ports'][1]['point_model'] = $rootPoint;
        $service = new JointPortGeometryDiagnosticService($this->pipeReference());
        $captured = $service->forJoints([$joint], $geometry)[$joint->id];

        $revision = new JointGeometryDiagnosticRevision;
        $revision->setRawAttributes([
            'method_code' => JointPortGeometryDiagnosticService::METHOD,
            'source_fingerprint' => $captured['source_fingerprint'],
            'revision' => 1,
            'diagnostic_status' => $captured['status'],
            'diagnostic_reason' => $captured['reason'],
            'diagnostic_snapshot' => json_encode([
                'status' => $captured['status'],
                'reason' => $captured['reason'],
                'branch_host_surface_geometry' => $captured['branch_host_surface_geometry'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ], true);

        foreach ($geometry['header-pipe']['ports'] as &$port) {
            $port['point_model'][1] += 10.0;
        }
        unset($port);
        $geometry['header-pipe']['pose_revision'] = 4;
        $changed = $service->forJoints([$joint], $geometry)[$joint->id];
        $revisionService = (new \ReflectionClass(JointGeometryDiagnosticRevisionService::class))
            ->newInstanceWithoutConstructor();
        $display = $revisionService->forDisplay(
            [$joint->id => $changed],
            [$joint->id => $revision],
        )[$joint->id];

        self::assertNotSame($captured['source_fingerprint'], $changed['source_fingerprint']);
        self::assertSame(4, $changed['branch_host_surface_geometry']['pose_revision_header']);
        self::assertEqualsWithDelta(-10.0, $changed['branch_host_surface_geometry']['root_port_signed_radial_residual_to_outer_surface_mm'], 1e-10);
        self::assertSame('STALE', $display['snapshot_state']);
        self::assertSame('STALE', $display['status']);
        self::assertSame('SOURCE_FINGERPRINT_CHANGED', $display['reason']);
        self::assertEqualsWithDelta(
            0.0,
            $display['branch_host_surface_geometry']['root_port_signed_radial_residual_to_outer_surface_mm'],
            1e-10,
            'A stale display preserves the captured metric and never substitutes live geometry into the historical snapshot.',
        );
    }

    public function test_recorded_station_range_is_compared_to_pose_length_without_an_acceptance_verdict(): void
    {
        foreach ([
            ['recorded' => -25.0, 'within' => false, 'overrun' => 25.0],
            ['recorded' => 6000.0, 'within' => true, 'overrun' => 0.0],
            ['recorded' => 6200.0, 'within' => false, 'overrun' => 200.0],
            ['recorded' => null, 'within' => null, 'overrun' => null],
        ] as $case) {
            [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
            $joint->setAttribute('branch_station_mm', $case['recorded']);

            $diagnostic = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];
            $station = $diagnostic['branch_station_geometry'];

            self::assertSame('UNRESOLVED', $diagnostic['status']);
            self::assertSame('UNASSESSED', $station['status']);
            self::assertSame('UPSTREAM_ENDPOINT_NOT_IDENTIFIED', $station['reason']);
            self::assertSame($case['within'], $station['station_recorded_within_pose_length']);
            self::assertSame($case['overrun'], $station['station_recorded_overrun_pose_length_mm']);
        }
    }

    public function test_branch_beta_requires_resolved_matching_frame_and_mm_geometry(): void
    {
        foreach ([
            ['field' => 'model_frame_id', 'value' => 'another-frame'],
            ['field' => 'coordinate_unit', 'value' => 'IN'],
        ] as $mismatch) {
            [$joint, $geometry] = $this->wallRoot(recordedBeta: 60.0, branchAngle: 60.0);
            $geometry['branch-pipe'][$mismatch['field']] = $mismatch['value'];

            $diagnostic = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];
            $beta = $diagnostic['branch_beta_geometry'];

            self::assertSame('STALE', $beta['status']);
            self::assertSame('BRANCH_BETA_FRAME_OR_UNIT_MISMATCH', $beta['reason']);
            self::assertArrayNotHasKey('beta_geometry_deg', $beta);
            self::assertSame('STALE', $diagnostic['branch_station_geometry']['status']);
            self::assertSame('BRANCH_STATION_FRAME_OR_UNIT_MISMATCH', $diagnostic['branch_station_geometry']['reason']);
            self::assertSame('STALE', $diagnostic['branch_host_surface_geometry']['status']);
            self::assertSame('BRANCH_STATION_GEOMETRY_NOT_READY', $diagnostic['branch_host_surface_geometry']['reason']);
        }
    }

    public function test_branch_beta_remains_unresolved_or_stale_when_pose_sources_do_not_resolve_together(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 60.0, branchAngle: 60.0);
        $geometry['header-pipe']['status'] = 'STALE';

        $diagnostic = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];

        self::assertSame('UNRESOLVED', $diagnostic['status']);
        self::assertSame('STALE', $diagnostic['branch_beta_geometry']['status']);
        self::assertSame('BRANCH_BETA_GEOMETRY_STALE', $diagnostic['branch_beta_geometry']['reason']);
        self::assertArrayNotHasKey('beta_geometry_deg', $diagnostic['branch_beta_geometry']);
        self::assertSame('STALE', $diagnostic['branch_station_geometry']['status']);
        self::assertSame('BRANCH_STATION_GEOMETRY_STALE', $diagnostic['branch_station_geometry']['reason']);
        self::assertSame('STALE', $diagnostic['branch_host_surface_geometry']['status']);
        self::assertSame('BRANCH_STATION_GEOMETRY_NOT_READY', $diagnostic['branch_host_surface_geometry']['reason']);
    }

    public function test_an_olet_wall_weld_does_not_receive_the_p2p_branch_beta_diagnostic(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: null, branchAngle: 60.0);
        $joint->setAttribute('joint_type', Joint::TYPE_OLET_HEADER_WELD);
        $joint->setAttribute('branch_kind', JointBranchKind::OLET);

        $diagnostic = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];

        self::assertSame('UNRESOLVED', $diagnostic['status']);
        self::assertSame('WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY', $diagnostic['reason']);
        self::assertArrayNotHasKey('branch_beta_geometry', $diagnostic);
        self::assertArrayNotHasKey('branch_station_geometry', $diagnostic);
        self::assertArrayNotHasKey('branch_host_surface_geometry', $diagnostic);
        self::assertNull($diagnostic['input_snapshot']['branch_beta_method_code']);
        self::assertArrayNotHasKey('branch_station_method_code', $diagnostic['input_snapshot']);
        self::assertArrayNotHasKey('branch_host_surface_method_code', $diagnostic['input_snapshot']);
    }

    public function test_v2_snapshot_becomes_stale_when_station_projection_joins_the_input_contract(): void
    {
        [$joint, $geometry] = $this->wallRoot(recordedBeta: 45.0, branchAngle: 60.0);
        $current = (new JointPortGeometryDiagnosticService)->forJoints([$joint], $geometry)[$joint->id];
        $legacyInput = $current['input_snapshot'];
        $legacyInput['schema'] = 'JOINT_GEOMETRY_INPUT_V2';
        unset($legacyInput['branch_station_method_code']);
        $legacyFingerprint = hash(
            'sha256',
            json_encode($legacyInput, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        );

        $legacyRevision = new JointGeometryDiagnosticRevision;
        $legacyRevision->setRawAttributes([
            'method_code' => JointPortGeometryDiagnosticService::METHOD,
            'source_fingerprint' => $legacyFingerprint,
            'revision' => 1,
            'diagnostic_status' => 'UNRESOLVED',
            'diagnostic_reason' => 'WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY',
            'diagnostic_snapshot' => json_encode([
                'status' => 'UNRESOLVED',
                'reason' => 'WALL_REFERENCE_WITHOUT_HOST_SURFACE_GEOMETRY',
                'branch_beta_geometry' => $current['branch_beta_geometry'],
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ], true);

        $revisionService = (new \ReflectionClass(JointGeometryDiagnosticRevisionService::class))
            ->newInstanceWithoutConstructor();
        $display = $revisionService->forDisplay(
            [$joint->id => $current],
            [$joint->id => $legacyRevision],
        )[$joint->id];

        self::assertSame('STALE', $display['snapshot_state']);
        self::assertSame('STALE', $display['status']);
        self::assertSame('SOURCE_FINGERPRINT_CHANGED', $display['reason']);
        self::assertSame('UNRESOLVED', $display['captured_status']);
        self::assertArrayHasKey('branch_beta_geometry', $display);
        self::assertArrayNotHasKey('branch_station_geometry', $display);
    }

    /** @return array{Joint,array<string,array<string,mixed>>} */
    private function wallRoot(?float $recordedBeta, float $branchAngle): array
    {
        $header = $this->component('header-pipe', 'header-line', 1, '6', 6000.0);
        $branch = $this->component('branch-pipe', 'branch-line', 1, '2', 1000.0);

        $branchPort = new Port;
        $branchPort->setRawAttributes([
            'id' => 'branch-port-1',
            'project_id' => 'project-1',
            'company_id' => 'company-1',
            'owner_kind' => Port::OWNER_COMPONENT,
            'component_id' => $branch->id,
            'nozzle_id' => null,
            'seq' => 2,
            'role' => 'RUN',
            'nominal_size' => '2',
            'nominal_size_decimal' => 2.0,
            'schedule' => '40',
            'end_type' => 'BW',
            'is_connected' => true,
        ], true);
        $branchPort->setRelation('component', $branch);

        $joint = new Joint;
        $joint->setRawAttributes([
            'id' => 'wall-root-1',
            'project_id' => 'project-1',
            'company_id' => 'company-1',
            'piping_line_id' => 'branch-line',
            'port_a_id' => $branchPort->id,
            'port_b_id' => null,
            'joint_type' => Joint::TYPE_BRANCH_STUB_IN,
            'branch_kind' => JointBranchKind::STUB_IN,
            'branch_header_component_id' => $header->id,
            'branch_header_line_id' => null,
            'branch_wall_component_id' => null,
            'branch_station_mm' => 2500.0,
            'branch_offset_mm' => 0.0,
            'branch_longitudinal_angle_deg' => $recordedBeta,
            'branch_root_joint_id' => null,
            'branch_pad_id' => null,
            'branch_pad_side' => null,
        ], true);
        $joint->setRelation('portA', $branchPort);
        $joint->setRelation('portB', null);
        $joint->setRelation('branchHeaderComponent', $header);
        $joint->setRelation('branchWallComponent', null);

        return [$joint, [
            $header->id => $this->resolvedPipeGeometry(3, [1.0, 0.0, 0.0], 6000.0),
            $branch->id => $this->resolvedPipeGeometry(7, [
                cos(deg2rad($branchAngle)),
                sin(deg2rad($branchAngle)),
                0.0,
            ], 1000.0),
        ]];
    }

    /** @param array<string, array<string, mixed>> $geometryByComponentId */
    private function rotateAndTranslateGeometry(array $geometryByComponentId): array
    {
        foreach ($geometryByComponentId as &$geometry) {
            foreach ($geometry['ports'] as &$port) {
                [$x, $y, $z] = $port['point_model'];
                $port['point_model'] = [$z + 120.0, $y - 40.0, -$x + 25.0];

                [$dx, $dy, $dz] = $port['outward_direction_model'];
                $port['outward_direction_model'] = [$dz, $dy, -$dx];
            }
            unset($port);
        }
        unset($geometry);

        return $geometryByComponentId;
    }

    private function pipeReference(): PipeReference
    {
        return new PipeReference(dirname(__DIR__, 3).'/standards-packs/reference');
    }

    private function component(string $id, string $lineId, int $sequence, string $nps, float $lengthMm): Component
    {
        $component = new Component;
        $component->setRawAttributes([
            'id' => $id,
            'project_id' => 'project-1',
            'company_id' => 'company-1',
            'piping_line_id' => $lineId,
            'sequence' => $sequence,
            'template_code' => 'PIPE',
            'fitting_qualifier' => null,
            'nominal_size' => $nps,
            'nominal_size_decimal' => (float) $nps,
            'schedule' => '40',
            'length_mm' => $lengthMm,
        ], true);

        return $component;
    }

    /** @param array{0:float,1:float,2:float} $axis @return array<string,mixed> */
    private function resolvedPipeGeometry(int $poseRevision, array $axis, float $lengthMm = 1000.0): array
    {
        return [
            'status' => 'RESOLVED',
            'profile_id' => LinearTwoPortComponentGeometryProfile::PIPE_PROFILE_ID,
            'profile_revision' => '1',
            'model_frame_id' => 'project-frame-r1',
            'coordinate_unit' => 'MM',
            'pose_revision' => $poseRevision,
            'ports' => [
                [
                    'seq' => 1,
                    'role' => 'RUN',
                    'point_model' => [0.0, 0.0, 0.0],
                    'outward_direction_model' => [-$axis[0], -$axis[1], -$axis[2]],
                ],
                [
                    'seq' => 2,
                    'role' => 'RUN',
                    'point_model' => [$lengthMm * $axis[0], $lengthMm * $axis[1], $lengthMm * $axis[2]],
                    'outward_direction_model' => $axis,
                ],
            ],
        ];
    }
}
