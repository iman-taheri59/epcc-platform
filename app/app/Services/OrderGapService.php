<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcceptedMtoRow;
use App\Models\MaterialItem;
use App\Models\PoItemAllocation;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\ProcurementState;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ADR-015 (MTO Slice 6) — PO attribution, amendment lineage, scope
 * allocation and the order gap.
 *
 *   Order Gap = Accepted Gross MTO Quantity − Valid Issued/In-force Net PO Qty
 *
 * The accepted domain rules, restated because every branch below hangs on
 * them: draft/cancelled POs contribute nothing; an amendment replaces the
 * previous quantity (lineage head only); ambiguous scope stays unallocated;
 * there is no tolerance and no automatic proration; an order above the MTO
 * is OVER_PLAN, never a negative shortage; an unmatched PO line is kept in
 * its own bucket, never guessed onto a material.
 */
final class OrderGapService
{
    /** Effective = issued and not cancelled (ADR-015 §5: a predicate, not a state). */
    private const EFFECTIVE_STATES = [
        ProcurementState::PO_ISSUED,
        ProcurementState::PO_PARTIALLY_RECEIVED,
    ];

    // ------------------------------------------------------------------
    // Attribution (R1)
    // ------------------------------------------------------------------

    /**
     * Resolve a PO line onto the Material Library by exact identity.
     * The unit must match the catalogue base unit — a mismatch is a
     * validation failure, not a conversion.
     *
     * @throws ValidationException
     */
    public function attribute(
        Project $project,
        PurchaseOrderItem $item,
        string $materialId,
        string $lang = 'fa',
    ): PurchaseOrderItem {
        $this->assertHead($item, $lang);

        $material = MaterialItem::query()
            ->forProjectCatalogue($project->id)
            ->active()
            ->whereKey($materialId)
            ->first();

        if ($material === null) {
            $this->fail($lang, 'material_item_id',
                'متریال با هویت دقیق در کتابخانه پیدا نشد.',
                'No exact material identity was found in the library.');
        }

        if (strtoupper(trim($item->unit)) !== $material->base_unit) {
            $this->fail($lang, 'unit',
                'واحد خط سفارش با واحد پایهٔ متریال سازگار نیست.',
                'The PO line unit is incompatible with the material base unit.');
        }

        $item->update(['material_item_id' => $material->id]);

        return $item->refresh();
    }

    // ------------------------------------------------------------------
    // Amendment lineage (R3)
    // ------------------------------------------------------------------

    /**
     * Replace the quantity of a line by creating its successor. The old row
     * is untouched and stays readable; the gap reads the head only.
     *
     * @throws ValidationException
     */
    public function amend(
        PurchaseOrderItem $item,
        float $quantity,
        string $lang = 'fa',
    ): PurchaseOrderItem {
        $this->assertHead($item, $lang);

        if ($quantity <= 0) {
            $this->fail($lang, 'quantity',
                'مقدار اصلاحیه باید بزرگ‌تر از صفر باشد.',
                'Amendment quantity must be greater than zero.');
        }

        $po = $item->purchaseOrder()->first();

        if ($po === null || ! in_array($po->state, [
            ProcurementState::PO_DRAFT,
            ProcurementState::PO_ISSUED,
            ProcurementState::PO_PARTIALLY_RECEIVED,
        ], true)) {
            $this->fail($lang, 'purchase_order_id',
                'این سفارش خرید دیگر قابل اصلاح نیست.',
                'This purchase order can no longer be amended.');
        }

        return DB::transaction(function () use ($item, $po, $quantity): PurchaseOrderItem {
            $lock = PurchaseOrderItem::whereKey($item->id)->lockForUpdate()->first();

            // Re-check inside the lock: a concurrent amendment must lose
            // loudly, and the unique index on supersedes_id is the backstop.
            if ($lock === null || $lock->supersededBy()->exists()) {
                $this->fail('en', 'supersedes_id',
                    'این خط قبلاً اصلاح شده است.',
                    'This line has already been superseded.');
            }

            $nextLine = 1 + (int) PurchaseOrderItem::where('purchase_order_id', $po->id)->max('line_no');

            return PurchaseOrderItem::create([
                'project_id' => $item->project_id,
                'purchase_order_id' => $po->id,
                'requisition_item_id' => $item->requisition_item_id,
                'subsystem_id' => $item->subsystem_id,
                'material_item_id' => $item->material_item_id,
                'line_no' => $nextLine,
                'item_code' => $item->item_code,
                'description_en' => $item->description_en,
                'description_fa' => $item->description_fa,
                'unit' => $item->unit,
                'quantity' => $quantity,
                'unit_price' => $item->unit_price,
                'supersedes_id' => $item->id,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Scope allocation (R4)
    // ------------------------------------------------------------------

    /**
     * Allocate part of an item's quantity to a subsystem. The service never
     * prorates: quantities are recorded exactly as given, and any remainder
     * remains `unallocated` on the board.
     *
     * @throws ValidationException
     */
    public function allocate(
        Project $project,
        User $user,
        PurchaseOrderItem $item,
        string $subsystemId,
        float $quantity,
        string $lang = 'fa',
    ): PoItemAllocation {
        $this->assertHead($item, $lang);

        if ($item->material_item_id === null) {
            $this->fail($lang, 'material_item_id',
                'ابتدا هویت متریال خط سفارش را مشخص کنید.',
                'Attribute the PO line to a material before allocating scope.');
        }

        if ($quantity <= 0) {
            $this->fail($lang, 'quantity',
                'مقدار تخصیص باید بزرگ‌تر از صفر باشد.',
                'Allocation quantity must be greater than zero.');
        }

        $po = $item->purchaseOrder()->first();

        if ($po === null || ! in_array($po->state, self::EFFECTIVE_STATES, true)) {
            $this->fail($lang, 'purchase_order_id',
                'تخصیص فقط برای سفارش‌های صادرشده/در حال اجرا ممکن است.',
                'Only issued/in-force orders can carry a scope allocation.');
        }

        $subsystem = Subsystem::query()->whereKey($subsystemId)->first();

        if ($subsystem === null || $subsystem->project_id !== $project->id) {
            $this->fail($lang, 'subsystem_id',
                'زیرسیستم در این پروژه پیدا نشد.',
                'The subsystem was not found on this project.');
        }

        return DB::transaction(function () use ($project, $user, $item, $subsystem, $quantity, $lang): PoItemAllocation {
            $locked = PurchaseOrderItem::whereKey($item->id)->lockForUpdate()->first();
            $allocatedMilli = (int) PoItemAllocation::where('purchase_order_item_id', $item->id)
                ->lockForUpdate()
                ->get()
                ->sum(fn (PoItemAllocation $a) => $this->milli((float) $a->quantity));

            $itemMilli = $locked === null ? 0 : $this->milli((float) $locked->quantity);

            if ($allocatedMilli + $this->milli($quantity) > $itemMilli) {
                $this->fail($lang, 'quantity',
                    'مجموع تخصیص‌ها نمی‌تواند از مقدار خط سفارش بیشتر شود؛ تسهیم خودکار وجود ندارد.',
                    'Allocations cannot exceed the PO line quantity; there is no automatic proration.');
            }

            $existing = PoItemAllocation::where('purchase_order_item_id', $item->id)
                ->where('subsystem_id', $subsystem->id)
                ->first();

            if ($existing !== null) {
                $existing->update([
                    'quantity' => $quantity,
                    'created_by' => $user->id,
                ]);

                return $existing->refresh();
            }

            return PoItemAllocation::create([
                'project_id' => $project->id,
                'purchase_order_item_id' => $item->id,
                'subsystem_id' => $subsystem->id,
                'quantity' => $quantity,
                'created_by' => $user->id,
            ]);
        });
    }

    // ------------------------------------------------------------------
    // The board (R5)
    // ------------------------------------------------------------------

    /**
     * Per-material gap rows plus the unmatched bucket. Gross comes from the
     * immutable accepted rows; ordered comes from lineage heads on
     * issued/in-force orders only. All arithmetic is integer milli-units.
     *
     * @return array{rows: list<array<string,mixed>>, totals: array{materials:int,shortage:int,covered:int,over_plan:int,unmatched:int}}
     */
    public function board(Project $project): array
    {
        /** @var array<string, array{gross:int, unit:string, label_en:string, label_fa:string, commodity:string}> $gross */
        $gross = [];

        AcceptedMtoRow::query()
            ->where('project_id', $project->id)
            ->get()
            ->each(function (AcceptedMtoRow $row) use (&$gross): void {
                // An accepted row without a resolved identity cannot be
                // compared with orders; it belongs to the catalogue audit,
                // not to this board.
                if ($row->material_item_id === null) {
                    return;
                }

                $key = $row->material_item_id.'|'.$row->unit;
                $entry = $gross[$key] ?? [
                    'gross' => 0,
                    'unit' => $row->unit,
                    'label_en' => $row->material_description_en,
                    'label_fa' => $row->material_description_fa,
                    'commodity' => $row->material_commodity_code,
                    'material_item_id' => $row->material_item_id,
                ];
                $entry['gross'] += $this->milli((float) $row->gross_quantity);
                $gross[$key] = $entry;
            });

        /** @var array<string, array{ordered:int, allocated:int}> $ordered */
        $ordered = [];
        $unmatched = [];

        PurchaseOrderItem::query()
            ->with(['purchaseOrder', 'allocations'])
            ->where('project_id', $project->id)
            ->get()
            ->filter(fn (PurchaseOrderItem $item) => $this->isEffective($item))
            ->each(function (PurchaseOrderItem $item) use (&$ordered, &$unmatched): void {
                if ($item->material_item_id === null) {
                    $code = (string) $item->item_code;
                    $bucket = $unmatched[$code] ?? [
                        'item_code' => $code,
                        'unit' => (string) $item->unit,
                        'ordered' => 0,
                        'description_en' => (string) $item->description_en,
                        'description_fa' => (string) $item->description_fa,
                    ];
                    $bucket['ordered'] += $this->milli((float) $item->quantity);
                    $unmatched[$code] = $bucket;

                    return;
                }

                $key = $item->material_item_id.'|'.$item->unit;
                $entry = $ordered[$key] ?? ['ordered' => 0, 'allocated' => 0];
                $entry['ordered'] += $this->milli((float) $item->quantity);
                $entry['allocated'] += (int) $item->allocations->sum(
                    fn (PoItemAllocation $a) => $this->milli((float) $a->quantity)
                );
                $ordered[$key] = $entry;
            });

        $rows = [];

        foreach ($gross as $key => $g) {
            $o = $ordered[$key] ?? ['ordered' => 0, 'allocated' => 0];
            $gap = $g['gross'] - $o['ordered'];
            $rows[] = [
                'material_item_id' => $g['material_item_id'] ?? null,
                'commodity_code' => $g['commodity'],
                'description_en' => $g['label_en'],
                'description_fa' => $g['label_fa'],
                'unit' => $g['unit'],
                'gross' => $this->decimal($g['gross']),
                'ordered' => $this->decimal($o['ordered']),
                'gap' => $this->decimal($gap),
                'state' => $gap > 0 ? 'SHORTAGE' : ($gap === 0 ? 'COVERED' : 'OVER_PLAN'),
                'allocated' => $this->decimal($o['allocated']),
                'unallocated' => $this->decimal(max($o['ordered'] - $o['allocated'], 0)),
            ];
        }

        // Ordered without an accepted MTO counterpart is over-plan by
        // construction and must be visible, not dropped.
        foreach ($ordered as $key => $o) {
            if (isset($gross[$key])) {
                continue;
            }

            [$materialId] = explode('|', $key, 2);
            $material = MaterialItem::query()->whereKey($materialId)->first();
            $rows[] = [
                'material_item_id' => $materialId,
                'commodity_code' => $material?->commodity_code ?? '—',
                'description_en' => $material?->description_en ?? '—',
                'description_fa' => $material?->description_fa ?? '—',
                'unit' => substr($key, strlen($materialId) + 1),
                'gross' => $this->decimal(0),
                'ordered' => $this->decimal($o['ordered']),
                'gap' => $this->decimal(-$o['ordered']),
                'state' => 'OVER_PLAN',
                'allocated' => $this->decimal($o['allocated']),
                'unallocated' => $this->decimal(max($o['ordered'] - $o['allocated'], 0)),
            ];
        }

        usort($rows, static fn (array $a, array $b) => strcmp((string) $a['commodity_code'], (string) $b['commodity_code']));

        $unmatchedRows = [];

        foreach ($unmatched as $bucket) {
            $unmatchedRows[] = [
                'material_item_id' => null,
                'commodity_code' => $bucket['item_code'],
                'description_en' => $bucket['description_en'],
                'description_fa' => $bucket['description_fa'],
                'unit' => $bucket['unit'],
                'gross' => null,
                'ordered' => $this->decimal($bucket['ordered']),
                'gap' => null,
                'state' => 'UNMATCHED',
                'allocated' => $this->decimal(0),
                'unallocated' => $this->decimal($bucket['ordered']),
            ];
        }

        $all = array_merge($rows, $unmatchedRows);

        return [
            'rows' => $all,
            'totals' => [
                'materials' => count($rows),
                'shortage' => count(array_filter($rows, static fn (array $r) => $r['state'] === 'SHORTAGE')),
                'covered' => count(array_filter($rows, static fn (array $r) => $r['state'] === 'COVERED')),
                'over_plan' => count(array_filter($rows, static fn (array $r) => $r['state'] === 'OVER_PLAN')),
                'unmatched' => count($unmatchedRows),
            ],
        ];
    }

    // ------------------------------------------------------------------

    /** Effective = lineage head on an issued/in-force order (R2/R3). */
    private function isEffective(PurchaseOrderItem $item): bool
    {
        $po = $item->purchaseOrder;

        if ($po === null || ! in_array($po->state, self::EFFECTIVE_STATES, true)) {
            return false;
        }

        return ! $item->supersededBy()->exists();
    }

    /** @throws ValidationException */
    private function assertHead(PurchaseOrderItem $item, string $lang): void
    {
        if ($item->exists && $item->supersededBy()->exists()) {
            $this->fail($lang, 'supersedes_id',
                'این خط با اصلاحیه جایگزین شده است؛ عملیات روی نسخهٔ جدید انجام شود.',
                'This line has been superseded; operate on its successor instead.');
        }
    }

    /** @throws ValidationException */
    private function fail(string $lang, string $field, string $fa, string $en): never
    {
        throw ValidationException::withMessages([
            $field => $lang === 'fa' ? $fa : $en,
        ]);
    }

    private function milli(float $value): int
    {
        return (int) round($value * 1000);
    }

    private function decimal(int $milli): string
    {
        return number_format($milli / 1000, 3, '.', '');
    }
}
