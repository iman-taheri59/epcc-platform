<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\ComponentPoseRevision;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\ProjectModelFrameRevision;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\LineReleaseState;
use App\Support\Reference\FittingDimensions;
use App\Support\Reference\ForgedFittingElbowDimensions;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Append-only manual pose entry and sourced component-port geometry resolution. */
final class ComponentPoseService
{
    public function __construct(
        private readonly ProjectArchiveService $archives,
        private readonly ProjectModelFrameService $frames,
        private readonly FittingDimensions $fittingDimensions,
        private readonly ForgedFittingElbowDimensions $forgedElbowDimensions,
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
    ) {}

    /**
     * Record a new manual pose revision for a supported local geometry profile.
     * Missing geometry inputs are saved UNRESOLVED rather than used to invent
     * port locations or directions.
     *
     * @param  array<int, int|float>  $anchorModel  Easting, Northing, elevation in MM
     */
    public function recordManual(
        Project $project,
        PipingLine $line,
        Component $component,
        ProjectModelFrameRevision $frame,
        User $actor,
        float $azimuthDeg,
        float $inclinationDeg,
        float $rollDeg,
        array $anchorModel,
        ?array $inputGeometry = null,
    ): ComponentPoseRevision {
        $inputGeometry ??= [
            'input_mode' => 'AZIMUTH_INCLINATION_ROLL',
            'adapter_method' => ManualComponentOrientation3D::METHOD,
            'angle_unit' => 'DEG',
            'azimuth_deg' => $azimuthDeg,
            'inclination_deg' => $inclinationDeg,
            'roll_deg' => $rollDeg,
        ];

        $this->frames->assertProjectAccess($project, $actor);

        if ($line->project_id !== $project->id
            || $component->project_id !== $project->id
            || $component->piping_line_id !== $line->id
            || $frame->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'component_id' => __('epcc.component.pose_scope_mismatch'),
            ]);
        }
        if (! $this->supportsGeometry($component)) {
            throw ValidationException::withMessages([
                'component_id' => __('epcc.component.pose_profile_unsupported'),
            ]);
        }

        try {
            $orientation = ManualComponentOrientation3D::resolveDeg(
                $azimuthDeg,
                $inclinationDeg,
                $rollDeg,
            );
            // Validate coordinates before opening the write transaction.
            new FramedRigidTransform3D(
                sourceFrameId: 'component:input-validation',
                targetFrameId: $frame->frameIdentifier(),
                coordinateUnit: ProjectModelFrameRevision::COORDINATE_UNIT,
                rotationSourceToTarget: $orientation['rotation_local_to_model'],
                translationTarget: $anchorModel,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'orientation' => __('epcc.component.pose_invalid_input', ['reason' => $exception->getMessage()]),
            ]);
        }

        return $this->tenant->runAs($actor->company_id, function () use (
            $project,
            $line,
            $component,
            $frame,
            $actor,
            $azimuthDeg,
            $inclinationDeg,
            $rollDeg,
            $anchorModel,
            $orientation,
            $inputGeometry,
        ): ComponentPoseRevision {
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->archives->assertWritable($lockedProject);
            // Recheck membership and modelling capability inside the write
            // transaction; stale caller state or a revoked role must not
            // authorize a pose write.
            $this->frames->assertProjectAccess($lockedProject, $actor);
            abort_unless($this->authz->allows($actor, 'equipment.maintain', $lockedProject), 403);

            $lockedLine = PipingLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($lockedLine->project_id !== $lockedProject->id
                || ! LineReleaseState::isEditable((string) $lockedLine->release_state)) {
                throw ValidationException::withMessages([
                    'release_state' => __('epcc.component.pose_requires_draft'),
                ]);
            }

            $lockedComponent = Component::query()
                ->with('ports')
                ->lockForUpdate()
                ->findOrFail($component->id);
            if ($lockedComponent->project_id !== $lockedProject->id
                || $lockedComponent->piping_line_id !== $lockedLine->id) {
                throw ValidationException::withMessages([
                    'component_id' => __('epcc.component.pose_scope_mismatch'),
                ]);
            }
            if (! $this->supportsGeometry($lockedComponent)) {
                throw ValidationException::withMessages([
                    'component_id' => __('epcc.component.pose_profile_unsupported'),
                ]);
            }

            $lockedFrame = ProjectModelFrameRevision::query()->findOrFail($frame->id);
            $latestFrame = $this->frames->latestForProject($lockedProject, $actor);
            if ($lockedFrame->project_id !== $lockedProject->id
                || $latestFrame?->id !== $lockedFrame->id) {
                throw ValidationException::withMessages([
                    'model_frame_revision_id' => __('epcc.component.pose_frame_changed'),
                ]);
            }

            try {
                $profile = $this->resolveGeometryProfile($lockedComponent);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'length_mm' => __('epcc.component.pose_profile_invalid', ['reason' => $exception->getMessage()]),
                ]);
            }
            $this->assertProfilePortShape($lockedComponent);
            $profileHash = self::profileHash($profile);
            $localFrameId = sprintf(
                'component:%s:%s:r%s',
                $lockedComponent->id,
                $profile['profile_id'],
                $profile['profile_revision'],
            );

            try {
                $pose = ManualComponentOrientation3D::framedTransform(
                    localFrameId: $localFrameId,
                    modelFrameId: $lockedFrame->frameIdentifier(),
                    coordinateUnit: ProjectModelFrameRevision::COORDINATE_UNIT,
                    anchorModel: $anchorModel,
                    azimuthDeg: $azimuthDeg,
                    inclinationDeg: $inclinationDeg,
                    rollDeg: $rollDeg,
                );
                $resolvedGeometry = ComponentPortGeometryTransformer::transform($profile, $pose);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    'orientation' => __('epcc.component.pose_invalid_input', ['reason' => $exception->getMessage()]),
                ]);
            }

            $revision = (int) (ComponentPoseRevision::query()
                ->where('component_id', $lockedComponent->id)
                ->max('revision') ?? 0) + 1;

            return ComponentPoseRevision::query()->create([
                'project_id' => $lockedProject->id,
                'company_id' => $actor->company_id,
                'piping_line_id' => $lockedLine->id,
                'component_id' => $lockedComponent->id,
                'model_frame_revision_id' => $lockedFrame->id,
                'revision' => $revision,
                'local_frame_id' => $localFrameId,
                'orientation_method' => ManualComponentOrientation3D::METHOD,
                'input_azimuth_deg' => $azimuthDeg,
                'input_inclination_deg' => $inclinationDeg,
                'input_roll_deg' => $rollDeg,
                'resolved_angles_deg' => $orientation['angles_deg'],
                'input_geometry' => $inputGeometry,
                'anchor_easting_mm' => (float) $anchorModel[0],
                'anchor_northing_mm' => (float) $anchorModel[1],
                'anchor_elevation_mm' => (float) $anchorModel[2],
                'coordinate_unit' => ProjectModelFrameRevision::COORDINATE_UNIT,
                'rotation_local_to_model' => $orientation['rotation_local_to_model'],
                'geometry_profile_id' => $profile['profile_id'],
                'geometry_profile_revision' => $profile['profile_revision'],
                'geometry_profile_hash' => $profileHash,
                'resolved_port_geometry' => $resolvedGeometry,
                'geometry_status' => $resolvedGeometry['status'],
                'unresolved_reason' => $resolvedGeometry['reason'] ?? null,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        }, $project->id);
    }

    /**
     * Record a pose from explicitly dimensioned signed run/rise coordinates.
     * The adapter's raw inputs and optional closure residual are preserved in
     * `input_geometry`; the common pose fields store the resolved angles.
     *
     * @param  array<int, int|float>  $anchorModel  Easting, Northing, elevation in MM
     */
    public function recordDimensionedTriangle(
        Project $project,
        PipingLine $line,
        Component $component,
        ProjectModelFrameRevision $frame,
        User $actor,
        float $azimuthDeg,
        float $rollDeg,
        float $runMm,
        float $riseMm,
        ?float $measuredHypotenuseMm,
        array $anchorModel,
    ): ComponentPoseRevision {
        try {
            $triangle = DimensionedTriangleOrientation3D::resolveDeg(
                $azimuthDeg,
                $rollDeg,
                $runMm,
                $riseMm,
                $measuredHypotenuseMm,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'run_mm' => __('epcc.component.pose_triangle_invalid', ['reason' => $exception->getMessage()]),
            ]);
        }

        return $this->recordManual(
            $project,
            $line,
            $component,
            $frame,
            $actor,
            $triangle['azimuth_deg'],
            $triangle['inclination_deg'],
            $triangle['roll_deg'],
            $anchorModel,
            $triangle['input_geometry'],
        );
    }

    /**
     * Return only geometry whose frame and profile still match current sources.
     * A changed source is marked STALE; no old coordinates are presented as
     * current and no engineering tolerance is applied.
     *
     * @return array<string, mixed>
     */
    public function currentGeometry(Project $project, User $actor, Component $component): array
    {
        $geometries = $this->currentGeometries($project, $actor, [$component]);
        abort_unless(array_key_exists($component->id, $geometries), 404);

        return $geometries[$component->id];
    }

    /**
     * Resolve a group of component geometries against one current frame read.
     * This avoids an N+1 frame/pose query pattern on the line-model screen.
     *
     * @param  iterable<Component>  $components
     * @return array<string, array<string, mixed>> keyed by component UUID
     */
    public function currentGeometries(Project $project, User $actor, iterable $components): array
    {
        $this->frames->assertProjectAccess($project, $actor);

        $componentIds = [];
        foreach ($components as $component) {
            if (! $component instanceof Component) {
                throw new InvalidArgumentException('Every geometry lookup item must be a Component.');
            }
            abort_unless($component->project_id === $project->id, 404);
            $componentIds[] = $component->id;
        }
        $componentIds = array_values(array_unique($componentIds));

        if ($componentIds === []) {
            return [];
        }

        return $this->tenant->runAs($actor->company_id, function () use (
            $project,
            $actor,
            $componentIds,
        ): array {
            $currentComponents = Component::query()
                ->with(['latestPoseRevision', 'ports'])
                ->where('project_id', $project->id)
                ->whereIn('id', $componentIds)
                ->get()
                ->keyBy('id');
            $frame = $this->frames->latestForProject($project, $actor);
            $geometries = [];

            foreach ($componentIds as $componentId) {
                $component = $currentComponents->get($componentId);
                if ($component instanceof Component) {
                    $sourceFingerprint = self::geometrySourceFingerprint($component, $frame);
                    $geometries[$componentId] = [
                        'geometry_source_fingerprint' => $sourceFingerprint,
                    ] + $this->resolveCurrentGeometry($component, $frame);
                }
            }

            return $geometries;
        }, $project->id);
    }

    /** @param array<string,mixed> $profile */
    private static function profileHash(array $profile): string
    {
        return hash('sha256', json_encode($profile, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Fingerprint every current source that can change a supported component
     * endpoint result (including elbow qualifier/reference geometry), its latest
     * pose, and the current project frame.
     */
    private static function geometrySourceFingerprint(
        Component $component,
        ?ProjectModelFrameRevision $currentFrame,
    ): string {
        $pose = $component->relationLoaded('latestPoseRevision')
            ? $component->getRelation('latestPoseRevision')
            : $component->latestPoseRevision()->first();
        $ports = $component->relationLoaded('ports')
            ? $component->getRelation('ports')
            : $component->ports()->get();

        $source = [
            'source_contract' => 'COMPONENT_POSE_GEOMETRY_SOURCE_V1',
            'component_id' => $component->id,
            'project_id' => $component->project_id,
            'company_id' => $component->company_id,
            'piping_line_id' => $component->piping_line_id,
            'sequence' => (int) $component->sequence,
            'template_code' => $component->template_code,
            'fitting_qualifier' => $component->fitting_qualifier,
            'fitting_standard' => $component->fitting_standard,
            'fitting_class_designation' => $component->fitting_class_designation,
            'nominal_size' => $component->nominal_size,
            'schedule' => $component->schedule,
            'length_mm' => $component->length_mm === null ? null : (float) $component->length_mm,
            'ports' => $ports->sortBy('seq')->values()->map(static fn ($port): array => [
                'id' => $port->id,
                'owner_kind' => $port->owner_kind,
                'component_id' => $port->component_id,
                'seq' => (int) $port->seq,
                'role' => (string) $port->role,
                'nominal_size' => $port->nominal_size,
                'schedule' => $port->schedule,
                'end_type' => $port->end_type,
            ])->all(),
            'current_frame_revision_id' => $currentFrame?->id,
            'pose_revision_id' => $pose?->id,
            'pose_revision' => $pose?->revision,
            'pose_frame_revision_id' => $pose?->model_frame_revision_id,
            'pose_geometry_profile_hash' => $pose?->geometry_profile_hash,
        ];

        return hash('sha256', json_encode($source, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string, mixed> */
    private function resolveCurrentGeometry(
        Component $component,
        ?ProjectModelFrameRevision $currentFrame,
    ): array {
        $pose = $component->relationLoaded('latestPoseRevision')
            ? $component->getRelation('latestPoseRevision')
            : $component->latestPoseRevision()->first();

        if (! $pose instanceof ComponentPoseRevision) {
            return ['status' => 'UNRESOLVED', 'reason' => 'NO_POSE', 'ports' => []];
        }

        if ($currentFrame === null || $pose->model_frame_revision_id !== $currentFrame->id) {
            return [
                'status' => 'STALE',
                'reason' => 'FRAME_REVISION_CHANGED',
                'pose_revision' => $pose->revision,
                'ports' => [],
            ];
        }

        if (! $this->supportsGeometry($component)) {
            return [
                'status' => 'STALE',
                'reason' => 'PROFILE_NO_LONGER_SUPPORTED',
                'pose_revision' => $pose->revision,
                'ports' => [],
            ];
        }

        if (! $this->profilePortShapeMatches($component)) {
            return [
                'status' => 'STALE',
                'reason' => 'PROFILE_PORT_SHAPE_CHANGED',
                'pose_revision' => $pose->revision,
                'ports' => [],
            ];
        }

        try {
            $profile = $this->resolveGeometryProfile($component);
        } catch (InvalidArgumentException) {
            return [
                'status' => 'STALE',
                'reason' => 'GEOMETRY_PROFILE_INVALID',
                'pose_revision' => $pose->revision,
                'ports' => [],
            ];
        }
        if (self::profileHash($profile) !== $pose->geometry_profile_hash) {
            return [
                'status' => 'STALE',
                'reason' => 'GEOMETRY_PROFILE_CHANGED',
                'pose_revision' => $pose->revision,
                'ports' => [],
            ];
        }

        return ['pose_revision' => $pose->revision] + $pose->resolved_port_geometry;
    }

    private function supportsGeometry(Component $component): bool
    {
        return in_array($component->template_code, [
            ...LinearTwoPortComponentGeometryProfile::SUPPORTED_TEMPLATES,
            'ELBOW',
        ], true);
    }

    /** @return array<string, mixed> */
    private function resolveGeometryProfile(Component $component): array
    {
        return match ($component->template_code) {
            'PIPE', 'NIPPLE' => LinearTwoPortComponentGeometryProfile::resolve(
                $component->template_code,
                $component->length_mm,
            ),
            'ELBOW' => $this->resolveElbowGeometryProfile($component),
            default => throw new InvalidArgumentException('No sourced component geometry profile is defined.'),
        };
    }

    /** @return array<string,mixed> */
    private function resolveElbowGeometryProfile(Component $component): array
    {
        if ($component->fitting_standard === 'B16.11') {
            $ports = $component->relationLoaded('ports') ? $component->getRelation('ports') : $component->ports()->get();
            $endTypes = $ports->map(static function ($port): ?string {
                $end = strtoupper(trim((string) $port->end_type));

                return $end === '' ? null : $end;
            })->unique()->values();
            $endType = $endTypes->count() === 1 ? $endTypes->first() : null;

            return ForgedFittingElbowGeometryProfile::resolve(
                $endType,
                $component->fitting_class_designation,
                $component->fitting_qualifier,
                $component->nominal_size,
                $this->forgedElbowDimensions,
            );
        }

        if ($component->fitting_standard !== null && $component->fitting_standard !== 'B16.9') {
            throw new InvalidArgumentException('The selected elbow geometry standard is not supported.');
        }

        return ButtWeldElbowGeometryProfile::resolve(
            $component->fitting_qualifier,
            $component->nominal_size,
            $this->fittingDimensions,
        );
    }

    private function assertProfilePortShape(Component $component): void
    {
        if (! $this->profilePortShapeMatches($component)) {
            throw ValidationException::withMessages([
                'component_id' => __('epcc.component.pose_profile_port_mismatch'),
            ]);
        }
    }

    private function profilePortShapeMatches(Component $component): bool
    {
        $ports = $component->ports->sortBy('seq')->values();
        $actual = $ports->map(fn ($port): array => [
            'seq' => (int) $port->seq,
            'role' => (string) $port->role,
        ])->all();

        if ($actual !== [
            ['seq' => 1, 'role' => 'RUN'],
            ['seq' => 2, 'role' => 'RUN'],
        ]) {
            return false;
        }
        if ($component->template_code !== 'ELBOW') {
            return true;
        }

        if ($component->fitting_standard === 'B16.11') {
            $ends = $ports->map(fn ($port): string => strtoupper(trim((string) $port->end_type)))
                ->unique()
                ->values();

            return in_array($ends->count() === 1 ? $ends->first() : null, ['SW', 'SC'], true);
        }

        if ($component->fitting_standard !== null && $component->fitting_standard !== 'B16.9') {
            return false;
        }

        return $ports->every(fn ($port): bool => strtoupper((string) $port->end_type) === 'BW');
    }
}
