<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One claim that a scope needs a quantity of an item (ADR-005 §2.1).
 *
 * The neutral baseline row. Its whole point is that the MTO's claim and the
 * model's claim live in the SAME table with the same shape, so a query can put
 * them side by side instead of a report having to know about two schemas.
 */
final class EngineeringItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'baseline_batch_id',
        'piping_line_id', 'subsystem_id',
        'item_code', 'component_type', 'nominal_size', 'nominal_size_decimal',
        'schedule', 'material_code', 'spec_code', 'description',
        'quantity', 'unit', 'source_kind', 'confidence',
        'external_ref', 'source_row',
    ];

    protected $casts = [
        'quantity' => 'float',
        'nominal_size_decimal' => 'float',
        'confidence' => 'integer',
        'source_row' => 'integer',
    ];

    /**
     * Inherit the batch's scope when the caller did not set it.
     *
     * Every read filters by `piping_line_id`, so a row created without one is
     * invisible for ever - it is in the table, it is in an ACCEPTED batch, and
     * it appears in no baseline and no shortage report. That silence is worse
     * than an error, and the batch already knows the answer.
     */
    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $batch = $item->baseline_batch_id === null
                ? null
                : ($item->relationLoaded('batch') ? $item->batch : BaselineBatch::find($item->baseline_batch_id));

            if ($batch === null) {
                return;
            }

            $item->piping_line_id ??= $batch->piping_line_id;
            $item->subsystem_id ??= $batch->subsystem_id;
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(BaselineBatch::class, 'baseline_batch_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    /** Only rows belonging to a batch somebody has accepted. */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->whereIn(
            'baseline_batch_id',
            BaselineBatch::query()->where('state', BaselineBatch::ACCEPTED)->select('id'),
        );
    }
}
