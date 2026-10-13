<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\ProcurementState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An inspection record (بازرسی).
 *
 * ADVISORY by the user's explicit choice: this never blocks a receipt. It is
 * carried forward onto the lot so that every warehouse screen can show the
 * verdict without a join, because an advisory control that is not visible is
 * not a control at all.
 */
final class MaterialInspection extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'purchase_order_id', 'number', 'sequence',
        'result', 'stage', 'findings_en', 'findings_fa',
        'inspected_by', 'inspected_at',
    ];

    protected $casts = ['inspected_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The inspecting body: TPI, PMC, MC or the owner. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function passed(): bool
    {
        return $this->result === ProcurementState::INSPECTION_PASS;
    }

    public function needsAttention(): bool
    {
        return ProcurementState::needsAttention($this->result);
    }

    public function findings(string $lang = 'fa'): ?string
    {
        return $lang === 'fa' ? $this->findings_fa : $this->findings_en;
    }
}
