<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One explicit scope allocation of a PO line (ADR-015 R4).
 *
 * Allocations are recorded, never prorated: a row exists only because
 * somebody allocated that quantity to that subsystem. The remainder of the
 * item quantity is `unallocated` and is reported as such on the gap board.
 */
final class PoItemAllocation extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'purchase_order_item_id', 'subsystem_id',
        'quantity', 'created_by',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
