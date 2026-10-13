<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Mechanical Completion certificate (FR-COM-05, acceptance journey 5).
 */
final class McCertificate extends Model
{
    use HasUuid;

    public const DRAFT = 'DRAFT';
    public const ISSUED = 'ISSUED';

    protected $fillable = [
        'project_id', 'subsystem_id', 'company_id', 'number', 'sequence',
        'state', 'issued_by', 'issued_at', 'exception_approved_by',
        'exception_approved_at', 'exception_reason', 'exception_items',
        'punch_summary',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'exception_approved_at' => 'datetime',
        'exception_items' => 'array',
        'punch_summary' => 'array',
    ];

    /**
     * The punch-list summary to display, frozen once the certificate issues.
     *
     * A DRAFT is a working view: it should show where the subsystem stands
     * right now, because that is what somebody deciding whether to issue needs
     * to see. An ISSUED certificate is evidence of a moment, and its numbers
     * must not improve later just because the contractor kept working. Anyone
     * rendering these counts must come through here rather than recounting the
     * live collection.
     *
     * @param  \Illuminate\Support\Collection<int, PunchItem>  $items
     * @param  \Illuminate\Support\Collection<int, PunchItem>  $openBlocking
     * @return array{total: int, closed: int, open_blocking: int}
     */
    public function punchSummary($items, $openBlocking): array
    {
        if ($this->isIssued() && is_array($this->punch_summary)) {
            return [
                'total' => (int) ($this->punch_summary['total'] ?? 0),
                'closed' => (int) ($this->punch_summary['closed'] ?? 0),
                'open_blocking' => (int) ($this->punch_summary['open_blocking'] ?? 0),
            ];
        }

        return [
            'total' => $items->count(),
            'closed' => $items->filter(fn (PunchItem $i) => ! $i->isOpen())->count(),
            'open_blocking' => $openBlocking->count(),
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exception_approved_by');
    }

    public function isIssued(): bool
    {
        return $this->state === self::ISSUED;
    }

    public function isDraft(): bool
    {
        return $this->state === self::DRAFT;
    }

    /**
     * An exception counts only when it carries a reason AND an approver.
     * Checking one column would let a half-written exception open the gate,
     * and this is the single override on the whole completion process.
     */
    public function hasApprovedException(): bool
    {
        return $this->exception_approved_by !== null
            && $this->exception_approved_at !== null
            && trim((string) $this->exception_reason) !== '';
    }
}
