<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Branch-table cell: `(header_range × branch_range) → component_type`
 * (ADR-008 §4). Overlap is the normal general-plus-exception shape; the
 * resolver picks the narrowest footprint, with `priority` as the explicit
 * tiebreak for equally narrow rules.
 */
final class PipingClassBranchRule extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'piping_class_id', 'header_range',
        'branch_range', 'component_type', 'p2p_kind', 'reinforcement',
        'pad_thickness', 'value', 'source_ref', 'priority', 'created_by',
    ];

    protected $casts = [
        'value' => 'array',
        'priority' => 'integer',
        'pad_thickness' => 'decimal:3',
    ];

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class);
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
