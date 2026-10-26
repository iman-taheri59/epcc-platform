<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcceptedMtoBatch;
use App\Models\AcceptedMtoRow;
use App\Models\MaterialItem;
use App\Models\MtoRevision;
use App\Models\MtoStagingRow;
use App\Models\MtoDocument;
use App\Models\Project;
use App\Support\ProjectState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns one valid DRAFT MTO revision into an immutable accepted snapshot.
 *
 * There is intentionally no permission check here yet. MTO access design is
 * deferred; the caller supplies a tenant-bound revision and PostgreSQL RLS
 * remains the existing project-participant visibility boundary.
 */
final class MtoAcceptanceService
{
    /**
     * Accept every staging row in one transaction, or accept none of them.
     *
     * @throws ValidationException
     */
    public function accept(MtoRevision $revision, string $lang = 'fa'): AcceptedMtoBatch
    {
        return DB::transaction(function () use ($revision, $lang): AcceptedMtoBatch {
            // Locate without trusting a caller's stale project attributes, then
            // lock project -> document -> revision. Archive uses the same project
            // row lock. The NEW chronology trigger shares this transaction.
            $locator = MtoRevision::query()->select(['id', 'project_id', 'mto_document_id'])->find($revision->getKey());
            if ($locator === null) {
                $this->fail($lang, 'revision', 'بازنگری MTO پیدا نشد.', 'MTO revision was not found.');
            }
            $project = Project::query()->whereKey($locator->project_id)->lockForUpdate()->first();
            if ($project === null || $project->state !== ProjectState::ACTIVE) {
                $this->fail($lang, 'project', 'پروژه برای پذیرش MTO فعال نیست.', 'The project is not active for MTO acceptance.');
            }
            $document = MtoDocument::query()->whereKey($locator->mto_document_id)->lockForUpdate()->first();
            $source = MtoRevision::query()
                ->with('document')
                ->whereKey($revision->getKey())
                ->lockForUpdate()
                ->first();

            if ($source === null || $document === null || $source->project_id !== $project->id
                || $source->mto_document_id !== $document->id || $document->project_id !== $project->id) {
                $this->fail($lang, 'revision', 'بازنگری MTO پیدا نشد.', 'MTO revision was not found.');
            }

            if ($source->state !== MtoRevision::DRAFT) {
                $this->fail(
                    $lang,
                    'revision',
                    'فقط بازنگری MTO در وضعیت پیش‌نویس قابل پذیرش است.',
                    'Only a DRAFT MTO revision can be accepted.'
                );
            }

            $rows = MtoStagingRow::query()
                ->where('mto_revision_id', $source->id)
                ->orderBy('line_no')
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                $this->fail(
                    $lang,
                    'rows',
                    'بازنگری MTO بدون ردیف قابل پذیرش نیست.',
                    'An MTO revision with no rows cannot be accepted.'
                );
            }

            $itemNumbers = [];
            $validationErrors = [];
            $materials = [];

            foreach ($rows as $row) {
                $itemNumbers[$row->mto_item_no] = ($itemNumbers[$row->mto_item_no] ?? 0) + 1;

                if ($row->validation_state !== MtoStagingRow::ACCEPTABLE) {
                    $validationErrors[] = sprintf(
                        '%s: %s',
                        $row->mto_item_no,
                        $row->validation_state
                    );
                    continue;
                }

                if ((float) $row->raw_quantity <= 0) {
                    $validationErrors[] = sprintf('%s: QUANTITY_NOT_POSITIVE', $row->mto_item_no);
                    continue;
                }

                // Do not trust a stale eager-loaded material label. Resolve the
                // current catalogue row, then copy its values into the snapshot.
                $material = MaterialItem::query()
                    ->forProjectCatalogue($source->project_id)
                    ->active()
                    ->whereKey($row->material_item_id)
                    ->first();

                if ($material === null) {
                    $validationErrors[] = sprintf('%s: MATERIAL_NOT_AVAILABLE', $row->mto_item_no);
                    continue;
                }

                if ($row->raw_unit !== $material->base_unit) {
                    $validationErrors[] = sprintf('%s: UNIT_INCOMPATIBLE', $row->mto_item_no);
                    continue;
                }

                $materials[$row->id] = $material;
            }

            foreach ($itemNumbers as $itemNumber => $count) {
                if ($count > 1) {
                    $validationErrors[] = sprintf('%s: DUPLICATE_MTO_ITEM_NO', $itemNumber);
                }
            }

            if ($validationErrors !== []) {
                $this->fail(
                    $lang,
                    'rows',
                    'همه ردیف‌های MTO باید معتبر باشند: '.implode('، ', $validationErrors),
                    'Every MTO staging row must be acceptable: '.implode(', ', $validationErrors)
                );
            }

            $acceptedAt = now();
            $batch = AcceptedMtoBatch::create([
                'project_id' => $source->project_id,
                'company_id' => $source->company_id,
                'mto_document_id' => $source->mto_document_id,
                'mto_revision_id' => $source->id,
                'document_number' => $source->document->document_number,
                'revision_code' => $source->revision_code,
                'state' => AcceptedMtoBatch::ACCEPTED,
                'source_file_hash' => $source->source_file_hash,
                'scope_mode' => $source->scope_mode,
                'header_scope_id' => $source->header_scope_id,
                'hierarchy_revision_id' => $source->hierarchy_revision_id,
                'accepted_at' => $acceptedAt,
            ]);

            foreach ($rows as $row) {
                /** @var MaterialItem $material */
                $material = $materials[$row->id];

                AcceptedMtoRow::create([
                    'project_id' => $source->project_id,
                    'company_id' => $source->company_id,
                    'accepted_mto_batch_id' => $batch->id,
                    'mto_document_id' => $source->mto_document_id,
                    'mto_revision_id' => $source->id,
                    'source_staging_row_id' => $row->id,
                    'mto_item_no' => $row->mto_item_no,
                    'material_item_id' => $material->id,
                    'gross_quantity' => $row->raw_quantity,
                    'unit' => $row->raw_unit,
                    // No conversion engine exists in this slice. Exact unit
                    // compatibility makes the canonical value unambiguous.
                    'canonical_quantity' => $row->raw_quantity,
                    'scope_id' => $row->row_scope_id ?? $source->header_scope_id,
                    'hierarchy_revision_id' => $source->hierarchy_revision_id,
                    'piping_class_id' => $row->piping_class_id,
                    'raw_description' => $row->raw_description,
                    'material_description_en' => $material->description_en,
                    'material_description_fa' => $material->description_fa,
                    'material_commodity_code' => $material->commodity_code,
                    'material_base_unit' => $material->base_unit,
                    'material_category' => $material->category,
                    'source_row_hash' => $this->sourceRowHash($row, $material),
                    'accepted_at' => $acceptedAt,
                ]);
            }

            // This update is part of the same transaction as every snapshot
            // row. If it fails, the batch and all rows roll back together.
            $source->forceFill(['state' => MtoRevision::ACCEPTED])->save();

            return $batch->load('rows');
        });
    }

    /** Alias kept explicit for callers that name the operation by its source. */
    public function acceptRevision(MtoRevision $revision, string $lang = 'fa'): AcceptedMtoBatch
    {
        return $this->accept($revision, $lang);
    }

    private function sourceRowHash(MtoStagingRow $row, MaterialItem $material): string
    {
        return hash('sha256', json_encode([
            'line_no' => $row->line_no,
            'mto_item_no' => $row->mto_item_no,
            'raw_description' => $row->raw_description,
            'raw_quantity' => (string) $row->raw_quantity,
            'raw_unit' => $row->raw_unit,
            'material_item_id' => $material->id,
            'commodity_code' => $material->commodity_code,
            'scope_id' => $row->row_scope_id,
            'piping_class_id' => $row->piping_class_id,
        ], JSON_THROW_ON_ERROR));
    }

    /** @throws ValidationException */
    private function fail(string $lang, string $field, string $fa, string $en): never
    {
        throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);
    }
}
