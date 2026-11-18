<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Joint;
use App\Models\JointGeometryDiagnosticRevision;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\LineReleaseState;
use App\Support\TenantContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Capture and read immutable source-versioned joint-geometry snapshots. */
final class JointGeometryDiagnosticRevisionService
{
    public function __construct(
        private readonly JointPortGeometryDiagnosticService $diagnostics,
        private readonly ComponentPoseService $poses,
        private readonly ProjectModelFrameService $frames,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Append one diagnostic revision per changed joint. Repeating a capture
     * against the same method and exact source fingerprint is idempotent.
     * The current line scope and all source geometry are read again only after
     * the project/line locks are held, so a capture cannot persist a result
     * calculated from a pose or joint state that changed while the request
     * was waiting to enter its write transaction.
     *
     * @return array<string, JointGeometryDiagnosticRevision> keyed by joint UUID
     */
    public function capture(Project $project, PipingLine $viewedLine, User $actor): array
    {
        if ($viewedLine->project_id !== $project->id) {
            abort(404);
        }

        $this->frames->assertProjectAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, function () use (
            $project,
            $viewedLine,
            $actor,
        ): array {
            // Pose and frame writes lock the project row. The line lock also
            // makes this DRAFT-only action fail closed if the line was
            // released while the user was on the model page.
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            $this->frames->assertProjectAccess($lockedProject, $actor);
            $this->archives->assertWritable($lockedProject);
            abort_unless($this->authz->allows($actor, 'equipment.maintain', $lockedProject), 403);

            $lockedLine = PipingLine::query()
                ->where('project_id', $lockedProject->id)
                ->whereKey($viewedLine->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! LineReleaseState::isEditable((string) $lockedLine->release_state)) {
                throw ValidationException::withMessages([
                    'release_state' => __('epcc.component.pose_requires_draft'),
                ]);
            }

            // Re-select the exact capture scope after taking its line lock.
            // This avoids trusting a stale page query and locks each joint
            // while its append-only revision is allocated.
            $joints = Joint::query()
                ->with([
                    'portA.component', 'portB.component',
                    'branchHeaderComponent', 'branchWallComponent',
                ])
                ->where('project_id', $lockedProject->id)
                ->where(function ($query) use ($lockedLine): void {
                    $query->where('piping_line_id', $lockedLine->id)
                        ->orWhereHas('branchPad.component', fn ($componentQuery) => $componentQuery
                            ->where('piping_line_id', $lockedLine->id));
                })
                ->orderByRaw('joint_seq NULLS LAST')
                ->lockForUpdate()
                ->get();

            $geometryComponents = $joints->flatMap(fn (Joint $joint) => [
                $joint->portA?->component,
                $joint->portB?->component,
                $joint->branchHeaderComponent,
                $joint->branchWallComponent,
            ])->filter()->unique('id')->values();
            $geometryByComponentId = $this->poses->currentGeometries(
                $lockedProject,
                $actor,
                $geometryComponents,
            );
            $diagnosticById = $this->diagnostics->forJoints($joints, $geometryByComponentId);

            $saved = [];
            foreach ($joints as $lockedJoint) {
                $jointId = $lockedJoint->id;
                $diagnostic = $diagnosticById[$jointId] ?? null;
                if (! is_array($diagnostic)
                    || ! is_string($diagnostic['source_fingerprint'] ?? null)
                    || ! preg_match('/^[0-9a-f]{64}$/D', $diagnostic['source_fingerprint'])
                    || ! is_array($diagnostic['input_snapshot'] ?? null)) {
                    throw new InvalidArgumentException('The diagnostic is missing its versioned source snapshot.');
                }

                $latest = JointGeometryDiagnosticRevision::query()
                    ->where('project_id', $lockedProject->id)
                    ->where('joint_id', $jointId)
                    ->orderByDesc('revision')
                    ->first();

                if ($latest !== null
                    && $latest->method_code === JointPortGeometryDiagnosticService::METHOD
                    && hash_equals($latest->source_fingerprint, $diagnostic['source_fingerprint'])) {
                    $saved[$jointId] = $latest;

                    continue;
                }

                $status = $diagnostic['status'] ?? null;
                $reason = $diagnostic['reason'] ?? null;
                if (! in_array($status, ['UNASSESSED', 'UNRESOLVED', 'STALE'], true)
                    || ! is_string($reason) || $reason === '') {
                    throw new InvalidArgumentException('A diagnostic snapshot cannot contain an acceptance verdict.');
                }

                $revision = ($latest?->revision ?? 0) + 1;
                $payload = [
                    'status' => $status,
                    'reason' => $reason,
                ];
                foreach ([
                    'gap_mm',
                    'outward_axis_deviation_deg',
                    'model_frame_id',
                    'coordinate_unit',
                    'pose_revision_a',
                    'pose_revision_b',
                    'branch_beta_geometry',
                    'branch_station_geometry',
                    'branch_host_surface_geometry',
                ] as $key) {
                    if (array_key_exists($key, $diagnostic)) {
                        $payload[$key] = $diagnostic[$key];
                    }
                }

                $saved[$jointId] = JointGeometryDiagnosticRevision::query()->create([
                    'project_id' => $lockedProject->id,
                    'company_id' => $actor->company_id,
                    'piping_line_id' => $lockedJoint->piping_line_id,
                    'joint_id' => $lockedJoint->id,
                    'revision' => $revision,
                    'method_code' => JointPortGeometryDiagnosticService::METHOD,
                    'source_fingerprint' => $diagnostic['source_fingerprint'],
                    'diagnostic_status' => $status,
                    'diagnostic_reason' => $reason,
                    'source_snapshot' => $diagnostic['input_snapshot'],
                    'diagnostic_snapshot' => $payload,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);
            }

            return $saved;
        }, $project->id);
    }

    /**
     * Fetch the latest persisted revision for each authorized joint.
     *
     * @param  iterable<Joint>  $joints
     * @return array<string, JointGeometryDiagnosticRevision> keyed by joint UUID
     */
    public function latestForJoints(Project $project, User $actor, iterable $joints): array
    {
        $ids = [];
        foreach ($joints as $joint) {
            if (! $joint instanceof Joint) {
                throw new InvalidArgumentException('Every snapshot lookup item must be a Joint.');
            }
            if ($joint->project_id !== $project->id) {
                abort(404);
            }
            $ids[] = $joint->id;
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $this->frames->assertProjectAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $ids): array {
            $latest = JointGeometryDiagnosticRevision::query()
                ->where('project_id', $project->id)
                ->whereIn('joint_id', $ids)
                ->orderByDesc('revision')
                ->get()
                ->unique('joint_id')
                ->keyBy('joint_id');

            return $latest->all();
        }, $project->id);
    }

    /**
     * Prefer the immutable snapshot when its sources still match. A changed
     * source or method invalidates the stored result explicitly; it never
     * silently substitutes a freshly calculated value for historical data.
     *
     * @param  array<string, array<string, mixed>>  $current
     * @param  array<string, JointGeometryDiagnosticRevision>  $latest
     * @return array<string, array<string, mixed>>
     */
    public function forDisplay(array $current, array $latest): array
    {
        $display = [];

        foreach ($current as $jointId => $liveDiagnostic) {
            $snapshot = $latest[$jointId] ?? null;
            if (! $snapshot instanceof JointGeometryDiagnosticRevision) {
                $liveDiagnostic['snapshot_state'] = 'NOT_CAPTURED';
                $display[$jointId] = $liveDiagnostic;

                continue;
            }

            $fresh = $snapshot->method_code === JointPortGeometryDiagnosticService::METHOD
                && is_string($liveDiagnostic['source_fingerprint'] ?? null)
                && hash_equals($snapshot->source_fingerprint, $liveDiagnostic['source_fingerprint']);
            $result = $snapshot->diagnostic_snapshot;
            $result['snapshot_state'] = $fresh ? 'CURRENT' : 'STALE';
            $result['snapshot_revision'] = $snapshot->revision;
            $result['snapshot_created_at'] = $snapshot->created_at;
            $result['method_code'] = $snapshot->method_code;

            if (! $fresh) {
                $result['captured_status'] = $snapshot->diagnostic_status;
                $result['captured_reason'] = $snapshot->diagnostic_reason;
                $result['status'] = 'STALE';
                $result['reason'] = $snapshot->method_code !== JointPortGeometryDiagnosticService::METHOD
                    ? 'DIAGNOSTIC_METHOD_CHANGED'
                    : 'SOURCE_FINGERPRINT_CHANGED';
            }

            $display[$jointId] = $result;
        }

        return $display;
    }
}
