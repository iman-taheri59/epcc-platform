<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\PipingClass;
use App\Models\DocumentRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Refuse to send an unreviewed PMS working-copy delta into SHARED. */
final class PmsRevisionDraftReview
{
    public function __construct(
        private readonly PmsRevisionDraftValidator $validator,
        private readonly PipingClassService $classes,
    ) {}

    /** Until capture can use draft content, never issue a falsely labelled revision. */
    public function assertPublishable(Document $document, DocumentRevision $revision): void
    {
        $this->assertShareable($document, $revision);
        if ($document->doc_type !== 'PMS' || $document->discipline !== 'PIPING') {
            return;
        }
        $drafts = DB::table('pms_class_revision_drafts')
            ->where('document_revision_id', $revision->id)->get();
        foreach ($drafts as $draft) {
            $previous = DB::table('pms_class_revision_snapshots as s')
                ->join('document_revisions as r', 'r.id', '=', 's.document_revision_id')
                ->where('s.pms_document_id', $document->id)
                ->where('s.piping_class_id', $draft->piping_class_id)
                ->where('r.ordinal', '<', $revision->ordinal)
                ->orderByDesc('r.ordinal')->value('s.specification');
            if ($previous === null || json_decode($draft->specification, true, 512, JSON_THROW_ON_ERROR)
                !== json_decode($previous, true, 512, JSON_THROW_ON_ERROR)) {
                throw ValidationException::withMessages([
                    'specification' => 'Revised PMS schedules cannot be published until revision-specific snapshot capture is available.',
                ]);
            }
        }
    }

    public function assertShareable(Document $document, DocumentRevision $revision): void
    {
        if ($document->doc_type !== 'PMS' || $document->discipline !== 'PIPING') {
            return;
        }
        $previous = DocumentRevision::where('document_id', $document->id)
            ->where('ordinal', '<', $revision->ordinal)
            ->orderByDesc('ordinal')->first();
        // First revision: the existing published-class gate captures the
        // initial specification. Later revisions need exactly one copy per
        // class from the immediately preceding approved snapshot set.
        $snapshots = $previous === null ? collect() : DB::table('pms_class_revision_snapshots')
            ->where('document_revision_id', $previous->id)->get()->keyBy('piping_class_id');
        $drafts = DB::table('pms_class_revision_drafts')
            ->where('document_revision_id', $revision->id)->get()->keyBy('piping_class_id');
        if ($snapshots->keys()->sort()->values()->all() !== $drafts->keys()->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'specification' => 'PMS revision working copies must match the previous approved class set.',
            ]);
        }
        foreach ($snapshots as $classId => $snapshot) {
            $spec = json_decode($drafts[$classId]->specification, true, 512, JSON_THROW_ON_ERROR);
            $this->validator->assertScheduleOnly(
                json_decode($snapshot->specification, true, 512, JSON_THROW_ON_ERROR), $spec,
            );
            $class = PipingClass::where('project_id', $document->project_id)
                ->whereKey($classId)->firstOrFail();
            $this->classes->assertRevisionScheduleSafe($class, $spec);
        }
    }
}
