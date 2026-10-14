<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Project;
use App\Models\User;
use App\Support\CdeState;
use App\Services\ReviewCycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The rules of the document register (SRS FR-ENG-02/03/04).
 *
 * Lives in a service, not the controller, because all three operations have to
 * be atomic and all three are wanted by the future API and importer too.
 */
final class DocumentRegister
{
    public function __construct(
        private readonly ReviewCycle $review,
        private readonly PmsRevisionSnapshotService $pmsSnapshots,
        private readonly PmsRevisionDraftReview $pmsDraftReview,
    )
    {
    }

    /**
     * FR-ENG-02: segment-based document number.
     *
     *     {PROJECT}-{DISCIPLINE}-{TYPE}-{SEQ:4}
     *
     * The sequence is allocated per (project, discipline, type) so each
     * discipline counts from 1, which is what engineers expect and what client
     * schemes assume.
     */
    public function nextNumber(Project $project, string $discipline, string $docType): string
    {
        $seq = $this->nextSequence($project, $discipline, $docType);

        return sprintf(
            '%s-%s-%s-%04d',
            strtoupper($project->code),
            strtoupper($discipline),
            strtoupper($docType),
            $seq,
        );
    }

    public function nextSequence(Project $project, string $discipline, string $docType): int
    {
        return 1 + (int) Document::query()
            ->where('project_id', $project->id)
            ->where('discipline', strtoupper($discipline))
            ->where('doc_type', strtoupper($docType))
            ->max('sequence');
    }

    /**
     * Register a document and issue its first revision, atomically.
     *
     * A document with no revision has no state and cannot be displayed, so the
     * two must never be separable - the same reasoning as project creation.
     *
     * @param  array{discipline:string,doc_type:string,title_en:string,title_fa:string,revision?:string,remarks?:string}  $data
     */
    public function register(Project $project, User $user, array $data): Document
    {
        return DB::transaction(function () use ($project, $user, $data): Document {
            $discipline = strtoupper($data['discipline']);
            $docType = strtoupper($data['doc_type']);
            // PMS is a piping engineering document, not a class row or a
            // generic specification with an inferred discipline.
            if ($docType === 'PMS' && $discipline !== 'PIPING') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'discipline' => 'A PMS document belongs to the PIPING discipline.',
                ]);
            }

            $document = Document::create([
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'number' => $this->nextNumber($project, $discipline, $docType),
                'discipline' => $discipline,
                'doc_type' => $docType,
                'sequence' => $this->nextSequence($project, $discipline, $docType),
                'title_en' => $data['title_en'],
                'title_fa' => $data['title_fa'],
            ]);

            // A new deliverable always starts in WIP with no suitability code:
            // nothing has been shared, so nothing is suitable for anything yet.
            $revision = DocumentRevision::create([
                'document_id' => $document->id,
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'revision' => $data['revision'] ?? 'A',
                'ordinal' => 1,
                'cde_state' => CdeState::WIP,
                'suitability' => null,
                'issued_by' => $user->id,
                'issued_at' => now(),
                'remarks' => $data['remarks'] ?? null,
            ]);

            $document->update(['current_revision_id' => $revision->id]);

            // Eager-load INSIDE the tenant scope. `document_revisions` is an
            // RLS table, so a lazy `->currentRevision` evaluated after the
            // caller leaves runAs() runs with no bound tenant and returns
            // NULL - the row is invisible, not missing. Fail-closed, but it
            // looks exactly like a broken write.
            $document->setRelation('currentRevision', $revision);

            return $document;
        });
    }

    /**
     * Move the current revision along the CDE state machine.
     *
     * @throws ValidationException on an illegal transition or suitability code
     */
    public function transition(
        Document $document,
        User $user,
        string $to,
        ?string $suitability,
        string $lang = 'fa',
    ): DocumentRevision {
        $revision = $document->currentRevision;

        if ($revision === null) {
            throw ValidationException::withMessages([
                'state' => $lang === 'fa'
                    ? 'این مدرک هیچ بازنگری‌ای ندارد.'
                    : 'This document has no revision.',
            ]);
        }

        if (! CdeState::canTransition($revision->cde_state, $to)) {
            // Naming both states matters: "invalid transition" alone leaves the
            // user guessing what the system thinks the current state is.
            throw ValidationException::withMessages([
                'state' => $lang === 'fa'
                    ? sprintf(
                        'گذار از «%s» به «%s» مجاز نیست.',
                        CdeState::label($revision->cde_state, 'fa'),
                        CdeState::label($to, 'fa'),
                    )
                    : sprintf(
                        'Cannot move from "%s" to "%s".',
                        CdeState::label($revision->cde_state, 'en'),
                        CdeState::label($to, 'en'),
                    ),
            ]);
        }

        if (! CdeState::allowsSuitability($to, $suitability)) {
            throw ValidationException::withMessages([
                'suitability' => $lang === 'fa'
                    ? 'کد تناسب برای این وضعیت معتبر نیست.'
                    : 'That suitability code is not valid for this state.',
            ]);
        }

        // FR-ENG-06: publishing over an unanswered blocking comment defeats
        // the review. Enforced HERE, in the state machine, rather than in the
        // controller - the API, the importer and any future bulk action all
        // go through this method, and a gate that only exists on one screen is
        // not a gate.
        if ($to === CdeState::PUBLISHED) {
            $this->review->assertPublishable($revision, $lang);
        }

        return DB::transaction(function () use ($document, $revision, $to, $suitability, $user): DocumentRevision {
            // Serialize approval with class attachment; a class must not be
            // added between the membership scan and snapshot publication.
            if ($document->doc_type === 'PMS' && CdeState::isApproved($to, $suitability)) {
                Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            }
            $fresh = DocumentRevision::whereKey($revision->id)->lockForUpdate()->firstOrFail();
            if ($fresh->cde_state !== $revision->cde_state) {
                throw ValidationException::withMessages(['state' => 'Revision state changed concurrently; please retry.']);
            }
            if ($to === CdeState::SHARED && $fresh->cde_state === CdeState::WIP) {
                $this->pmsDraftReview->assertShareable($document, $fresh);
            }
            $fresh->update([
                'cde_state' => $to,
                'suitability' => $suitability,
                'issued_by' => $user->id,
                'issued_at' => now(),
            ]);

            if ($document->doc_type === 'PMS' && CdeState::isApproved($to, $suitability)) {
                $this->pmsSnapshots->capture($document, $fresh);
            }

            return $fresh->refresh();
        });
    }

    /**
     * Issue the next revision (FR-ENG-04).
     *
     * The previous revision is ARCHIVED rather than deleted - superseding is a
     * record, not an erasure. The new revision starts at WIP.
     */
    public function issueRevision(
        Document $document,
        User $user,
        string $revisionCode,
        ?string $remarks = null,
        string $lang = 'fa',
    ): DocumentRevision {
        $current = $document->currentRevision;

        if ($current !== null && $current->cde_state !== CdeState::PUBLISHED) {
            // Superseding un-published work loses the review cycle that was
            // still in flight; finish it or reject it first.
            throw ValidationException::withMessages([
                'revision' => $lang === 'fa'
                    ? 'تنها پس از انتشار بازنگری فعلی می‌توان بازنگری جدید صادر کرد.'
                    : 'A new revision can only be issued once the current one is published.',
            ]);
        }

        $exists = DocumentRevision::where('document_id', $document->id)
            ->where('revision', $revisionCode)->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'revision' => $lang === 'fa'
                    ? 'این شمارهٔ بازنگری قبلاً ثبت شده است.'
                    : 'That revision already exists for this document.',
            ]);
        }

        return DB::transaction(function () use ($document, $user, $revisionCode, $remarks, $current): DocumentRevision {
            if ($current !== null) {
                $current->update(['cde_state' => CdeState::ARCHIVED]);
            }

            $next = DocumentRevision::create([
                'document_id' => $document->id,
                'project_id' => $document->project_id,
                'company_id' => $document->company_id,
                'revision' => $revisionCode,
                'ordinal' => 1 + (int) DocumentRevision::where('document_id', $document->id)->max('ordinal'),
                'cde_state' => CdeState::WIP,
                'suitability' => null,
                'issued_by' => $user->id,
                'issued_at' => now(),
                'remarks' => $remarks,
            ]);

            $document->update(['current_revision_id' => $next->id]);

            // Seed the NEXT revision's independent WIP working copies from
            // the last issued snapshots. Never mutate the published class or
            // previous issue-time snapshot; draft publication is a later step.
            if ($document->doc_type === 'PMS' && $document->discipline === 'PIPING' && $current !== null) {
                $snapshots = DB::table('pms_class_revision_snapshots')
                    ->where('document_revision_id', $current->id)->get();
                foreach ($snapshots as $snapshot) {
                    DB::table('pms_class_revision_drafts')->insert([
                        'id' => (string) Str::uuid(),
                        'project_id' => $document->project_id,
                        'company_id' => $document->company_id,
                        'pms_document_id' => $document->id,
                        'document_revision_id' => $next->id,
                        'piping_class_id' => $snapshot->piping_class_id,
                        'specification' => $snapshot->specification,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }

            return $next;
        });
    }
}
