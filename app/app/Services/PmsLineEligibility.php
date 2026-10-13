<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Resolve the exact approved, current PMS class snapshot for a NEW line. */
final class PmsLineEligibility
{
    /** Called inside a tenant-scoped transaction, never with an invented class. */
    public function snapshotId(string $projectId, ?string $classId): string
    {
        if ($classId === null || $classId === '') {
            throw ValidationException::withMessages([
                'piping_class_id' => 'Choose a published class governed by an approved PMS revision before registering a line.',
            ]);
        }

        $row = DB::table('piping_classes as c')
            ->join('documents as d', 'd.id', '=', 'c.pms_document_id')
            ->join('document_revisions as r', 'r.id', '=', 'd.current_revision_id')
            ->join('pms_class_revision_snapshots as s', function ($join): void {
                $join->on('s.document_revision_id', '=', 'r.id')
                    ->on('s.piping_class_id', '=', 'c.id')
                    ->on('s.pms_document_id', '=', 'd.id');
            })
            ->where('c.id', $classId)
            ->where('c.project_id', $projectId)
            ->where('d.project_id', $projectId)
            ->where('r.project_id', $projectId)
            ->where('s.project_id', $projectId)
            ->where('c.state', 'PUBLISHED')
            ->where('d.doc_type', 'PMS')
            ->where('d.discipline', 'PIPING')
            ->where('r.cde_state', 'PUBLISHED')
            ->where('r.suitability', 'like', 'A%')
            ->value('s.id');

        if ($row === null) {
            throw ValidationException::withMessages([
                'piping_class_id' => 'No current approved PMS revision contains this published class in this project.',
            ]);
        }

        return $row;
    }
}
