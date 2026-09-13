<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\ProcurementState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase order placed with a vendor.
 *
 * RLS lets the named vendor read its own order and nothing else - vendors on
 * one project are frequently competitors.
 */
final class PurchaseOrder extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'vendor_company_id', 'number', 'sequence',
        'state', 'requisition_id', 'title_en', 'title_fa', 'total_value',
        'currency', 'promised_date', 'forecast_date', 'issued_by', 'issued_at',
    ];

    protected $casts = [
        'promised_date' => 'date',
        'forecast_date' => 'date',
        'issued_at' => 'datetime',
        'total_value' => 'decimal:2',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The BUYER. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** The VENDOR the order is placed with. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'vendor_company_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisition::class, 'requisition_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function lots(): HasMany
    {
        return $this->hasMany(MaterialLot::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(MaterialInspection::class);
    }

    public function title(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->title_fa : $this->title_en;
    }

    public function acceptsReceipts(): bool
    {
        return ProcurementState::poAcceptsReceipts($this->state);
    }

    /**
     * Expediting: is the vendor forecasting later than it promised?
     *
     * Returns null when either date is missing rather than guessing. "No
     * forecast" and "on time" are different facts and a register that renders
     * them the same teaches the reader to distrust the column.
     */
    public function slipDays(): ?int
    {
        if ($this->promised_date === null || $this->forecast_date === null) {
            return null;
        }

        return (int) $this->promised_date->diffInDays($this->forecast_date, false);
    }

    public function isLate(): bool
    {
        return ($this->slipDays() ?? 0) > 0;
    }
}
