<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two sources disagree about one item, and nobody has decided yet.
 *
 * THE DELIVERABLE. Everything else in this phase exists so this list can be
 * produced. On a real project the disagreement IS the finding: it usually
 * means the isometric was revised and the MTO was not re-issued, which is the
 * error that puts 250 m of the wrong pipe on a truck.
 *
 * Per the user's ruling, no side wins automatically. The row stays OPEN, and
 * with it the baseline has no single number for that item, until a person
 * chooses one and says why.
 */
final class BaselineDiscrepancy extends Model
{
    use HasUuid;

    public const QUANTITY = 'QUANTITY';

    public const MISSING_IN_MODEL = 'MISSING_IN_MODEL';

    public const MISSING_IN_MTO = 'MISSING_IN_MTO';

    public const OPEN = 'OPEN';

    public const ACCEPTED_A = 'ACCEPTED_A';

    public const ACCEPTED_B = 'ACCEPTED_B';

    public const ACCEPTED_OTHER = 'ACCEPTED_OTHER';

    public const DISMISSED = 'DISMISSED';

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id', 'subsystem_id',
        'item_code', 'unit', 'kind',
        'source_a', 'quantity_a', 'batch_a_id',
        'source_b', 'quantity_b', 'batch_b_id',
        'difference', 'difference_pct',
        'state', 'resolved_quantity', 'resolution_note', 'resolved_by', 'resolved_at',
    ];

    protected $casts = [
        'quantity_a' => 'float',
        'quantity_b' => 'float',
        'difference' => 'float',
        'difference_pct' => 'float',
        'resolved_quantity' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function isOpen(): bool
    {
        return $this->state === self::OPEN;
    }

    /**
     * The quantity to plan and buy against.
     *
     * Null while the row is OPEN, and that is the point: an unresolved
     * disagreement must not silently resolve itself into a purchase order.
     */
    public function agreedQuantity(): ?float
    {
        return match ($this->state) {
            self::OPEN => null,
            self::DISMISSED => $this->quantity_a ?? $this->quantity_b,
            default => $this->resolved_quantity,
        };
    }
}
