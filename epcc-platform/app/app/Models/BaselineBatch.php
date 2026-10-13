<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One immutable import of one source's claim about quantities (ADR-005 §2.2).
 *
 * A re-issue is a NEW batch, never an edit of this one. That is what makes
 * "which revision did we buy against" answerable, and it is the same discipline
 * the movement ledger already uses: never overwrite a claim, record the next
 * one alongside it.
 */
final class BaselineBatch extends Model
{
    use HasUuid;

    public const DRAFT = 'DRAFT';

    public const ACCEPTED = 'ACCEPTED';

    public const SUPERSEDED = 'SUPERSEDED';

    public const SOURCE_MTO = 'XLSX_MTO';

    public const SOURCE_LINELIST = 'XLSX_LINELIST';

    public const SOURCE_PCF = 'PCF';

    public const SOURCE_MANUAL = 'MANUAL';

    /**
     * How far each source is trusted, as a percentage.
     *
     * NOT a tie-breaker - the user ruled that no source wins automatically.
     * It orders the evidence for the person deciding, and lets a report say
     * "the only claim about this item is hand-typed".
     *
     * PCF is highest because it is generated from the geometry an isometric is
     * drawn from; a typed MTO is a human transcription of that same geometry
     * and can lag a revision behind.
     */
    public const CONFIDENCE = [
        self::SOURCE_PCF => 90,
        self::SOURCE_MTO => 70,
        self::SOURCE_LINELIST => 60,
        self::SOURCE_MANUAL => 40,
    ];

    protected $fillable = [
        'project_id', 'company_id', 'source_kind', 'filename', 'content_hash',
        'source_document_id', 'revision_label', 'piping_line_id', 'subsystem_id',
        'state', 'row_count', 'rejected_count', 'warnings',
        'imported_by', 'accepted_at', 'accepted_by',
    ];

    protected $casts = [
        'warnings' => 'array',
        'accepted_at' => 'datetime',
        'row_count' => 'integer',
        'rejected_count' => 'integer',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(EngineeringItem::class, 'baseline_batch_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function isAccepted(): bool
    {
        return $this->state === self::ACCEPTED;
    }

    public static function confidenceFor(string $sourceKind): int
    {
        return self::CONFIDENCE[$sourceKind] ?? 50;
    }
}
