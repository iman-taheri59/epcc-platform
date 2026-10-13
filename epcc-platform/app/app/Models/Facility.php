<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\FacilityKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical facility inside a project: a plant, an offsite, a marine
 * facility, a utility block - and later a pipeline.
 *
 * The commissioning parent that was missing (ADR-004). Subsystems hang off it,
 * optionally via an area.
 */
final class Facility extends Model
{
    use HasUuid;

    protected $table = 'facilities';

    protected $fillable = [
        'project_id', 'company_id', 'code', 'name_en', 'name_fa',
        'kind', 'addressing', 'external_ref',
    ];

    protected $casts = [
        'addressing' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    /** Functional systems in this facility - the commissioning axis. */
    public function systems(): HasMany
    {
        return $this->hasMany(System::class);
    }

    public function subsystems(): HasMany
    {
        return $this->hasMany(Subsystem::class);
    }

    public function name(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }

    public function kindLabel(string $lang = 'fa'): string
    {
        return FacilityKind::label($this->kind, $lang);
    }

    /** What this facility calls its intermediate level: Area, Section, Berth. */
    public function areaLabel(string $lang = 'fa'): string
    {
        return FacilityKind::areaLabel($this->kind, $lang);
    }

    /**
     * Subsystems attached straight to the facility, with no area in between.
     *
     * Not an error state: the area level is optional by design, and a small
     * station is expected to skip it entirely.
     *
     * @return \Illuminate\Support\Collection<int,Subsystem>
     */
    public function unassignedSubsystems()
    {
        return $this->subsystems->filter(fn (Subsystem $s) => $s->area_id === null)->values();
    }

    /**
     * Does this facility use the area level at all?
     *
     * Asked by screens deciding whether to render a grouping column, so that a
     * project which never creates areas never sees an empty one.
     */
    public function usesAreas(): bool
    {
        return $this->areas()->exists();
    }
}
