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
        self::assertSame('JOINT_GEOMETRY_INPUT_V3', $diagnostic['input_snapshot']['schema']);
        self::assertSame(
            JointPortGeometryDiagnosticService::BRANCH_BETA_METHOD,
            $diagnostic['input_snapshot']['branch_beta_method_code'],
        );
        self::assertSame(
            JointPortGeometryDiagnosticService::BRANCH_STATION_METHOD,
            $diagnostic['input_snapshot']['branch_station_method_code'],
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
        self::assertSame(JointPortGeometryDiagnosticService::BRANCH_STATION_METHOD, $station['method_code']);
        self::assertSame('UNASSESSED', $station['status']);
        self::assertSame('UPSTREAM_ENDPOINT_NOT_IDENTIFIED', $station['reason']);
        self::assertSame(2500.0, $station['station_recorded_mm']);
        self::assertEqualsWithDelta(6000.0, $station['header_length_from_pose_mm'], 1e-10);
        self::assertEqualsWithDelta(2500.0, $station['station_from_port_1_mm'], 1e-10);
        self::assertEqualsWithDelta(3500.0, $station['station_from_port_2_mm'], 1e-10);
        self::assertEqualsWithDelta(0.0, $station['delta_from_port_1_mm'], 1e-10);
        self::assertEqualsWithDelta(1000.0, $station['delta_from_port_2_mm'], 1e-10);
        self::assertArrayNotHasKey('branch_offset_mm', $station);
        self::assertArrayNotHasKey('contact_surface', $station);
        self::assertArrayNotHasKey('wall_contact', $station);
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
        self::assertNull($diagnostic['input_snapshot']['branch_beta_method_code']);
        self::assertArrayNotHasKey('branch_station_method_code', $diagnostic['input_snapshot']);
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
