<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\ProcurementState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A material requisition: "we need this material" (تهیه). */
final class MaterialRequisition extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'number', 'sequence', 'state',
        'material_category', 'title_en', 'title_fa', 'discipline', 'raised_by', 'raised_at',
        'technical_approved_by', 'technical_approved_at',
        'commercial_approved_by', 'commercial_approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason',
    ];

    protected $casts = [
        'raised_at' => 'datetime',
        'technical_approved_at' => 'datetime',
        'commercial_approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MaterialRequisitionItem::class, 'requisition_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'requisition_id');
    }

    public function title(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->title_fa : $this->title_en;
    }

    /** Can a buyer still raise a PO against this? */
    public function isOrderable(): bool
    {
        return $this->state === ProcurementState::MR_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->state === ProcurementState::MR_REJECTED;
    }

    /**
     * Total requisitioned quantity. Summed in PHP over the loaded relation
     * rather than with a DB aggregate, because callers already eager-load
     * items for display and a second query per row is how a register becomes
     * slow at 400 lines.
     */
    public function totalQuantity(): float
    {
        return (float) $this->items->sum(fn ($i) => (float) $i->quantity);
    }
}
