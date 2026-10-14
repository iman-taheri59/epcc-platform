<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\LineReleaseState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piping line: the unit engineering designs, procurement buys against and
 * construction builds (ADR-006 §5.1).
 */
final class PipingLine extends Model
{
    use HasUuid;

    protected $fillable = [
        'design_pressure_kpa', 'operating_pressure_kpa', 'test_pressure_kpa',
        'design_temperature_c', 'operating_temperature_c',
        'project_id', 'company_id', 'subsystem_id', 'test_pack_id',
        'line_number', 'number_tokens', 'sequence',
        'nominal_size', 'service_code', 'piping_class_id', 'pms_class_snapshot_id', 'coating_code',
        'design_pressure', 'design_pressure_unit',
        'design_temperature', 'design_temperature_unit',
        'operating_pressure', 'operating_pressure_unit',
        'operating_temperature', 'operating_temperature_unit',
        'test_pressure', 'test_pressure_unit', 'test_fluid', 'test_type',
        'insulation_type', 'insulation_thickness_mm', 'tracing_type',
        'from_ref', 'to_ref', 'external_ref',
        'release_state',
    ];

    protected $casts = [
        'number_tokens' => 'array',
        'afc_confirmed_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class, 'piping_class_id');
    }

    public function testPack(): BelongsTo
    {
        return $this->belongsTo(PipingTestPack::class, 'test_pack_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(LineDocument::class, 'piping_line_id');
    }

    public function afcConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'afc_confirmed_by');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    // ---------------------------------------------------------- the gate --

    /** May the field build this line? */
    public function isBuildable(): bool
    {
        return LineReleaseState::isBuildable((string) $this->release_state);
    }

    /** Has it passed AFC, whether or not it is released? */
    public function isAfc(): bool
    {
        return LineReleaseState::isAfc((string) $this->release_state);
    }

    /** Is engineering still free to change it without re-earning approval? */
    public function isEditable(): bool
    {
        return LineReleaseState::isEditable((string) $this->release_state);
    }

    // ------------------------------------------------------------ scopes --

    public function scopeBuildable(Builder $query): Builder
    {
        return $query->where('release_state', LineReleaseState::RELEASED);
    }

    public function scopeAwaitingAfc(Builder $query): Builder
    {
        return $query->where('release_state', LineReleaseState::DRAFT);
    }

    /**
     * Lines whose number we never allocated.
     *
     * ADR-006 §10.2: an import must not invent numbers, so an imported line
     * arrives with a number but no sequence. This is the predicate that finds
     * them - useful for spotting lines outside the project's own scheme.
     */
    public function scopeExternallyNumbered(Builder $query): Builder
    {
        return $query->whereNull('sequence');
    }

    /**
     * The sequence, zero-padded for display.
     *
     * Padding is presentation and is never stored - the same rule ADR-006
     * §11.1 sets for joint numbers, and the same reason document numbers use
     * sprintf at render time.
     */
    public function paddedSequence(int $width = 3): ?string
    {
        return $this->sequence === null
            ? null
            : str_pad((string) $this->sequence, $width, '0', STR_PAD_LEFT);
    }
}
