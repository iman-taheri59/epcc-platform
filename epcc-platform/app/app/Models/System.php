<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A functional system - "Fuel Gas", "Instrument Air", "Flare" (ADR-004 rev 2).
 *
 * The distinction that earns this table its place: a SYSTEM is functional, an
 * AREA is geographic, and they deliberately do not nest. One fuel gas system
 * crosses three areas; one area hosts six systems. Construction is organised by
 * area, commissioning by system, and most handover disputes on a real project
 * are some version of that mismatch.
 *
 * So a system hangs off the FACILITY, not the area. Putting it under an area
 * would force a commissioning boundary to follow a geographic one and make the
 * resulting roll-ups quietly wrong.
 */
final class System extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'facility_id',
        'code', 'name_en', 'name_fa',
        'commissioning_sequence', 'external_ref',
    ];

    protected $casts = [
        'commissioning_sequence' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function subsystems(): HasMany
    {
        return $this->hasMany(Subsystem::class);
    }

    /**
     * Systems in the order they will be commissioned.
     *
     * NULLS LAST: a system with no sequence yet is not "first", it is
     * unplanned, and sorting it to the top of a commissioning schedule is the
     * kind of wrong that looks like a decision.
     */
    public function scopeInCommissioningOrder(Builder $query): Builder
    {
        return $query->orderByRaw('commissioning_sequence NULLS LAST')->orderBy('code');
    }

    public function name(string $lang = 'en'): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }

    /**
     * The code as a reader can safely compare.
     *
     * System codes are unique per FACILITY, not per project - two plants in one
     * project both legitimately run a "FG" system - so the bare code is unsafe
     * anywhere a reader might compare across facilities. Composed at read time,
     * never stored: a stored copy drifts the first time a facility is renamed
     * (the same rule as Subsystem::fullCode()).
     */
    public function fullCode(): string
    {
        $facility = $this->relationLoaded('facility') ? $this->facility : $this->facility()->first();

        return $facility === null ? $this->code : $facility->code.'-'.$this->code;
    }
}
