<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ComponentPoseRevision;
use App\Models\Project;
use App\Models\ProjectModelFrameRevision;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\LineReleaseState;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Manage immutable, project-local ENU frame definitions for ADR-061. */
final class ProjectModelFrameService
{
    public function __construct(
        private readonly ProjectArchiveService $archives,
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
    ) {}

    /**
     * Read the latest frame only after binding and validating the actor's
     * company, project participation, and project membership.
     */
    public function latestForProject(Project $project, User $actor): ?ProjectModelFrameRevision
    {
        $this->assertProjectAccess($project, $actor);

        return $this->tenant->runAs(
            $actor->company_id,
            fn (): ?ProjectModelFrameRevision => $this->latestInCurrentScope($project->id),
            $project->id,
        );
    }

    /**
     * Record a new frame revision when the declared project origin changes.
     * The axes and unit are fixed by ADR-061; the origin description is always
     * supplied explicitly and is never inferred from a line or drawing.
     */
    public function define(Project $project, User $actor, string $originDescription): ProjectModelFrameRevision
    {
        $this->assertProjectAccess($project, $actor);

        $originDescription = trim($originDescription);
        if ($originDescription === '' || mb_strlen($originDescription) > 1000) {
            throw ValidationException::withMessages([
                'origin_description' => __('epcc.component.pose_origin_description'),
            ]);
        }

        return $this->tenant->runAs($actor->company_id, function () use (
            $project,
            $actor,
            $originDescription,
        ): ProjectModelFrameRevision {
            // Frame changes and pose saves lock the same project row. This
            // serializes revision allocation and prevents a pose from being
            // saved against a frame that ceased to be current mid-write.
            $lockedProject = Project::query()->lockForUpdate()->findOrFail($project->id);
            abort_unless($this->actorCanAccessInCurrentScope($lockedProject->id, $actor), 404);
            $this->archives->assertWritable($lockedProject);
            abort_unless($this->authz->allows($actor, 'equipment.maintain', $lockedProject), 403);

            $latest = $this->latestInCurrentScope($lockedProject->id);
            if ($latest !== null && $latest->origin_description === $originDescription) {
                return $latest;
            }

            if ($latest !== null) {
                $hasProtectedPose = ComponentPoseRevision::query()
                    ->where('project_id', $lockedProject->id)
                    ->whereHas('line', fn ($query) => $query->where(
                        'release_state', '<>', LineReleaseState::DRAFT
                    ))
                    ->exists();

                if ($hasProtectedPose) {
                    throw ValidationException::withMessages([
                        'origin_description' => __('epcc.component.pose_frame_locked'),
                    ]);
                }
            }

            return ProjectModelFrameRevision::query()->create([
                'project_id' => $lockedProject->id,
                'company_id' => $actor->company_id,
                'revision' => ($latest?->revision ?? 0) + 1,
                'frame_code' => ProjectModelFrameRevision::FRAME_CODE,
                'axis_convention' => ProjectModelFrameRevision::AXIS_CONVENTION,
                'coordinate_unit' => ProjectModelFrameRevision::COORDINATE_UNIT,
                'origin_description' => $originDescription,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        }, $project->id);
    }

    /** Fail closed unless the active actor is a member of a participating company. */
    public function assertProjectAccess(Project $project, User $actor): void
    {
        if (! $actor->is_active || ! is_string($actor->company_id) || $actor->company_id === '') {
            abort(404);
        }

        $allowed = $this->tenant->runAs(
            $actor->company_id,
            fn (): bool => $this->actorCanAccessInCurrentScope($project->id, $actor),
            $project->id,
        );

        abort_unless($allowed, 404);
    }

    private function actorCanAccessInCurrentScope(string $projectId, User $actor): bool
    {
        return User::query()
            ->whereKey($actor->id)
            ->where('company_id', $actor->company_id)
            ->where('is_active', true)
            ->exists()
            && DB::table('project_company')
                ->where('project_id', $projectId)
                ->where('company_id', $actor->company_id)
                ->exists()
            && DB::table('project_memberships')
                ->where('project_id', $projectId)
                ->where('user_id', $actor->id)
                ->exists();
    }

    private function latestInCurrentScope(string $projectId): ?ProjectModelFrameRevision
    {
        return ProjectModelFrameRevision::query()
            ->where('project_id', $projectId)
            ->where('frame_code', ProjectModelFrameRevision::FRAME_CODE)
            ->orderByDesc('revision')
            ->first();
    }
}
