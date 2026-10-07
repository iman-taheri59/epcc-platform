<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcceptedMtoRow;
use App\Models\EngineeringEstimateRow;
use App\Models\MaterialItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records net engineering need and compares it to an accepted gross MTO row.
 *
 * Matching is by stable material identity and, when supplied, the immutable
 * accepted-row UUID. Descriptions and fuzzy matching never participate.
 */
final class EngineeringEstimateService
{
    /**
     * @param  array<string,mixed>  $attributes
     * @throws ValidationException
     */
    public function create(
        Project $project,
        User $user,
        array $attributes,
        string $lang = 'fa',
    ): EngineeringEstimateRow {
        $sourceType = strtoupper(trim((string) ($attributes['source_type'] ?? '')));
        $materialId = (string) ($attributes['material_item_id'] ?? '');
        $unit = strtoupper(trim((string) ($attributes['unit'] ?? '')));
        $netQuantity = (float) ($attributes['net_quantity'] ?? 0);
        $acceptedRowId = $attributes['accepted_mto_row_id'] ?? null;
        $scopeId = $attributes['scope_id'] ?? null;
        $provenance = $attributes['provenance'] ?? [];

        if (! in_array($sourceType, EngineeringEstimateRow::sourceTypes(), true)) {
            $this->fail($lang, 'source_type', 'منبع برآورد معتبر نیست.', 'Estimate source type is not recognised.');
        }

        if ($materialId === '') {
            $this->fail($lang, 'material_item_id', 'انتخاب متریال دقیق الزامی است.', 'An exact material item is required.');
        }

        if ($netQuantity <= 0) {
            $this->fail($lang, 'net_quantity', 'مقدار نیاز خالص باید بزرگ‌تر از صفر باشد.', 'Net quantity must be greater than zero.');
        }

        if ($unit === '') {
            $this->fail($lang, 'unit', 'واحد برآورد الزامی است.', 'Estimate unit is required.');
        }

        if (trim((string) ($attributes['source_revision'] ?? '')) === '' && trim((string) ($attributes['source_evidence'] ?? '')) === '') {
            $this->fail($lang, 'provenance', 'شماره بازنگری یا evidence منبع الزامی است.', 'A source revision or evidence reference is required.');
        }

        if (! is_array($provenance) || $provenance === [] || array_is_list($provenance)) {
            $this->fail($lang, 'provenance', 'provenance باید یک شیء غیرخالی باشد.', 'Provenance must be a non-empty object.');
        }

        $materialQuery = MaterialItem::query()
            ->forProjectCatalogue($project->id)
            ->active()
            ->whereKey($materialId);
        $material = $materialQuery->first();

        if ($material === null) {
            $this->fail($lang, 'material_item_id', 'متریال با identity دقیق پیدا نشد.', 'The exact material identity was not found.');
        }

        if ($unit !== $material->base_unit) {
            $this->fail(
                $lang,
                'unit',
                'واحد برآورد با واحد پایه متریال سازگار نیست.',
                'Estimate unit is incompatible with the material base unit.'
            );
        }

        $accepted = null;
        if ($acceptedRowId !== null && $acceptedRowId !== '') {
            $accepted = AcceptedMtoRow::query()->whereKey($acceptedRowId)->first();

            if ($accepted === null) {
                $this->fail($lang, 'accepted_mto_row_id', 'ردیف accepted MTO پیدا نشد.', 'Accepted MTO row was not found.');
            }

            if ($accepted->project_id !== $project->id) {
                $this->fail($lang, 'accepted_mto_row_id', 'ردیف accepted به این پروژه تعلق ندارد.', 'Accepted MTO row belongs to another project.');
            }

            if ($accepted->material_item_id !== $material->id || $accepted->unit !== $unit) {
                $this->fail(
                    $lang,
                    'accepted_mto_row_id',
                    'متریال و واحد برآورد با accepted MTO یکسان نیست.',
                    'Estimate material and unit do not match the accepted MTO row.'
                );
            }

            if ($accepted->scope_id !== $scopeId) {
                $this->fail(
                    $lang,
                    'scope_id',
                    'scope برآورد با scope ردیف accepted یکسان نیست.',
                    'Estimate scope does not match the accepted MTO scope.'
                );
            }
        }

        return DB::transaction(function () use (
            $project,
            $user,
            $attributes,
            $sourceType,
            $material,
            $accepted,
            $netQuantity,
            $unit,
            $scopeId,
            $provenance
        ): EngineeringEstimateRow {
            return EngineeringEstimateRow::create([
                'project_id' => $project->id,
                'company_id' => $user->company_id,
                'source_type' => $sourceType,
                'source_revision' => $attributes['source_revision'] ?? null,
                'source_evidence' => $attributes['source_evidence'] ?? null,
                'material_item_id' => $material->id,
                'accepted_mto_row_id' => $accepted?->id,
                'net_quantity' => $netQuantity,
                'unit' => $unit,
                'scope_id' => $scopeId,
                'piping_class_id' => $attributes['piping_class_id'] ?? null,
                'line_id' => $attributes['line_id'] ?? null,
                'provenance' => $provenance,
            ]);
        });
    }

    /**
     * Compare a net estimate with its immutable accepted gross row.
     *
     * There is deliberately no tolerance parameter. A positive net-minus-gross
     * is always reported as shortage; a larger gross is shown as delta/over-plan
     * and no waste or contingency cause is inferred.
     *
     * @return array{status:string,gross_quantity:?string,net_quantity:string,unit:string,net_minus_gross:?string,delta_quantity:?string,shortage_quantity:?string,over_plan_quantity:?string,accepted_mto_row_id:?string}
     * @throws ValidationException
     */
    public function compare(EngineeringEstimateRow $estimate, string $lang = 'fa'): array
    {
        $accepted = $estimate->relationLoaded('acceptedMtoRow')
            ? $estimate->acceptedMtoRow
            : $estimate->acceptedMtoRow()->first();

        if ($accepted === null) {
            return [
                'status' => 'PROVISIONAL',
                'gross_quantity' => null,
                'net_quantity' => $this->decimal((int) round((float) $estimate->net_quantity * 1000)),
                'unit' => (string) $estimate->unit,
                'net_minus_gross' => null,
                'delta_quantity' => null,
                'shortage_quantity' => null,
                'over_plan_quantity' => null,
                'accepted_mto_row_id' => null,
            ];
        }

        if (
            $accepted->material_item_id !== $estimate->material_item_id
            || $accepted->unit !== $estimate->unit
            || $accepted->scope_id !== $estimate->scope_id
        ) {
            $this->fail(
                $lang,
                'comparison',
                'مبنای متریال/واحد برای مقایسه سازگار نیست.',
                'Material/unit basis is incompatible for comparison.'
            );
        }

        $grossMilli = (int) round((float) $accepted->gross_quantity * 1000);
        $netMilli = (int) round((float) $estimate->net_quantity * 1000);
        $signedDelta = $netMilli - $grossMilli;

        return [
            'status' => $signedDelta > 0 ? 'SHORTAGE' : 'DELTA_ONLY',
            'gross_quantity' => $this->decimal($grossMilli),
            'net_quantity' => $this->decimal($netMilli),
            'unit' => (string) $estimate->unit,
            'net_minus_gross' => $this->decimal($signedDelta),
            'delta_quantity' => $this->decimal(abs($signedDelta)),
            'shortage_quantity' => $this->decimal(max($signedDelta, 0)),
            'over_plan_quantity' => $this->decimal(max(-$signedDelta, 0)),
            'accepted_mto_row_id' => $accepted->id,
        ];
    }

    /** @throws ValidationException */
    private function fail(string $lang, string $field, string $fa, string $en): never
    {
        throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);
    }

    private function decimal(int $milli): string
    {
        return number_format($milli / 1000, 3, '.', '');
    }
}
