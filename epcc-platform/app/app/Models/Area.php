<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The OPTIONAL level between a facility and its subsystems.
 *
 * This is Plant 3D's "Area" and AWP's "CWA" (Construction Work Area) - the
 * level `awp.pack.json` already referenced and previously had nowhere to live.
 *
 * Optional is the whole point: Plant 3D's own refinery example has 13 areas, so
 * the level must exist for a lossless import, but a booster station with 12
 * subsystems must not be forced to invent 12 areas.
 */
final class Area extends Model
{
    use HasUuid;

    protected $table = 'areas';

    protected $fillable = [
        'project_id', 'company_id', 'facility_id',
        'code', 'name_en', 'name_fa', 'unit_code', 'external_ref',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function subsystems(): HasMany
    {
        return $this->hasMany(Subsystem::class);
    }

    public function name(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }

    /**
     * Plant 3D keeps Area and Unit as two separate tag subparts, because one
     * area can host more than one process unit. Displayed together when both
     * are known, so a screen does not have to decide.
     */
    public function displayCode(): string
    {
        return $this->unit_code === null || $this->unit_code === ''
            ? $this->code
            : $this->code.' / '.$this->unit_code;
    }
}
