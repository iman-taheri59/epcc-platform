<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AFC evidence: a CDE document that justifies a line's approval (ADR-006 §5).
 *
 * The link is deliberately explicit rather than implied by discipline or
 * number matching. "Which documents prove this line is approved?" must be
 * answerable by a query, because that is what an MC or TPI body asks.
 */
final class LineDocument extends Model
{
    use HasUuid;

    /** Evidence kinds the CHECK constraint accepts. */
    public const ISOMETRIC = 'ISOMETRIC';
    public const PID = 'PID';
    public const PMS = 'PMS';
    public const LINE_LIST = 'LINE_LIST';
    public const STRESS = 'STRESS';
    public const SUPPORT_LIST = 'SUPPORT_LIST';
    public const OTHER = 'OTHER';

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id', 'document_id', 'evidence_kind',
    ];

    /** @return list<string> */
    public static function kinds(): array
    {
        return [
            self::ISOMETRIC, self::PID, self::PMS,
            self::LINE_LIST, self::STRESS, self::SUPPORT_LIST, self::OTHER,
        ];
    }

    /**
     * The kinds that must be present and approved before a line can pass AFC.
     *
     * Only two. An isometric says how it is built; a P&ID says what it is for.
     * Requiring the full set (stress, support list) would block the gate on
     * documents that do not exist for every line - a small utility line has no
     * stress report - and a gate that cannot be passed gets bypassed.
     *
     * @return list<string>
     */
    public static function requiredForAfc(): array
    {
        return [self::ISOMETRIC, self::PID];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
