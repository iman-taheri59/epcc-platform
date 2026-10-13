<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\ProjectState;
use App\Support\TenantType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Project close-out: archive and reopen (ADR-013 §7.2).
 *
 * ARCHIVING IS NOT DELETION, AND THAT IS THE POINT
 * ------------------------------------------------
 * An archived project is read-only, hidden from pickers, and does not consume
 * a plan slot - but every certificate, transmittal and punch item stays exactly
 * where it was and stays exportable. The ADR-013 spike showed deleting one
 * project destroyed 2 payment certificates and an MC certificate; those are the
 * records a company produces when a claim arrives two years later.
 *
 * WHY THIS SERVICE EXISTS WHEN RLS ALREADY BLOCKS WRITES
 * ------------------------------------------------------
 * Because the database's refusal is SILENT for UPDATE and DELETE. A blocked
 * UPDATE matches zero rows, returns no error, and Eloquent's save() happily
 * returns true - so the user is told "saved" while their edit vanished.
 * Measured in the ADR-013 spike.
 *
 * So: this layer refuses loudly and early with a real message, and the RLS
 * policy is the backstop that makes a forgotten check harmless instead of
 * catastrophic. Neither is sufficient alone - one is forgettable, the other is
 * mute.
 */
final class ProjectArchiveService
{
    /**
     * Only the owner may close out a project.
     *
     * Not the contractor, not the PMC: archiving ends everyone else's ability
     * to write, so it belongs to the party that owns the contract.
     */
    private const ARCHIVING_TYPES = [TenantType::OWN];

    /**
     * Archive a project.
     *
     * @throws ValidationException
     */
    public function archive(Project $project, User $actor, ?string $reason = null): Project
    {
        $this->assertMayArchive($project, $actor);

        if ($project->state === ProjectState::ARCHIVED) {
            throw ValidationException::withMessages([
                'state' => __('epcc.project.already_archived'),
            ]);
        }

        if (! ProjectState::canTransition($project->state, ProjectState::ARCHIVED)) {
            throw ValidationException::withMessages([
                'state' => __('epcc.project.bad_transition'),
            ]);
        }

        return DB::transaction(function () use ($project, $actor, $reason): Project {
            // Re-read under FOR UPDATE so two simultaneous archive requests
            // cannot both pass the state check above. Same lock discipline as
            // the joint-number allocator in ADR-006.
            $fresh = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($fresh->state === ProjectState::ARCHIVED) {
                throw ValidationException::withMessages([
                    'state' => __('epcc.project.already_archived'),
                ]);
            }

            $fresh->state = ProjectState::ARCHIVED;
            $fresh->archived_at = now();
            $fresh->archived_by = $actor->id;
            $fresh->archive_reason = $reason;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Reopen an archived project.
     *
     * Deliberately reversible, and deliberately NOT silent: reopening clears
     * the provenance columns, so an archive/reopen cycle is visible in the
     * audit log rather than leaving stale "archived by" data on a live project.
     *
     * @throws ValidationException
     */
    public function reopen(Project $project, User $actor): Project
    {
        $this->assertMayArchive($project, $actor);

        if ($project->state !== ProjectState::ARCHIVED) {
            throw ValidationException::withMessages([
                'state' => __('epcc.project.not_archived'),
            ]);
        }

        $this->assertQuotaAllowsAnotherActiveProject($project);

        return DB::transaction(function () use ($project): Project {
            $fresh = Project::query()->lockForUpdate()->findOrFail($project->id);

            $fresh->state = ProjectState::ACTIVE;
            $fresh->archived_at = null;
            $fresh->archived_by = null;
            $fresh->archive_reason = null;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * The guard every write path should call.
     *
     * Throws rather than returning false, because "the caller might ignore the
     * boolean" is exactly the failure mode the RLS backstop exists to catch -
     * and we would rather not need the backstop.
     *
     * @throws ValidationException
     */
    public function assertWritable(Project $project): void
    {
        if (! ProjectState::isWritable($project->state)) {
            throw ValidationException::withMessages([
                'project' => __('epcc.project.read_only'),
            ]);
        }
    }

    public function isWritable(Project $project): bool
    {
        return ProjectState::isWritable($project->state);
    }

    /**
     * How many ACTIVE projects this company owns.
     *
     * Archived projects are excluded on purpose (ProjectState::countsAgainstQuota).
     * A starter-plan customer with max_projects = 1 must be able to finish a
     * project and start the next one WITHOUT deleting the first - otherwise the
     * licence terms push them toward destroying evidence.
     */
    public function activeProjectCount(string $companyId): int
    {
        return Project::query()
            ->where('owner_company_id', $companyId)
            ->where('state', ProjectState::ACTIVE)
            ->count();
    }

    /** @throws ValidationException */
    private function assertMayArchive(Project $project, User $actor): void
    {
        if ($actor->company_id !== $project->owner_company_id) {
            throw ValidationException::withMessages([
                'project' => __('epcc.project.archive_owner_only'),
            ]);
        }

        $type = $actor->company?->tenant_type;

        if ($type !== null && ! in_array($type, self::ARCHIVING_TYPES, true)) {
            throw ValidationException::withMessages([
                'project' => __('epcc.project.archive_owner_only'),
            ]);
        }
    }

    /**
     * Reopening consumes a licence slot again, so it can legitimately fail.
     *
     * Checked here rather than left to a surprise at the next write: telling
     * someone "you are at your project limit" while reopening is honest;
     * letting them reopen and then blocking every save is not.
     *
     * @throws ValidationException
     */
    private function assertQuotaAllowsAnotherActiveProject(Project $project): void
    {
        $company = $project->ownerCompany;
        $max = $company?->plan?->max_projects;

        if ($max === null) {
            return; // unlimited
        }

        if ($this->activeProjectCount($project->owner_company_id) >= $max) {
            throw ValidationException::withMessages([
                'project' => __('epcc.project.quota_reached', ['max' => $max]),
            ]);
        }
    }
}
