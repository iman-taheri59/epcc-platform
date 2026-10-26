<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaterialItem;
use App\Models\Project;
use Illuminate\Validation\ValidationException;

/** ADR-034: declared category is not proof of physical identity for free-text lines. */
final class ProcurementCategoryService
{
    public function validateLines(Project $project, array $data): void
    {
        foreach ($data['lines'] as $i => $line) {
            if (isset($line['material_category']) && $line['material_category'] !== $data['material_category']) {
                throw ValidationException::withMessages(["lines.$i.material_category" => __('procurement_category.mismatch')]);
            }
            if (! empty($line['material_item_id'])) {
                $material = MaterialItem::query()->forProjectCatalogue($project->id)->active()
                    ->whereKey($line['material_item_id'])->sharedLock()->first();
                if (! $material || $material->category !== $data['material_category']
                    || $material->discipline !== $data['discipline']
                    || $material->commodity_code !== $line['item_code'] || $material->base_unit !== $line['unit']) {
                    throw ValidationException::withMessages(["lines.$i.material_item_id" => __('procurement_category.identity_mismatch')]);
                }
            }
        }
    }
}
