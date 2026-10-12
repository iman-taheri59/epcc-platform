<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A project's PMS piping class register (ADR-008). `dn_span` is an int4range
 * held as its canonical text form, e.g. "[15,600)"; the publish gate demands
 * every rule kind cover it contiguously before the class may be used.
 */
final class PipingClass extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'code', 'name_en', 'name_fa',
        'state', 'dn_span', 'design_pressure_mpa', 'design_temperature_c',
        'published_at', 'published_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        // Design basis (ADR-008 Step B): pressure MPa, temperature deg C.
        'design_pressure_mpa' => 'decimal:4',
        'design_temperature_c' => 'decimal:2',
    ];

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function publishedBy(): BelongsTo { return $this->belongsTo(User::class, 'published_by'); }

    public function rules(): HasMany
    {
        return $this->hasMany(PipingClassRule::class);
    }

    public function branchRules(): HasMany
    {
        return $this->hasMany(PipingClassBranchRule::class);
    }

    public function isPublished(): bool
    {
        return $this->state === \App\Support\Piping\PipingClassState::PUBLISHED;
    }
}
