<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Latest A-family issued PMS snapshots, skipping B-coded intervening issues. */
final class PmsApprovedRevisionBaseline
{
    /** @return Collection<string, object> keyed by stable piping_class_id */
    public function before(Document $document, DocumentRevision $revision): Collection
    {
        $baselineRevisionId = DB::table('document_revisions as r')
            ->join('pms_class_revision_snapshots as s', 's.document_revision_id', '=', 'r.id')
            ->where('r.document_id', $document->id)
            ->where('r.ordinal', '<', $revision->ordinal)
            ->whereIn('r.cde_state', ['PUBLISHED', 'ARCHIVED'])
            ->where('r.suitability', 'like', 'A%')
            ->orderByDesc('r.ordinal')->value('r.id');

        if ($baselineRevisionId === null) {
            return collect();
        }

        return DB::table('pms_class_revision_snapshots')
            ->where('document_revision_id', $baselineRevisionId)
            ->where('pms_document_id', $document->id)
            ->get()->keyBy('piping_class_id');
    }
}
