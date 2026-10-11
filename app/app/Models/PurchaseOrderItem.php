<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ordered line. */
final class PurchaseOrderItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'purchase_order_id', 'requisition_item_id',
        'subsystem_id', 'material_item_id', 'line_no', 'item_code',
        'description_en', 'description_fa', 'unit', 'quantity', 'unit_price',
        'supersedes_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function requisitionItem(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisitionItem::class, 'requisition_item_id');
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function materialItem(): BelongsTo
    {
        return $this->belongsTo(MaterialItem::class);
    }

    /** The line this one replaces (ADR-015 amendment lineage). */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    /** The line that replaces this one, if any. Absent = lineage head. */
    public function supersededBy(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'supersedes_id');
    }

    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PoItemAllocation::class, 'purchase_order_item_id');
    }

    public function description(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->description_fa : $this->description_en;
    }

    public function lineValue(): ?float
    {
        return $this->unit_price === null
            ? null
            : (float) $this->quantity * (float) $this->unit_price;
    }
}
