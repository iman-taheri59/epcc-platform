<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BranchPad;
use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\User;
use App\Support\LineReleaseState;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\P2pKind;
use App\Support\Piping\PipingRuleKind;
use App\Support\Reference\Exceptions\UnknownMaterialException;
use App\Support\Reference\Exceptions\UnknownScheduleException;
use App\Support\Reference\Exceptions\UnknownSizeException;
use App\Support\Reference\MaterialReference;
use App\Support\Reference\PipeReference;
use App\Support\Reference\PipeSize;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Build a branch reinforcement pad and both of its required fillet joints.
 *
 * This service deliberately accepts only an existing direct P2P wall root
 * (stub-in or stub-on). At that topology the physical branch pipe actually
 * meets the header at the pad. An OLET has a purchased fitting between header
 * and branch pipe, so treating a downstream pipe weld as the pad connection
 * would invent geometry; it is intentionally not inferred here.
 */
final class BranchPadService
{
    public function __construct(
        private readonly ComponentService $components,
        private readonly JointService $joints,
        private readonly PmsPinnedClassSpecification $issuedPms,
        private readonly PipeReference $reference,
        private readonly MaterialReference $materials,
        private readonly ProjectArchiveService $archives,
    ) {}

    /**
     * Whether a direct P2P root can be offered in the model-page pad selector.
     * Invalid or unavailable issued rules fail closed to "not eligible".
     */
    public function isEligibleForPad(Joint $branchRoot): bool
    {
        $root = Joint::query()
            ->with(['portA.component.ports', 'branchHeaderComponent'])
            ->find($branchRoot->id);

        if ($root === null) {
            return false;
        }

        try {
            [$branchLine, $branchPipe, $branchPipePort] = $this->branchPipeForRoot($root);
            $header = $root->branchHeaderComponent;

            if ($header === null || $header->template_code !== 'PIPE'
                || $header->project_id !== $root->project_id
                || $root->port_b_id !== null
                || $root->port_a_id !== $branchPipePort->id
                || ! $branchPipePort->is_connected
                || $branchPipe->template_code !== 'PIPE') {
                return false;
            }

            $headerLine = PipingLine::query()->find($header->piping_line_id);
            $project = Project::query()->find($root->project_id);

            if ($headerLine === null || $project === null
                || $branchLine->project_id !== $project->id
                || $headerLine->project_id !== $project->id
                || $branchPipe->project_id !== $project->id
                || $branchLine->release_state === LineReleaseState::RELEASED
                || $headerLine->release_state === LineReleaseState::RELEASED) {
                return false;
            }

            $this->archives->assertWritable($project);
            $rule = $this->resolveHeaderPadRule($root, $header, $headerLine, $branchPipe);
            $this->resolvePadGeometry(
                $root, $header, $headerLine, $branchLine, $branchPipe,
                (float) $rule['pad_thickness'],
                $this->padMaterialFromBranchRule($rule),
            );

            return true;
        } catch (ValidationException|\JsonException) {
            return false;
        }
    }

    /**
     * Install a pad and create exactly two independent, header-owned fillet joints.
     */
    public function install(Joint $branchRoot, User $actor): BranchPad
    {
        $root = Joint::query()
            ->with(['portA.component.ports', 'branchHeaderComponent'])
            ->whereKey($branchRoot->id)
            ->firstOrFail();

        [$branchLine, $branchPipe, $branchPipePort] = $this->branchPipeForRoot($root);
        $header = $root->branchHeaderComponent;

        if ($header === null || $header->template_code !== 'PIPE'
            || $header->project_id !== $root->project_id
            || ! in_array($root->joint_type, [
                Joint::TYPE_BRANCH_STUB_IN,
                Joint::TYPE_BRANCH_STUB_ON,
            ], true)
            || $root->port_b_id !== null) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'A branch pad requires an existing direct P2P stub-in/stub-on wall root with an explicit header pipe.',
            ]);
        }

        $headerLine = PipingLine::query()->findOrFail($header->piping_line_id);
        $project = Project::query()->findOrFail($root->project_id);

        if ($branchLine->project_id !== $project->id || $headerLine->project_id !== $project->id
            || $branchPipe->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The branch root and its header/branch pipes must be in the same project.',
            ]);
        }

        $this->archives->assertWritable($project);
        $this->assertLineIsModellable($branchLine);
        $this->assertLineIsModellable($headerLine);

        return DB::transaction(function () use (
            $root, $header, $headerLine, $branchLine, $branchPipe, $branchPipePort, $actor, $project,
        ): BranchPad {
            $this->lockLines($branchLine, $headerLine);
            $lockedBranchLine = PipingLine::query()->whereKey($branchLine->id)->lockForUpdate()->firstOrFail();
            $lockedHeaderLine = PipingLine::query()->whereKey($headerLine->id)->lockForUpdate()->firstOrFail();

            $freshRoot = Joint::query()
                ->with(['portA.component.ports', 'branchHeaderComponent'])
                ->whereKey($root->id)
                ->lockForUpdate()
                ->firstOrFail();
            $freshHeader = Component::query()->whereKey($header->id)->lockForUpdate()->firstOrFail();
            [$actualBranchLine, $freshBranchPipeCandidate, $freshBranchPipePortCandidate] = $this->branchPipeForRoot($freshRoot);

            if ($actualBranchLine->id !== $lockedBranchLine->id
                || $freshBranchPipeCandidate->id !== $branchPipe->id
                || $freshBranchPipePortCandidate->id !== $branchPipePort->id) {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => 'The branch-side pipe changed while the pad was being prepared.',
                ]);
            }

            $freshBranchPipe = Component::query()->whereKey($freshBranchPipeCandidate->id)->lockForUpdate()->firstOrFail();
            $freshBranchPipePort = Port::query()->whereKey($freshBranchPipePortCandidate->id)->lockForUpdate()->firstOrFail();

            if (BranchPad::query()->where('branch_root_joint_id', $freshRoot->id)->exists()) {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => 'This branch wall root already has a reinforcement pad.',
                ]);
            }

            if ($freshRoot->branch_header_component_id !== $freshHeader->id
                || $freshRoot->port_b_id !== null
                || $freshHeader->template_code !== 'PIPE'
                || $freshBranchPipe->template_code !== 'PIPE'
                || $freshBranchPipe->piping_line_id !== $lockedBranchLine->id
                || $freshBranchPipePort->component_id !== $freshBranchPipe->id
                || $freshBranchPipePort->role !== 'RUN'
                || ! $freshBranchPipePort->is_connected
                || $freshRoot->port_a_id !== $freshBranchPipePort->id) {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => 'The direct P2P wall-root pipe relationship changed before pad installation.',
                ]);
            }

            $this->assertLineIsModellable($lockedBranchLine);
            $this->assertLineIsModellable($lockedHeaderLine);

            if ($freshHeader->piping_line_id !== $lockedHeaderLine->id) {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => 'The header pipe no longer belongs to the PMS owner line.',
                ]);
            }

            // IMPORTANT: the pad thickness is resolved from the HEADER line's
            // immutable issued class, not from the branch root's register owner.
            $rule = $this->resolveHeaderPadRule(
                $freshRoot,
                $freshHeader,
                $lockedHeaderLine,
                $freshBranchPipe,
            );
            $geometry = $this->resolvePadGeometry(
                $freshRoot,
                $freshHeader,
                $lockedHeaderLine,
                $lockedBranchLine,
                $freshBranchPipe,
                (float) $rule['pad_thickness'],
                $this->padMaterialFromBranchRule($rule),
            );

            $padComponent = $this->components->placeBranchPad($lockedBranchLine, $actor, [
                'nominal_size' => $freshBranchPipe->nominal_size,
                'port_sizes' => [
                    1 => $freshHeader->nominal_size,
                    2 => $freshBranchPipe->nominal_size,
                ],
                'source' => 'MANUAL',
            ]);

            $headerPadPort = $padComponent->ports->firstWhere('role', 'PAD_HEADER');
            $branchPadPort = $padComponent->ports->firstWhere('role', 'PAD_BRANCH');

            if ($padComponent->ports->count() !== 2 || $headerPadPort === null || $branchPadPort === null) {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => 'The branch-pad template must provide exactly one header and one branch interface.',
                ]);
            }

            // Preserve the actual pipe schedules on each distinct pad interface;
            // a pad component has no single meaningful schedule of its own.
            Port::query()->whereKey($headerPadPort->id)->update([
                'schedule' => $freshHeader->schedule,
                'end_type' => 'FA',
            ]);
            Port::query()->whereKey($branchPadPort->id)->update([
                'schedule' => $freshBranchPipe->schedule,
                'end_type' => 'FA',
            ]);
            $headerPadPort->refresh();
            $branchPadPort->refresh();

            $headerJointId = (string) Str::uuid();
            $branchJointId = (string) Str::uuid();
            $p2pKind = ($rule['component_type'] ?? null) === BranchConnectionType::P2P
                ? (string) ($rule['p2p_kind'] ?? '')
                : null;

            $pad = BranchPad::query()->forceCreate([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $actor->company_id,
                'component_id' => $padComponent->id,
                'branch_root_joint_id' => $freshRoot->id,
                'header_pipe_component_id' => $freshHeader->id,
                'branch_pipe_component_id' => $freshBranchPipe->id,
                'branch_pipe_port_id' => $freshBranchPipePort->id,
                'header_line_id' => $lockedHeaderLine->id,
                'pms_class_snapshot_id' => $lockedHeaderLine->pms_class_snapshot_id,
                'header_joint_id' => $headerJointId,
                'branch_joint_id' => $branchJointId,
                'branch_rule_component_type' => $rule['component_type'],
                'branch_rule_p2p_kind' => $p2pKind,
                'pad_thickness_mm' => (float) $rule['pad_thickness'],
                'pad_major_axis_mm' => $geometry['pad_major_axis_mm'],
                'pad_minor_axis_mm' => $geometry['pad_minor_axis_mm'],
                'pad_major_axis_direction' => $geometry['pad_major_axis_direction'],
                'pad_minor_axis_direction' => $geometry['pad_minor_axis_direction'],
                'pad_geometry_d2_mm' => $geometry['pad_geometry_d2_mm'],
                'pad_geometry_beta_deg' => $geometry['pad_geometry_beta_deg'],
                'pad_geometry_method' => $geometry['pad_geometry_method'],
                'pad_geometry_snapshot' => $geometry['pad_geometry_snapshot'],
                'created_by' => $actor->id,
            ]);

            $this->joints->joinBranchPadWall(
                $pad,
                $headerPadPort,
                $freshHeader,
                'HEADER',
                (float) $freshRoot->branch_station_mm,
                (float) $freshRoot->branch_offset_mm,
                $headerJointId,
                $actor,
            );

            $this->joints->joinBranchPadWall(
                $pad,
                $branchPadPort,
                $freshBranchPipe,
                'BRANCH',
                null,
                null,
                $branchJointId,
                $actor,
            );

            return $pad->fresh([
                'component.ports', 'branchRootJoint', 'headerPipe.line', 'branchPipe.line',
                'headerLine', 'pmsClassSnapshot', 'headerJoint.portA.component',
                'headerJoint.branchWallComponent', 'branchJoint.portA.component', 'branchJoint.branchWallComponent',
            ]);
        });
    }

    /** @return array{0: PipingLine, 1: Component, 2: Port} */
    private function branchPipeForRoot(Joint $root): array
    {
        $anchor = $root->portA?->component;

        if ($anchor === null) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The branch wall root has no physical branch-side component.',
            ]);
        }

        if (in_array($root->joint_type, [Joint::TYPE_BRANCH_STUB_IN, Joint::TYPE_BRANCH_STUB_ON], true)) {
            $port = $root->portA;
            if ($anchor->template_code !== 'PIPE' || $port === null || $port->role !== 'RUN') {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => 'A P2P branch pad requires the root to reference an actual branch-pipe end.',
                ]);
            }

            return [PipingLine::query()->findOrFail($anchor->piping_line_id), $anchor, $port];
        }

        throw ValidationException::withMessages([
            'branch_root_joint_id' => 'A reinforcement pad requires a direct P2P stub-in or stub-on root.',
        ]);
    }

    /**
     * Resolve the exact header-issued P2P rule and require its positive pad
     * thickness before it can be installed or offered in the model-page selector.
     *
     * @return array<string, mixed>
     */
    private function resolveHeaderPadRule(
        Joint $root,
        Component $header,
        PipingLine $headerLine,
        Component $branchPipe,
    ): array {
        $headerDn = $this->dn($header->nominal_size, 'header_pipe_component_id');
        $branchDn = $this->dn($branchPipe->nominal_size, 'branch_pipe_component_id');
        $specification = $this->issuedPms->forLine($headerLine);
        $rule = $this->issuedPms->branchRuleFromSpecification($specification, $headerDn, $branchDn);

        if (($rule['reinforcement'] ?? null) !== 'PAD'
            || ! isset($rule['pad_thickness'])
            || ! is_numeric($rule['pad_thickness'])
            || (float) $rule['pad_thickness'] <= 0) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The header line\'s issued PMS branch rule does not require a valid reinforcement pad for this size pair.',
            ]);
        }

        $this->assertHeaderRuleMatchesRoot($root, $rule);

        return $rule;
    }

    /** @param array<string,mixed> $rule @return array<string,mixed>|null */
    private function padMaterialFromBranchRule(array $rule): ?array
    {
        $value = $rule['value'] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The issued PMS branch-rule value must be an object when present.',
            ]);
        }
        if (! array_key_exists('pad_material', $value) || $value['pad_material'] === null) {
            return null;
        }
        if (! is_array($value['pad_material'])) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The issued PMS PAD material must be a material object with a spec and grade.',
            ]);
        }

        return $value['pad_material'];
    }

    /**
     * Resolve the sampled PAD outer perimeter and its two spans, area slice, and weld-throat
     * minima from the two physical pipe ODs/schedules, the nominal PAD rule
     * thickness, optional PMS PAD material (defaulting to the header's
     * issued PIPE_MATERIAL rule), the root's explicit beta, and both issued
     * corrosion allowances. Geometry method v1 is limited to a centred 90-degree root;
     * it never infers beta from station or offset.
     *
     * @return array<string, mixed>
     */
    private function resolvePadGeometry(
        Joint $root,
        Component $header,
        PipingLine $headerLine,
        PipingLine $branchLine,
        Component $branchPipe,
        float $padNominalThicknessMm,
        ?array $padMaterialSpecification = null,
    ): array {
        if ($root->branch_longitudinal_angle_deg === null) {
            throw ValidationException::withMessages([
                'branch_longitudinal_angle_deg' => 'A recorded P2P branch angle is required for saddle-pad geometry.',
            ]);
        }

        $betaDeg = (float) $root->branch_longitudinal_angle_deg;
        if (abs($betaDeg - BranchPadGeometryCalculator::SUPPORTED_BETA_DEG) > 1e-9) {
            throw ValidationException::withMessages([
                'branch_longitudinal_angle_deg' => 'The current nominal saddle-perimeter method is approved only for a recorded 90-degree branch angle.',
            ]);
        }

        if ($root->branch_offset_mm === null) {
            throw ValidationException::withMessages([
                'branch_offset_mm' => 'A centred-branch position is required for the current saddle-pad geometry calculation.',
            ]);
        }

        $offset = (float) $root->branch_offset_mm;
        if (abs($offset) > 1e-9) {
            throw ValidationException::withMessages([
                'branch_offset_mm' => 'Saddle-pad dimensions currently require a centred branch (offset 0 mm); a non-zero offset has no approved circumferential reference in the model.',
            ]);
        }

        $headerSize = $this->pipeSize($header, 'header_pipe_component_id');
        $branchSize = $this->pipeSize($branchPipe, 'branch_pipe_component_id');
        $headerWall = $this->wallData($header, $headerSize, 'header_pipe_component_id');
        $branchWall = $this->wallData($branchPipe, $branchSize, 'branch_pipe_component_id');

        $headerCa = $this->issuedCorrosionAllowance($headerLine, $headerSize->dn, 'header');
        $branchCa = $this->issuedCorrosionAllowance($branchLine, $branchSize->dn, 'branch');
        $governingCa = max($headerCa, $branchCa);

        $issuedSpecification = $this->issuedPms->forLine($headerLine);
        try {
            $headerMaterial = $this->issuedPms->ruleValueFromSpecification(
                $issuedSpecification,
                PipingRuleKind::PIPE_MATERIAL,
                $headerSize->dn,
            );
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The header line\'s issued PMS must define one PIPE_MATERIAL rule for the header DN to resolve PAD material.',
            ]);
        }
        $designTemperature = $issuedSpecification['class']['design_temperature_c'] ?? null;
        if (! is_numeric($designTemperature) || ! is_finite((float) $designTemperature)) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The header line\'s issued PMS design temperature is required to resolve the PAD allowable stress.',
            ]);
        }
        $designTemperature = (float) $designTemperature;
        $selectedPadMaterial = $padMaterialSpecification ?? $headerMaterial;
        $headerAllowableStress = $this->allowableStressForPmsMaterial($headerMaterial, $designTemperature, 'header');
        $padAllowableStress = $this->allowableStressForPmsMaterial($selectedPadMaterial, $designTemperature, 'PAD');
        $padStressRatio = min(1.0, $padAllowableStress / $headerAllowableStress);
        $padMaterialSource = $padMaterialSpecification === null
            ? 'HEADER_LINE_ISSUED_PMS_PIPE_MATERIAL_DEFAULT'
            : 'HEADER_LINE_ISSUED_PMS_BRANCH_RULE_VALUE';

        try {
            $calculated = BranchPadGeometryCalculator::forCenteredRightAngleSaddle(
                Dh: $headerSize->odMm,
                Th: $headerWall['effective_wall_mm'],
                Db: $branchSize->odMm,
                Tb: $branchWall['effective_wall_mm'],
                c: $governingCa,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'Saddle-pad geometry could not be resolved from the recorded pipe sizes, schedules and issued allowances: '.$exception->getMessage(),
            ]);
        }

        $millToleranceFactor = $this->materials->millToleranceFactor();
        $padMinimumThickness = $padNominalThicknessMm * $millToleranceFactor;
        $d2 = $calculated['d2_mm'];
        $padSpanAlongRun = $calculated['pad_minor_axis_mm'];
        $padWidthInZone = max(0.0, min(
            $padSpanAlongRun - $branchSize->odMm,
            2.0 * $d2 - $branchSize->odMm,
        ));
        $l4 = min(
            2.5 * ($headerWall['effective_wall_mm'] - $governingCa),
            2.5 * ($branchWall['effective_wall_mm'] - $governingCa) + $padMinimumThickness,
        );
        $padHeightInZone = min($padMinimumThickness, max(0.0, $l4));
        // Figure 304.3.3-1 note (2) proportions the physical A4 width to the
        // full minimum saddle thickness. L4 clips credited area only; it does
        // not reduce the physical height for this shape-ratio check.
        $padWidthToHeightRatio = $padMinimumThickness > 0.0
            ? $padWidthInZone / $padMinimumThickness
            : 0.0;

        if ($padHeightInZone <= 0.0 || $padWidthToHeightRatio < 1.0 - 1e-9) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => sprintf(
                    'The PAD A4 width-to-height ratio is %.3f:1; ASME B31.3-2024 Figure 304.3.3-1 note (2) prohibits a ratio below 1:1.',
                    $padWidthToHeightRatio,
                ),
            ]);
        }

        try {
            $welds = BranchPadWeldCalculator::minimums(
                branchNominalWallMm: $branchWall['nominal_wall_mm'],
                padNominalThicknessMm: $padNominalThicknessMm,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'PAD fillet-weld minima could not be resolved: '.$exception->getMessage(),
            ]);
        }

        $padMetalAreaBeforeStressRatio = $padWidthInZone * $padHeightInZone;
        $padAreaCredit = $padMetalAreaBeforeStressRatio * $padStressRatio;
        $rootWeldAreaCredit = $welds['root_branch_to_run']['a4_credit_mm2'];
        $padEdgeWeldAreaCredit = $welds['pad_to_header']['a4_credit_mm2'];
        $totalA4Credit = $padAreaCredit + $rootWeldAreaCredit + $padEdgeWeldAreaCredit;

        $round = static fn (float $value): float => round($value, 3);
        $major = $round($calculated['pad_major_axis_mm']);
        $minor = $round($calculated['pad_minor_axis_mm']);
        $d1 = $round($calculated['d1_mm']);
        $d2 = $round($calculated['d2_mm']);
        $extension = $round($calculated['zone_extension_each_side_mm']);
        $contactArc = $round($calculated['saddle_contact_arc_mm']);
        $headerOd = $round($headerSize->odMm);
        $branchOd = $round($branchSize->odMm);
        $headerEffectiveWall = $round($headerWall['effective_wall_mm']);
        $branchEffectiveWall = $round($branchWall['effective_wall_mm']);
        $governingCa = $round($governingCa);

        $contactProfileSamples = BranchPadSaddleContactProfileCalculator::sampledPoints(
            headerOdMm: $headerSize->odMm,
            branchOdMm: $branchSize->odMm,
        );
        $contactProfileSampleRows = array_map(
            static fn (array $point): array => [
                round($point['branch_profile_parameter_rad'], 9),
                round($point['header_axis_x_mm'], 6),
                round($point['header_circumferential_y_mm'], 6),
                round($point['header_radial_z_mm'], 6),
                round($point['header_circumferential_angle_rad'], 9),
                round($point['header_developed_arc_mm'], 6),
            ],
            $contactProfileSamples,
        );
        $contactProfileSnapshot = array_merge($calculated['saddle_contact_profile'], [
            'sample_segments' => BranchPadSaddleContactProfileCalculator::DEFAULT_SAMPLE_SEGMENTS,
            'sample_point_columns' => [
                'branch_profile_parameter_rad',
                'header_axis_x_mm',
                'header_circumferential_y_mm',
                'header_radial_z_mm',
                'header_circumferential_angle_rad',
                'header_developed_arc_mm',
            ],
            'sampled_points_mm' => $contactProfileSampleRows,
            'sample_points_are_visualization_aid_only' => true,
        ]);

        $outerPerimeter = $calculated['pad_outer_perimeter_profile'];
        $outerPerimeterSampleRows = array_map(
            static fn (array $point): array => [round($point[0], 6), round($point[1], 6)],
            $outerPerimeter['sampled_points_mm'],
        );
        $outerPerimeterSnapshot = array_merge($outerPerimeter, [
            'edge_extension_mm' => round($outerPerimeter['edge_extension_mm'], 6),
            'header_axis_half_extent_mm' => round($outerPerimeter['header_axis_half_extent_mm'], 6),
            'developed_circumferential_half_extent_mm' => round($outerPerimeter['developed_circumferential_half_extent_mm'], 6),
            'span_along_header_mm' => round($outerPerimeter['span_along_header_mm'], 6),
            'developed_circumferential_span_mm' => round($outerPerimeter['developed_circumferential_span_mm'], 6),
            'sampled_points_mm' => $outerPerimeterSampleRows,
        ]);

        $snapshot = [
            'method' => $calculated['method'],
            'inputs' => [
                'header_nps' => $headerSize->nps,
                'header_od_mm' => $headerOd,
                'header_schedule' => $headerWall['schedule'],
                'header_effective_wall_mm' => $headerEffectiveWall,
                'header_corrosion_allowance_mm' => $round($headerCa),
                'branch_nps' => $branchSize->nps,
                'branch_od_mm' => $branchOd,
                'branch_schedule' => $branchWall['schedule'],
                'branch_effective_wall_mm' => $branchEffectiveWall,
                'branch_corrosion_allowance_mm' => $round($branchCa),
                'mill_tolerance_factor' => $millToleranceFactor,
                'governing_corrosion_allowance_mm' => $governingCa,
                'pad_thickness_basis' => 'NOMINAL_PIPE_SADDLE_THICKNESS',
                'pad_nominal_thickness_mm' => $round($padNominalThicknessMm),
                'pad_minimum_thickness_for_area_mm' => $round($padMinimumThickness),
                'pad_mill_tolerance_factor' => $millToleranceFactor,
            ],
            'materials' => [
                'header_pipe' => [
                    'source' => 'HEADER_LINE_ISSUED_PMS_PIPE_MATERIAL_RULE',
                    'dn' => $headerSize->dn,
                    'identity' => $this->materialIdentity($headerMaterial),
                    'allowable_stress_mpa_at_design_temperature' => $round($headerAllowableStress),
                ],
                'pad' => [
                    'source' => $padMaterialSource,
                    'defaulted_from_header' => $padMaterialSpecification === null,
                    'identity' => $this->materialIdentity($selectedPadMaterial),
                    'design_temperature_c' => $round($designTemperature),
                    'allowable_stress_mpa_at_design_temperature' => $round($padAllowableStress),
                    'allowable_stress_ratio_to_run' => round($padStressRatio, 9),
                ],
            ],
            'assumptions' => [
                'branch_angle_deg' => $betaDeg,
                'branch_angle_source' => 'JOINT_BRANCH_LONGITUDINAL_ANGLE_DEG',
                'branch_offset_mm' => $round($offset),
                'branch_centered' => true,
                'circumferential_clocking_theta_used' => false,
                'component_rotation_used' => false,
                'fit_up_clearance_mm' => 0.0,
                'fabrication_tolerance_mm' => 0.0,
            ],
            'reinforcement_zone' => [
                'd1_mm' => $d1,
                'd2_mm' => $d2,
                'extension_each_side_mm' => $extension,
                'l4_mm' => $round($l4),
            ],
            'a4_area_check' => [
                'pad_span_along_run_mm' => $round($padSpanAlongRun),
                'pad_width_within_zone_mm' => $round($padWidthInZone),
                'pad_height_within_zone_mm' => $round($padHeightInZone),
                'pad_thickness_for_width_height_ratio_mm' => $round($padMinimumThickness),
                'width_to_height_ratio_basis' => 'PAD_WIDTH_WITHIN_REINFORCEMENT_ZONE / FULL_MINIMUM_PAD_THICKNESS',
                'width_to_height_ratio' => $round($padWidthToHeightRatio),
                'minimum_width_to_height_ratio' => 1.0,
                'preferred_width_to_height_ratio' => 4.0,
                'preferred_ratio_is_advisory' => true,
                'pad_metal_area_mm2_before_stress_ratio' => $round($padMetalAreaBeforeStressRatio),
                'pad_allowable_stress_ratio_to_run' => round($padStressRatio, 9),
                'pad_metal_area_mm2_credited' => $round($padAreaCredit),
                'root_weld_area_credit_mm2' => $round($rootWeldAreaCredit),
                'pad_edge_weld_area_credit_mm2' => $round($padEdgeWeldAreaCredit),
                'total_pad_and_credited_weld_area_mm2' => $round($totalA4Credit),
                'standard_reference' => 'ASME B31.3-2024 §304.3.3, Figure 304.3.3-1 notes (1)-(3)',
            ],
            'pad_weld_requirements' => [
                'basis' => 'Minimum design throat only; actual as-welded size is not recorded or verified.',
                'root_branch_to_run' => [
                    'reference' => $welds['root_branch_to_run']['reference'],
                    'minimum_throat_mm' => $round($welds['root_branch_to_run']['minimum_throat_mm']),
                    'equal_leg_equivalent_mm' => $round($welds['root_branch_to_run']['equal_leg_equivalent_mm']),
                    'a4_credit_mm2' => $round($welds['root_branch_to_run']['a4_credit_mm2']),
                    'credited_in_area_balance' => true,
                ],
                'pad_to_header' => [
                    'joint_side' => 'HEADER',
                    'reference' => $welds['pad_to_header']['reference'],
                    'minimum_throat_mm' => $round($welds['pad_to_header']['minimum_throat_mm']),
                    'equal_leg_equivalent_mm' => $round($welds['pad_to_header']['equal_leg_equivalent_mm']),
                    'a4_credit_mm2' => $round($welds['pad_to_header']['a4_credit_mm2']),
                    'credited_in_area_balance' => true,
                ],
                'pad_to_branch' => [
                    'joint_side' => 'BRANCH',
                    'reference' => $welds['pad_to_branch']['reference'],
                    'minimum_throat_mm' => $round($welds['pad_to_branch']['minimum_throat_mm']),
                    'equal_leg_equivalent_mm' => $round($welds['pad_to_branch']['equal_leg_equivalent_mm']),
                    'a4_credit_mm2' => $round($welds['pad_to_branch']['a4_credit_mm2']),
                    'credited_in_area_balance' => $welds['pad_to_branch']['credited_in_area_balance'],
                ],
            ],
            'saddle' => [
                'contact_arc_mm' => $contactArc,
                'contact_profile' => $contactProfileSnapshot,
                'outer_perimeter_profile' => $outerPerimeterSnapshot,
            ],
            'axes' => [
                'major_mm' => $major,
                'major_direction' => $calculated['pad_major_axis_direction'],
                'minor_mm' => $minor,
                'minor_direction' => $calculated['pad_minor_axis_direction'],
            ],
        ];

        return [
            'pad_major_axis_mm' => $major,
            'pad_minor_axis_mm' => $minor,
            'pad_major_axis_direction' => $calculated['pad_major_axis_direction'],
            'pad_minor_axis_direction' => $calculated['pad_minor_axis_direction'],
            'pad_geometry_d2_mm' => $d2,
            'pad_geometry_beta_deg' => $betaDeg,
            'pad_geometry_method' => $calculated['method'],
            'pad_geometry_snapshot' => $snapshot,
        ];
    }

    private function pipeSize(Component $pipe, string $field): PipeSize
    {
        if ($pipe->nominal_size === null) {
            throw ValidationException::withMessages([$field => 'Both header and branch nominal sizes are required for saddle geometry.']);
        }

        try {
            return $this->reference->size($pipe->nominal_size);
        } catch (UnknownSizeException) {
            throw ValidationException::withMessages([$field => "The pipe size '{$pipe->nominal_size}' is not in the reference pack."]);
        }
    }

    /** @return array{schedule:string,nominal_wall_mm:float,effective_wall_mm:float,mill_tolerance_factor:float} */
    private function wallData(Component $pipe, PipeSize $size, string $field): array
    {
        $schedule = is_string($pipe->schedule) ? trim($pipe->schedule) : '';
        if ($schedule === '') {
            throw ValidationException::withMessages([$field => 'An actual pipe schedule is required to calculate the reinforcement-zone boundary d2.']);
        }

        try {
            $nominalWall = $this->reference->wall($size->nps, $schedule);
        } catch (UnknownScheduleException) {
            throw ValidationException::withMessages([$field => "Schedule '{$schedule}' is not published for NPS {$size->nps}."]);
        }

        $millToleranceFactor = $this->materials->millToleranceFactor();

        return [
            'schedule' => $schedule,
            'nominal_wall_mm' => $nominalWall,
            'effective_wall_mm' => $nominalWall * $millToleranceFactor,
            'mill_tolerance_factor' => $millToleranceFactor,
        ];
    }

    private function issuedCorrosionAllowance(PipingLine $line, int $dn, string $side): float
    {
        try {
            $value = $this->issuedPms->ruleValue($line, PipingRuleKind::CORROSION_ALLOWANCE, $dn);
        } catch (ValidationException) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => "The {$side} line's issued PMS must define one corrosion allowance for the saddle-zone calculation.",
            ]);
        }

        $ca = $value['ca_mm'] ?? null;
        if (! is_numeric($ca) || (float) $ca < 0.0) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => "The {$side} line's issued PMS corrosion allowance is invalid for saddle geometry.",
            ]);
        }

        return (float) $ca;
    }

    /** @param array<string,mixed> $material */
    private function allowableStressForPmsMaterial(array $material, float $temperatureC, string $side): float
    {
        $spec = $material['spec'] ?? null;
        $grade = $material['grade'] ?? null;
        $psl = $material['psl'] ?? null;
        if (! is_string($spec) || trim($spec) === ''
            || ! is_string($grade) || trim($grade) === ''
            || ($psl !== null && (! is_string($psl) || trim($psl) === ''))) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => "The issued PMS {$side} material must identify a standard specification, grade and valid optional PSL.",
            ]);
        }

        $override = $material['s_mpa_override'] ?? null;
        if ($override !== null) {
            if (! is_numeric($override) || ! is_finite((float) $override) || (float) $override <= 0.0) {
                throw ValidationException::withMessages([
                    'branch_root_joint_id' => "The issued PMS {$side} material allowable-stress override is invalid.",
                ]);
            }

            return (float) $override;
        }

        try {
            return $this->materials->allowableStress($spec, $grade, $temperatureC, $psl);
        } catch (UnknownMaterialException $exception) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => "The issued PMS {$side} material cannot be resolved at the design temperature: ".$exception->getMessage(),
            ]);
        }
    }

    /** @param array<string,mixed> $material @return array<string,mixed> */
    private function materialIdentity(array $material): array
    {
        return [
            'spec' => is_string($material['spec'] ?? null) ? $material['spec'] : null,
            'grade' => is_string($material['grade'] ?? null) ? $material['grade'] : null,
            'psl' => is_string($material['psl'] ?? null) ? $material['psl'] : null,
            'pipe_type' => is_string($material['pipe_type'] ?? null) ? $material['pipe_type'] : null,
            's_mpa_override' => isset($material['s_mpa_override']) && is_numeric($material['s_mpa_override'])
                ? (float) $material['s_mpa_override']
                : null,
            'p_no' => is_string($material['p_no'] ?? null) ? $material['p_no'] : null,
            'group_no' => isset($material['group_no']) && is_numeric($material['group_no'])
                ? (string) $material['group_no']
                : null,
        ];
    }

    /** @param array<string, mixed> $rule */
    private function assertHeaderRuleMatchesRoot(Joint $root, array $rule): void
    {
        $expectedKind = match ($root->joint_type) {
            Joint::TYPE_BRANCH_STUB_IN => P2pKind::STUB_IN,
            Joint::TYPE_BRANCH_STUB_ON => P2pKind::STUB_ON,
            default => null,
        };

        if ($expectedKind === null
            || ($rule['component_type'] ?? null) !== BranchConnectionType::P2P
            || ($rule['p2p_kind'] ?? null) !== $expectedKind
            || $root->branch_kind !== $expectedKind) {
            throw ValidationException::withMessages([
                'branch_root_joint_id' => 'The header PMS rule must match the physical P2P stub-in/stub-on wall root.',
            ]);
        }
    }

    private function dn(?string $nps, string $field): int
    {
        if ($nps === null) {
            throw ValidationException::withMessages([$field => 'Both header and branch pipe sizes are required for a PMS pad lookup.']);
        }

        try {
            return $this->reference->size($nps)->dn;
        } catch (UnknownSizeException) {
            throw ValidationException::withMessages([$field => "The pipe size '{$nps}' is not in the reference pack."]);
        }
    }

    private function lockLines(PipingLine ...$lines): void
    {
        $ids = array_values(array_unique(array_map(
            static fn (PipingLine $line): string => $line->id,
            $lines,
        )));
        sort($ids, SORT_STRING);

        foreach ($ids as $id) {
            PipingLine::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        }
    }

    private function assertLineIsModellable(PipingLine $line): void
    {
        if ($line->release_state === LineReleaseState::RELEASED) {
            throw ValidationException::withMessages([
                'release_state' => 'A released line cannot be changed; use the revision workflow.',
            ]);
        }
    }
}
