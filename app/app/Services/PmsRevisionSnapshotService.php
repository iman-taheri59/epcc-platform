<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\PipingClass;
use App\Support\CdeState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Called inside the CDE publication transaction with the PMS document locked. */
final class PmsRevisionSnapshotService
{
    public function capture(Document $document, DocumentRevision $revision): void
    {
        if ($document->doc_type !== 'PMS' || $document->discipline !== 'PIPING'
            || $revision->document_id !== $document->id
            || ! CdeState::isApproved($revision->cde_state, $revision->suitability)) {
            throw ValidationException::withMessages(['pms' => 'An approved PIPING/PMS revision is required.']);
        }

        $classes = PipingClass::where('project_id', $document->project_id)
            ->where('pms_document_id', $document->id)->orderBy('id')->lockForUpdate()->get();
        if ($classes->isEmpty() || $classes->contains(fn (PipingClass $c) => ! $c->isPublished())) {
            throw ValidationException::withMessages(['pms' => 'All PMS classes must be published before approving this revision, and at least one class is required.']);
        }

        $drafts = DB::table('pms_class_revision_drafts')
            ->where('document_revision_id', $revision->id)->get()->keyBy('piping_class_id');
        if ($drafts->isNotEmpty()
            && $drafts->keys()->sort()->values()->all() !== $classes->pluck('id')->sort()->values()->all()) {
            throw ValidationException::withMessages(['pms' => 'The approved revision must contain every governed PMS class.']);
        }

        foreach ($classes as $class) {
            if (! DB::table('piping_class_rules')->where('piping_class_id', $class->id)->exists()) {
                throw ValidationException::withMessages(['pms' => 'Every published PMS class must have specification rules.']);
            }
            // PostgreSQL builds a deterministic JSON document of the exact
            // header and rule/branch rows at issuance. Range columns become
            // their canonical text form. A future revision gets new rows;
            // the old snapshot is never overwritten.
            $spec = DB::selectOne(<<<'SQL'
                SELECT jsonb_build_object(
                    'class', to_jsonb(c),
                    'rules', COALESCE((SELECT jsonb_agg(to_jsonb(r) ORDER BY r.id)
                        FROM piping_class_rules r WHERE r.piping_class_id = c.id), '[]'::jsonb),
                    'branch_rules', COALESCE((SELECT jsonb_agg(to_jsonb(b) ORDER BY b.id)
                        FROM piping_class_branch_rules b WHERE b.piping_class_id = c.id), '[]'::jsonb)
                ) AS specification FROM piping_classes c WHERE c.id = ?
            SQL, [$class->id]);
            DB::table('pms_class_revision_snapshots')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $document->project_id,
                'company_id' => $class->company_id,
                'pms_document_id' => $document->id,
                'document_revision_id' => $revision->id,
                'piping_class_id' => $class->id,
                // Review checked this exact copy before PUBLISHED. The SQL
                // snapshot trigger also checks draft identity, previous issue
                // and the strict schedule-only delta, then freezes the row.
                'specification' => $drafts->get($class->id)?->specification ?? $spec->specification,
                'created_at' => now(),
            ]);
        }
    }
}
