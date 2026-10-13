<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\MovementType;
use App\Support\ProcurementState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A traceable parcel of one item - the unit of stock.
 *
 * Stock is not "300 m of 6 inch pipe" but "120 m of heat A-4471". A TPI
 * signing a pressure-containing weld has to name the heat and produce its
 * certificate, so the heat number is the identity of the material, not a note
 * attached to it.
 */
final class MaterialLot extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'purchase_order_id', 'purchase_order_item_id',
        'subsystem_id', 'lot_code', 'item_code', 'description_en',
        'description_fa', 'unit', 'heat_number', 'serial_number',
        'mtc_document_id', 'inspection_result', 'inspection_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The custodian - whoever's warehouse holds it. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    /** The MTC, as a document in the CDE - not a loose file. */
    public function mtcDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'mtc_document_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(MaterialInspection::class, 'inspection_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MaterialMovement::class, 'lot_id');
    }

    public function description(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->description_fa : $this->description_en;
    }

    /**
     * Warehouse balance for this lot, from the ledger.
     *
     * DELIBERATELY LIVE, not a stored column. A cached balance and an
     * append-only ledger are two sources of truth that drift, and when they
     * disagree nobody can say which one lied. The same rule as the punch
     * register's closurePercent(): snapshot what a DOCUMENT asserts about a
     * moment, compute what a REGISTER shows now.
     *
     * Pass a pre-loaded movements collection when rendering a list, or this
     * is a query per row.
     */
    public function balance(?iterable $movements = null): float
    {
        $rows = $movements ?? $this->movements;
        $total = 0.0;

        foreach ($rows as $m) {
            $total += MovementType::sign($m->type) * (float) $m->quantity;
        }

        return round($total, 3);
    }

    /** Has the material physically reached the subsystem? */
    public function deliveredToSite(?iterable $movements = null): float
    {
        $rows = $movements ?? $this->movements;
        $total = 0.0;

        foreach ($rows as $m) {
            if ($m->type === MovementType::SITE) {
                $total += (float) $m->quantity;
            }
        }

        return round($total, 3);
    }

    /**
     * The warning every warehouse screen shows. NULL result means nobody has
     * inspected it, which is not the same as a pass and must not be rendered
     * as one.
     */
    public function inspectionNeedsAttention(): bool
    {
        return ProcurementState::needsAttention($this->inspection_result);
    }

    /** Traceability is only real if the certificate is actually attached. */
    public function isFullyTraceable(): bool
    {
        return ($this->heat_number !== null || $this->serial_number !== null)
            && $this->mtc_document_id !== null;
    }
}
