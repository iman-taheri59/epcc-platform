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
 *
 * The "unit" this level used to carry (`unit_code`) is gone since the ADR-004
 * amendment that added `systems`: where a thing IS is this tree, and what it
 * DOES is the system's, and one column answering both was the confusion.
 */
final class Area extends Model
{
    use HasUuid;

    protected $table = 'areas';

    protected $fillable = [
        'project_id', 'company_id', 'facility_id',
        'code', 'name_en', 'name_fa', 'external_ref',
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
}
