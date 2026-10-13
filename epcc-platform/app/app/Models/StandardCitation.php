<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This thing cites that standard, at that clause."
 *
 * Polymorphic because the user's instruction is that the library be usable
 * across the system, and the relationship is identical everywhere: an ITP row
 * cites ASME B31.3 §323, a piping class cites B16.5, a weld procedure cites
 * AWS D1.1. A table per consumer would be the same three columns and the same
 * RLS policy written five times.
 */
final class StandardCitation extends Model
{
    use HasUuid;

    /** An ITP row. The only host today. */
    public const ITP_STAGE = 'ITP_STAGE';

    /** @var list<string> */
    public const TYPES = [self::ITP_STAGE];

    protected $fillable = [
        'project_id', 'standard_id', 'citable_type', 'citable_id',
        'clause', 'sequence', 'source_edition',
    ];

    protected $casts = [
        'sequence' => 'integer',
    ];

    public static function typeIsValid(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /** @return BelongsTo<Standard, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The citation as printed on the row: 'ASME B31.3 §323.4'.
     *
     * Finalized registered hosts read the immutable revision snapshot; legacy
     * history never falls back to live data. Drafts prefer the source edition,
     * then the existing project-edition preview. Preview is not publication.
     *
     * Pass the adoption in rather than querying it here: rendering a grid
     * would otherwise fire one query per row.
     */
    public function printed(?ProjectStandard $adoption = null): string
    {
        $stage = $this->citable_type === self::ITP_STAGE ? ItpStage::find($this->citable_id) : null;
        $revision = $stage?->standardReferenceRevision();
        if ($revision !== null && $revision->standardBasisStatus() !== 'DRAFT') {
            foreach (($revision->frozenStandardStage($this->citable_id)['citations'] ?? []) as $item) {
                if ($item['citation_id'] === $this->id) {
                    return self::printFrozen($item);
                }
            }

            return __('epcc.standards.history_uncaptured');
        }
        $code = $this->standard?->code() ?? '';

        $edition = $this->source_edition ?: $adoption?->effectiveEdition();
        if ($edition !== null && $edition !== '') {
            $code .= '-'.$edition;
        }

        return $this->clause ? $code.' '.$this->clause : $code;
    }

    /** @param array<string, mixed> $item Captured identity; no model/catalogue reads. */
    public static function printFrozen(array $item): string
    {
        $code = trim($item['body'].' '.$item['designation']).'-'.$item['edition'];

        return ! empty($item['clause']) ? $code.' '.$item['clause'] : $code;
    }
}
