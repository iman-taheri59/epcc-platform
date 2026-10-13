<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project's Inspection and Test Plan for one discipline.
 *
 * Piping and Pipeline each get their own, by the user's instruction: they are
 * different disciplines with different codes (B31.3 vs B31.4/B31.8), different
 * inspection bodies and different acceptance criteria, and folding them into
 * one "piping" plan would force one set of hold points onto both.
 */
final class Itp extends Model
{
    use HasUuid;

    protected $table = 'itps';

    public const DRAFT = 'DRAFT';
    public const ACTIVE = 'ACTIVE';
    public const SUPERSEDED = 'SUPERSEDED';

    protected $fillable = [
        'project_id', 'company_id', 'document_id', 'discipline', 'number',
        'title_en', 'title_fa', 'scope_en', 'scope_fa',
        'revision_label', 'state', 'source_template_id', 'created_by',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(ItpStage::class, 'itp_id')->orderBy('sequence');
    }

    /**
     * The register entry for this plan.
     *
     * An ITP is a project deliverable: it is issued for review, commented on,
     * revised and published like any datasheet. The register owns the number,
     * the revision chain and the CDE state; this row owns only what is
     * specific to being an inspection plan.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** Forms and procedures bound in with the plan. */
    public function annexes(): HasMany
    {
        return $this->hasMany(ItpAnnex::class, 'itp_id')->orderBy('sequence');
    }

    /**
     * The number to print.
     *
     * Once the plan is registered the register is authoritative — that is the
     * whole point of registering it. `number` remains as the working label for
     * a plan that has not been registered yet.
     */
    public function displayNumber(): string
    {
        return $this->document?->number ?? (string) $this->number;
    }

    /** Is this plan governed by the document register yet? */
    public function isRegistered(): bool
    {
        return $this->document_id !== null;
    }

    /**
     * The CDE state of the plan, when it has one.
     *
     * Deliberately NOT merged with `state`. `state` is about the gate — which
     * plan is in force for a discipline — while the CDE state is about
     * publication. A plan can be ACTIVE and its document still SHARED pending
     * the client's stamp, and conflating the two would let a publication step
     * silently switch off a hold point.
     */
    public function cdeState(): ?string
    {
        return $this->document?->currentRevision?->cde_state;
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ItpTemplate::class, 'source_template_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', self::ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->state === self::ACTIVE;
    }

    public function title(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->title_fa : $this->title_en;
    }
}
