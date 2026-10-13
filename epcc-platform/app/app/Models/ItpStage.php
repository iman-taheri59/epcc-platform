<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/** One numbered row of an ITP: an activity with acceptance criteria. */
final class ItpStage extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'itp_id', 'clause', 'sequence',
        'title_en', 'title_fa', 'acceptance_en', 'acceptance_fa',
        'method', 'frequency_en', 'frequency_fa', 'reference',
        'record_en', 'record_fa', 'form_number', 'notice_hours',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'notice_hours' => 'integer',
    ];

    public function itp(): BelongsTo
    {
        return $this->belongsTo(Itp::class, 'itp_id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(ItpStageParty::class, 'itp_stage_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(ItpRecord::class, 'itp_stage_id');
    }

    /**
     * Project documents cited by this row: specs, drawings, procedures and the
     * report form. External standards live in the `reference` string instead —
     * we do not own them and they are not in the register.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(ItpStageDocument::class, 'itp_stage_id');
    }

    /** @return Collection<int, ItpStageDocument> */
    public function documentsFor(string $usage): Collection
    {
        return $this->documents->where('usage', $usage)->values();
    }

    /**
     * The codes and standards cited by this row, from the library.
     *
     * Polymorphic, so the relation is declared by hand rather than with
     * morphMany: `citable_type` holds our own short constant ('ITP_STAGE'),
     * not a class name. Storing a FQCN in the database would mean a namespace
     * rename silently orphans every citation.
     *
     * @return HasMany<StandardCitation, $this>
     */
    public function standardCitations(): HasMany
    {
        return $this->hasMany(StandardCitation::class, 'citable_id')
            ->where('citable_type', StandardCitation::ITP_STAGE)
            ->orderBy('sequence');
    }

    /**
     * The standards column as printed: 'ASME B31.3 §323 · EN 10204'.
     *
     * Library citations first, then whatever free text remains. Both, because
     * the free-text column is the escape hatch for standards nobody has added
     * to the library yet, and dropping it would lose references mid-project.
     *
     * @param  array<string, ProjectStandard>  $adoptions  keyed by standard_id,
     *                                                     passed in so a grid does not fire a query per row.
     */
    public function standardReferenceRevision(): ?DocumentRevision
    {
        $itp = $this->itp;
        if ($itp?->document_id === null) {
            return null;
        }
        $revision = $itp->document?->currentRevision;
        if ($revision === null) {
            throw new \LogicException('Registered reference context is unavailable; refusing live fallback.');
        }

        return $revision;
    }

    public function printedStandards(array $adoptions = [], ?DocumentRevision $revision = null): string
    {
        if ($revision !== null && ($revision->document_id !== $this->itp?->document_id || $revision->project_id !== $this->project_id)) {
            throw new \InvalidArgumentException('Revision is not this stage’s document.');
        }
        $revision ??= $this->standardReferenceRevision();
        if ($revision !== null && $revision->standardBasisStatus() !== 'DRAFT') {
            $captured = $revision->frozenStandardStage($this->id);
            if ($captured === null) {
                return __('epcc.standards.history_uncaptured');
            }

            return implode(' · ', array_map(StandardCitation::printFrozen(...), $captured['citations']));
        }
        $parts = $this->standardCitations
            ->map(fn (StandardCitation $c) => $c->printed($adoptions[$c->standard_id] ?? null))
            ->filter()
            ->all();

        if ($this->reference) {
            $parts[] = $this->reference;
        }

        return implode(' · ', $parts);
    }

    /**
     * The inspection report form for this row.
     *
     * A registered form document wins over the printed number, because the
     * register carries the revision and a bare number does not. Returns the
     * plain string when the form was never registered — which on a real
     * project is most client-issued forms.
     */
    public function formLabel(): ?string
    {
        $registered = $this->documentsFor(ItpStageDocument::FORM)->first();

        if ($registered?->document !== null) {
            return $registered->document->number;
        }

        return $this->form_number;
    }

    public function title(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->title_fa : $this->title_en;
    }

    public function acceptance(string $lang = 'fa'): ?string
    {
        return $lang === 'fa' ? $this->acceptance_fa : $this->acceptance_en;
    }

    /**
     * Does any party hold this stage?
     *
     * Asked of the STAGE rather than of a code, because "is this a hold point"
     * is a property of the row as printed: if the TPI holds it, the row is a
     * hold point even though the vendor only monitors it.
     */
    public function isHoldPoint(): bool
    {
        return $this->parties->contains(fn (ItpStageParty $p): bool => $p->blocks());
    }

    /** @return list<string> tenant types that must sign before this stage passes */
    public function holdingParties(): array
    {
        return $this->parties
            ->filter(fn (ItpStageParty $p): bool => $p->blocks())
            ->pluck('tenant_type')
            ->values()
            ->all();
    }
}
