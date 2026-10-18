<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One-dimensional PMS rule: a half-open DN band resolving to a value
 * (ADR-008 §3). `dn_range` is an int4range held as its canonical text form,
 * e.g. "[100,250)". Overlap inside one class and kind is refused by the
 * EXCLUDE constraint, not by application code.
 */
final class PipingClassRule extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'piping_class_id', 'rule_kind',
        'dn_range', 'value', 'note_en', 'note_fa', 'source_ref', 'created_by',
    ];

    protected $casts = [
        'value' => 'array',
    ];

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class);
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
}
