<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\PipingClass;
use App\Models\Project;
use App\Models\User;
use App\Support\CdeState;
use App\Support\Piping\PipingClassState;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** ADR-028: nominate a draft class into ONE governing WIP PMS document. */
final class PmsClassDocumentService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PmsAccessService $authors,
        private readonly ProjectArchiveService $archives,
    ) {}

    public function attach(Project $project, User $actor, string $classId, string $documentId): PipingClass
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $classId, $documentId): PipingClass {
            // The same project lock serializes nomination/suspension and this
            // attachment. Check the CURRENT policy after acquiring it.
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['pms-entry:'.$project->id]);
            $this->archives->assertWritable($project);
            if (! $this->authors->allows($project, $actor)) {
                throw ValidationException::withMessages(['pms_access' => 'An active PMS author nomination is required.']);
            }

            $class = PipingClass::where('project_id', $project->id)->whereKey($classId)
                ->lockForUpdate()->firstOrFail();
            $document = Document::with('currentRevision')
                ->where('project_id', $project->id)->whereKey($documentId)->firstOrFail();
            if ($class->company_id !== $actor->company_id || $document->company_id !== $actor->company_id) {
                throw ValidationException::withMessages(['pms_document_id' => 'Class and PMS document must be owned by the authoring company.']);
            }
            if ($class->state !== PipingClassState::DRAFT || $class->pms_document_id !== null) {
                throw ValidationException::withMessages(['pms_document_id' => 'Only an unassigned draft class can be attached.']);
            }
            if ($document->discipline !== 'PIPING' || $document->doc_type !== 'PMS'
                || $document->currentRevision?->cde_state !== CdeState::WIP) {
                throw ValidationException::withMessages(['pms_document_id' => 'A WIP PIPING/PMS document is required.']);
            }
            $class->pms_document_id = $document->id;
            $class->save();

            return $class;
        }, $project->id);
    }
}
