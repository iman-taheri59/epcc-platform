<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\PipingClass;
use App\Models\PipingClassRule;
use App\Models\Project;
use App\Models\User;
use App\Services\DocumentRegister;
use App\Support\CdeState;

/** Only for existing legacy test fixtures; not an application escape hatch. */
final class ApprovedPmsClassFixture
{
    /** Caller MUST already be inside the project's owning-company RLS scope. */
    public static function ensure(Project $project, User $actor, PipingClass $class): PipingClass
    {
        if ($class->pms_document_id !== null) {
            return $class;
        }
        $document = Document::create([
            'project_id' => $project->id, 'company_id' => $actor->company_id,
            'number' => $project->code.'-PIP-PMS-'.$class->code,
            'discipline' => 'PIPING', 'doc_type' => 'PMS', 'sequence' => 1,
            'title_en' => 'Synthetic '. $class->code.' PMS', 'title_fa' => 'PMS آزمایشی',
        ]);
        $revision = DocumentRevision::create([
            'document_id' => $document->id, 'project_id' => $project->id,
            'company_id' => $actor->company_id, 'revision' => 'A',
            'ordinal' => 1, 'cde_state' => CdeState::WIP,
        ]);
        $document->update(['current_revision_id' => $revision->id]);
        $class->forceFill([
            'pms_document_id' => $document->id,
            'state' => 'PUBLISHED',
            'dn_span' => $class->dn_span ?? '[15,600)',
        ])->save();
        if (! PipingClassRule::where('piping_class_id', $class->id)->exists()) {
            PipingClassRule::create([
                'project_id' => $project->id, 'company_id' => $actor->company_id,
                'piping_class_id' => $class->id, 'rule_kind' => 'PIPE_SCHEDULE',
                'dn_range' => '[15,600)', 'value' => ['schedule' => '80'],
            ]);
        }
        $register = app(DocumentRegister::class);
        $register->transition($document->refresh(), $actor, CdeState::SHARED, 'S2');
        $register->transition($document->refresh(), $actor, CdeState::PUBLISHED, 'A1');

        return $class->refresh();
    }
}
