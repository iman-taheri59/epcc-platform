<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Two ports, joined (ADR-006 section 5.3).
 *
 * The pair is ORDERED - port_a_id < port_b_id, enforced by a CHECK - so that
 * (A,B) and (B,A) cannot both exist and be counted twice. Nothing in the
 * application should construct a Joint directly; JointService::join() sorts the
 * pair before it writes.
 */
final class Joint extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id',
        'port_a_id', 'port_b_id',
        'joint_seq', 'numbering_policy',
        'joint_type', 'state',
        'fitted_by_code', 'fitted_at',
        'nominal_size', 'nominal_size_decimal', 'schedule', 'thickness_mm',
        'shop_field', 'spool_id', 'isometric_sheet_no',
        'compatibility_state', 'compatibility_notes',
        'source', 'external_ref', 'created_by',
    ];

    protected $casts = [
        'joint_seq' => 'integer',
        'nominal_size_decimal' => 'float',
        'thickness_mm' => 'float',
        'compatibility_notes' => 'array',
        'fitted_at' => 'datetime',
    ];

    public const STATE_MODELLED = 'MODELLED';

    public const STATE_FITTED = 'FITTED';

    public const NUMBERING_REQUIRED = 'REQUIRED';

    public const NUMBERING_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public function portA(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_a_id');
    }

    public function portB(): BelongsTo
    {
        return $this->belongsTo(Port::class, 'port_b_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function scopeOnLine(Builder $query, string $lineId): Builder
    {
        return $query->where('piping_line_id', $lineId);
    }

    /**
     * Joints that should have a number and do not - the chase-list.
     *
     * Distinct from "unnumbered": a flanged joint is unnumbered forever and
     * correctly so. Only REQUIRED rows belong on anyone's worklist.
     */
    public function scopeAwaitingNumber(Builder $query): Builder
    {
        return $query->where('numbering_policy', self::NUMBERING_REQUIRED)->whereNull('joint_seq');
    }

    /**
     * The number as people write it: zero-padded to three.
     *
     * Padding lives here and not in the column, so that sorting and "next
     * number" stay integer arithmetic (ADR-006 section 11.1).
     */
    public function displayNumber(): ?string
    {
        return $this->joint_seq === null ? null : str_pad((string) $this->joint_seq, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Does this joint contribute to welded progress?
     *
     * Only welds do. A flanged joint is real work, but it is bolt-up work and
     * belongs in a different statement - counting it as dia-inch would inflate
     * the welding figure the project is paid against.
     */
    public function isWeld(): bool
    {
        return in_array($this->joint_type, ['BUTT_WELD', 'SOCKET_WELD', 'FABRICATED'], true);
    }

    public function isFitted(): bool
    {
        return $this->state === self::STATE_FITTED;
    }

    /** The other end of the joint, given one of its ports. */
    public function counterpartOf(string $portId): ?string
    {
        return match ($portId) {
            $this->port_a_id => $this->port_b_id,
            $this->port_b_id => $this->port_a_id,
            default => null,
        };
    }
}
