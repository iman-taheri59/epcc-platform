<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Project;
use App\Models\User;
use App\Support\CdeState;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Narrow, nominated-author edit of the next revision's working copy. */
final class PmsRevisionDraftService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PmsAccessService $authors,
        private readonly ProjectArchiveService $archives,
        private readonly PmsRevisionDraftValidator $validator,
    ) {}

    /**
     * Change an existing PIPE_SCHEDULE rule, not its identity, range or kind.
     * This edits ONLY the WIP revision's copy; the live published class and
     * previous approved snapshots remain untouched. No publication yet.
     *
     * @return array<string, mixed> Updated working-copy specification.
     */
    public function changePipeSchedule(
        Project $project,
        User $actor,
        string $documentId,
        string $classId,
        string $ruleId,
        string $schedule,
    ): array {
        return $this->tenant->runAs($actor->company_id, function () use (
            $project, $actor, $documentId, $classId, $ruleId, $schedule,
        ): array {
            $this->archives->assertWritable($project);
            $schedule = trim($schedule);
            if ($schedule === '' || mb_strlen($schedule) > 32) {
                throw ValidationException::withMessages(['schedule' => 'Enter a pipe schedule (1–32 characters).']);
            }

            // Serialize policy changes with edits; never trust a nomination
            // checked before this transaction or before the latest suspension.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pms-entry:'.$project->id]);
            if (! $this->authors->allows($project, $actor)) {
                throw ValidationException::withMessages(['pms_access' => 'An active PMS author nomination is required.']);
            }
            $document = Document::whereKey($documentId)->where('project_id', $project->id)
                ->where('company_id', $actor->company_id)->lockForUpdate()->firstOrFail();
            if ($document->doc_type !== 'PMS' || $document->discipline !== 'PIPING') {
                throw ValidationException::withMessages(['pms_document_id' => 'A PIPING/PMS document is required.']);
            }
            $revision = DocumentRevision::whereKey($document->current_revision_id)
                ->lockForUpdate()->firstOrFail();
            if ($revision->cde_state !== CdeState::WIP) {
                throw ValidationException::withMessages(['revision' => 'PMS working copies can only be edited in WIP.']);
            }
            $draft = DB::table('pms_class_revision_drafts')
                ->where('pms_document_id', $document->id)
                ->where('document_revision_id', $revision->id)
                ->where('piping_class_id', $classId)
                ->lockForUpdate()->first();
            if ($draft === null) {
                throw ValidationException::withMessages(['piping_class_id' => 'No WIP working copy exists for this PMS class.']);
            }

            $spec = json_decode($draft->specification, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($spec) || ! isset($spec['rules']) || ! is_array($spec['rules'])) {
                throw ValidationException::withMessages(['specification' => 'Invalid PMS working-copy structure.']);
            }
            $found = false;
            foreach ($spec['rules'] as &$rule) {
                if (($rule['id'] ?? null) !== $ruleId) {
                    continue;
                }
                if ($found || ($rule['rule_kind'] ?? null) !== 'PIPE_SCHEDULE') {
                    throw ValidationException::withMessages(['rule_id' => 'Choose one existing PIPE_SCHEDULE rule.']);
                }
                if (! is_array($rule['value'] ?? null) || ! is_string($rule['value']['schedule'] ?? null)) {
                    throw ValidationException::withMessages(['rule_id' => 'The schedule rule has no schedule value.']);
                }
                $rule['value']['schedule'] = $schedule;
                $found = true;
            }
            unset($rule);
            if (! $found) {
                throw ValidationException::withMessages(['rule_id' => 'Choose one existing PIPE_SCHEDULE rule.']);
            }
            // Compare against the previous approved issue, not the mutable
            // live class. Only a schedule leaf may differ in this first slice.
            $baseline = DB::table('pms_class_revision_snapshots as s')
                ->join('document_revisions as r', 'r.id', '=', 's.document_revision_id')
                ->where('s.pms_document_id', $document->id)
                ->where('s.piping_class_id', $classId)
                ->where('r.ordinal', '<', $revision->ordinal)
                ->orderByDesc('r.ordinal')->value('s.specification');
            if ($baseline === null) {
                throw ValidationException::withMessages(['specification' => 'A prior approved PMS snapshot is required.']);
            }
            $this->validator->assertScheduleOnly(
                json_decode($baseline, true, 512, JSON_THROW_ON_ERROR), $spec,
            );
            DB::table('pms_class_revision_drafts')->where('id', $draft->id)
                ->update(['specification' => json_encode($spec, JSON_THROW_ON_ERROR), 'updated_at' => now()]);

            return $spec;
        }, $project->id);
    }
}
