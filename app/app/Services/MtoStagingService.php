<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\MtoStagingRow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Versioned MTO staging. This service does not accept rows; it only loads and
 * validates editable DRAFT rows so later slices can snapshot them atomically.
 */
final class MtoStagingService
{
    public function __construct(private readonly MaterialCatalogueService $catalogue)
    {
    }

    /** @throws ValidationException */
    public function createDocument(
        Project $project,
        User $user,
        string $documentNumber,
        string $titleEn,
        string $titleFa,
        string $discipline = 'PIPING',
        string $sourceType = MtoDocument::SOURCE_MANUAL,
        ?string $cdeDocumentId = null,
        string $lang = 'fa',
    ): MtoDocument {
        $documentNumber = strtoupper(trim($documentNumber));
        $titleEn = trim($titleEn);
        $titleFa = trim($titleFa);
        $discipline = strtoupper(trim($discipline));
        $sourceType = strtoupper(trim($sourceType));

        $fail = fn (string $field, string $fa, string $en): never => throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);

        if ($documentNumber === '') {
            $fail('document_number', 'شماره سند MTO الزامی است.', 'MTO document number is required.');
        }

        if ($titleEn === '' || $titleFa === '') {
            $fail('title', 'عنوان سند MTO به هر دو زبان الزامی است.', 'MTO title is required in both languages.');
        }

        if (! in_array($sourceType, MtoDocument::sourceTypes(), true)) {
            $fail('source_type', 'نوع منبع MTO معتبر نیست.', 'MTO source type is not recognised.');
        }

        Company::findOrFail($user->company_id);

        return MtoDocument::create([
            'project_id' => $project->id,
            'company_id' => $user->company_id,
            'discipline' => $discipline,
            'document_number' => $documentNumber,
            'title_en' => $titleEn,
            'title_fa' => $titleFa,
            'source_type' => $sourceType,
            'cde_document_id' => $cdeDocumentId,
        ]);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     *
     * @throws ValidationException
     */
    public function createRevision(
        MtoDocument $document,
        User $user,
        string $revisionCode,
        array $rows,
        ?string $revisionDate = null,
        string $scopeMode = MtoRevision::SCOPE_ROW,
        ?string $headerScopeId = null,
        ?string $sourceFileHash = null,
        ?string $hierarchyRevisionId = null,
        string $lang = 'fa',
    ): MtoRevision {
        $revisionCode = strtoupper(trim($revisionCode));
        $scopeMode = strtoupper(trim($scopeMode));

        $fail = fn (string $field, string $fa, string $en): never => throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);

        if ($revisionCode === '') {
            $fail('revision_code', 'کد بازنگری MTO الزامی است.', 'MTO revision code is required.');
        }

        if ($rows === []) {
            $fail('rows', 'بازنگری MTO باید حداقل یک ردیف staging داشته باشد.', 'MTO revision needs at least one staging row.');
        }

        if (! in_array($scopeMode, MtoRevision::scopeModes(), true)) {
            $fail('scope_mode', 'نوع scope برای MTO معتبر نیست.', 'MTO scope mode is not recognised.');
        }

        return DB::transaction(function () use (
            $document,
            $user,
            $revisionCode,
            $revisionDate,
            $scopeMode,
            $headerScopeId,
            $sourceFileHash,
            $hierarchyRevisionId,
            $rows,
            $lang
        ): MtoRevision {
            $revision = MtoRevision::create([
                'project_id' => $document->project_id,
                'company_id' => $user->company_id,
                'mto_document_id' => $document->id,
                'revision_code' => $revisionCode,
                'revision_date' => $revisionDate,
                'state' => MtoRevision::DRAFT,
                'source_file_hash' => $sourceFileHash,
                'scope_mode' => $scopeMode,
                'header_scope_id' => $headerScopeId,
                'hierarchy_revision_id' => $hierarchyRevisionId,
            ]);

            $normalisedRows = array_values(array_map(
                fn (array $row, int $index): array => $this->normaliseRow($document->project, $row, $index + 1, $lang),
                $rows,
                array_keys($rows)
            ));

            $itemCounts = [];
            foreach ($normalisedRows as $row) {
                $itemCounts[$row['mto_item_no']] = ($itemCounts[$row['mto_item_no']] ?? 0) + 1;
            }

            foreach ($normalisedRows as $row) {
                if (($itemCounts[$row['mto_item_no']] ?? 0) > 1) {
                    $row['validation_state'] = MtoStagingRow::BLOCKED_DUPLICATE_IDENTITY;
                    $row['validation_errors'][] = 'DUPLICATE_MTO_ITEM_NO';
                }

                MtoStagingRow::create([
                    'project_id' => $document->project_id,
                    'mto_revision_id' => $revision->id,
                ] + $row);
            }

            return $revision->refresh()->load('rows');
        });
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    private function normaliseRow(Project $project, array $row, int $lineNo, string $lang): array
    {
        $mtoItemNo = trim((string) ($row['mto_item_no'] ?? ''));
        $description = trim((string) ($row['raw_description'] ?? $row['description'] ?? ''));
        $quantity = (float) ($row['raw_quantity'] ?? $row['quantity'] ?? 0);
        $unit = strtoupper(trim((string) ($row['raw_unit'] ?? $row['unit'] ?? '')));
        $commodityCode = trim((string) ($row['commodity_code'] ?? ''));
        $materialItemId = $row['material_item_id'] ?? null;
        $errors = [];
        $state = MtoStagingRow::ACCEPTABLE;

        $fail = fn (string $field, string $fa, string $en): never => throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);

        if ($mtoItemNo === '') {
            $fail('mto_item_no', 'شماره آیتم MTO در همه ردیف‌ها الزامی است.', 'Every MTO row needs an item number.');
        }

        if ($description === '') {
            $fail('raw_description', 'شرح خام ردیف MTO الزامی است.', 'Every MTO row needs a raw description.');
        }

        if ($quantity <= 0) {
            $state = MtoStagingRow::BLOCKED_QUANTITY_INVALID;
            $errors[] = 'QUANTITY_NOT_POSITIVE';
        }

        if ($unit === '') {
            $state = MtoStagingRow::BLOCKED_UNIT_INCOMPATIBLE;
            $errors[] = 'UNIT_MISSING';
        }

        $material = null;
        if ($materialItemId !== null && $materialItemId !== '') {
            $material = MaterialItem::query()
                ->forProjectCatalogue($project->id)
                ->active()
                ->whereKey($materialItemId)
                ->first();
        } elseif ($commodityCode !== '') {
            $material = $this->catalogue->findByCommodityCode($project, 'PIPING', $commodityCode);
        }

        if ($material === null) {
            $materialItemId = null;
            if ($state === MtoStagingRow::ACCEPTABLE) {
                $state = MtoStagingRow::BLOCKED_UNMATCHED_MATERIAL;
            }
            $errors[] = 'UNMATCHED_MATERIAL';
        } else {
            $materialItemId = $material->id;
            if ($unit !== '' && $unit !== $material->base_unit) {
                $state = MtoStagingRow::BLOCKED_UNIT_INCOMPATIBLE;
                $errors[] = 'UNIT_INCOMPATIBLE_WITH_MATERIAL';
            }
        }

        return [
            'line_no' => (int) ($row['line_no'] ?? $lineNo),
            'mto_item_no' => $mtoItemNo,
            'raw_description' => $description,
            'raw_quantity' => $quantity,
            'raw_unit' => $unit,
            'material_item_id' => $materialItemId,
            'row_scope_id' => $row['row_scope_id'] ?? null,
            'piping_class_id' => $row['piping_class_id'] ?? null,
            'validation_state' => $state,
            'validation_errors' => array_values(array_unique($errors)),
        ];
    }
}
