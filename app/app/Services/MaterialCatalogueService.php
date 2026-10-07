<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MaterialItem;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stable material catalogue operations for the MTO foundation.
 *
 * MTO rows may later be accepted only after they point to one of these stable
 * identities. Aliases are retained as lookup hints, but this service never
 * treats an alias or a free-text description as the material identity.
 */
final class MaterialCatalogueService
{
    /**
     * Create or update a project overlay catalogue item.
     *
     * @param  array<string,mixed>  $data
     *
     * @throws ValidationException
     */
    public function upsertProjectItem(Project $project, array $data, string $lang = 'fa'): MaterialItem
    {
        $clean = $this->validateAndNormalise($data, $lang);

        return DB::transaction(function () use ($project, $clean): MaterialItem {
            $item = MaterialItem::query()
                ->where('project_id', $project->id)
                ->where('discipline', $clean['discipline'])
                ->where('commodity_code', $clean['commodity_code'])
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                return MaterialItem::create(['project_id' => $project->id] + $clean);
            }

            $item->update($clean);

            return $item->refresh();
        });
    }

    /**
     * Find a material by exact commodity code, preferring the project overlay.
     *
     * Aliases are deliberately not searched here. They can help a human locate
     * a candidate, but downstream MR/MTO/PO links must resolve to the real
     * commodity code / immutable row identity, not a vendor nickname.
     */
    public function findByCommodityCode(Project $project, string $discipline, string $commodityCode): ?MaterialItem
    {
        $discipline = strtoupper(trim($discipline));
        $commodityCode = strtoupper(trim($commodityCode));

        return MaterialItem::query()
            ->forProjectCatalogue($project->id)
            ->active()
            ->where('discipline', $discipline)
            ->where('commodity_code', $commodityCode)
            ->orderByRaw('CASE WHEN project_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }

    /** @return Builder<MaterialItem> */
    public function queryForProject(Project $project, string $discipline = 'PIPING'): Builder
    {
        return MaterialItem::query()
            ->forProjectCatalogue($project->id)
            ->where('discipline', strtoupper(trim($discipline)))
            ->orderBy('category')
            ->orderBy('commodity_code');
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    private function validateAndNormalise(array $data, string $lang): array
    {
        $discipline = strtoupper(trim((string) ($data['discipline'] ?? 'PIPING')));
        $category = strtoupper(trim((string) ($data['category'] ?? '')));
        $commodityCode = strtoupper(trim((string) ($data['commodity_code'] ?? '')));
        $descriptionEn = trim((string) ($data['description_en'] ?? ''));
        $descriptionFa = trim((string) ($data['description_fa'] ?? ''));
        $baseUnit = strtoupper(trim((string) ($data['base_unit'] ?? '')));
        $source = strtoupper(trim((string) ($data['source'] ?? MaterialItem::SOURCE_PROJECT_CATALOGUE)));
        $status = strtoupper(trim((string) ($data['status'] ?? MaterialItem::STATUS_ACTIVE)));

        $fail = fn (string $field, string $fa, string $en): never => throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);

        if ($discipline === '') {
            $fail('discipline', 'رشتهٔ کالا الزامی است.', 'Material discipline is required.');
        }

        if (! in_array($category, MaterialItem::categories(), true)) {
            $fail('category', 'گروه کالایی معتبر نیست.', 'Material category is not recognised.');
        }

        if ($commodityCode === '') {
            $fail('commodity_code', 'کد کالا الزامی است.', 'Commodity code is required.');
        }

        if ($descriptionEn === '' || $descriptionFa === '') {
            $fail('description', 'شرح کالا به هر دو زبان الزامی است.', 'Material description is required in both languages.');
        }

        if (! in_array($baseUnit, MaterialItem::units(), true)) {
            $fail('base_unit', 'واحد پایهٔ کالا باید M، EA یا KG باشد.', 'Base unit must be M, EA or KG.');
        }

        if (! in_array($source, MaterialItem::sources(), true)) {
            $fail('source', 'منبع کاتالوگ معتبر نیست.', 'Catalogue source is not recognised.');
        }

        if (! in_array($status, MaterialItem::statuses(), true)) {
            $fail('status', 'وضعیت کالا معتبر نیست.', 'Material status is not recognised.');
        }

        $aliases = $data['alias_codes'] ?? [];
        if (! is_array($aliases)) {
            $fail('alias_codes', 'کدهای جایگزین باید آرایه باشند.', 'Alias codes must be an array.');
        }

        $aliases = array_values(array_unique(array_filter(array_map(
            fn (mixed $v): string => strtoupper(trim((string) $v)),
            $aliases
        ))));

        return [
            'reference_item_id' => $data['reference_item_id'] ?? null,
            'discipline' => $discipline,
            'category' => $category,
            'commodity_code' => $commodityCode,
            'description_en' => $descriptionEn,
            'description_fa' => $descriptionFa,
            'base_unit' => $baseUnit,
            'size_nps' => $this->nullableString($data['size_nps'] ?? null),
            'size_dn' => $this->nullableString($data['size_dn'] ?? null),
            'schedule' => $this->nullableString($data['schedule'] ?? null),
            'rating' => $this->nullableString($data['rating'] ?? null),
            'class_code' => $this->nullableString($data['class_code'] ?? null),
            'material_spec' => $this->nullableString($data['material_spec'] ?? null),
            'grade' => $this->nullableString($data['grade'] ?? null),
            'source' => $source,
            'status' => $status,
            'alias_codes' => $aliases,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
