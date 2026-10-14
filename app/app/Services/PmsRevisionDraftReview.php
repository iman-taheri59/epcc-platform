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
