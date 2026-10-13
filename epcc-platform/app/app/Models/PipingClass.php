<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piping class from the project's PMS (ADR-006 §11.5).
 *
 * Per-project, not universal: two projects both "on ISO" will have different
 * class codes, so this is a tenant table rather than a reference pack. ADR-008
 * will hang size bands and branch tables off it; nothing here anticipates that
 * shape, because guessing it now would be a migration to undo later.
 */
final class PipingClass extends Model
{
    use HasUuid;

    protected $table = 'piping_classes';

    protected $fillable = [
        'project_id', 'company_id', 'code',
        'description_en', 'description_fa',
        'pressure_rating',
        // Header, ADR-008 §11.6 / §11.12 step 4.
        'service_en', 'service_fa', 'fluid_service_category',
        // B31.3 300.2 calls this a CONDITION, not a fluid service. It sits
        // alongside the service, not inside it: a Category M line can be
        // under severe cyclic conditions and so can a Normal one.
        'severe_cyclic_conditions',
        'design_temp_min_c', 'design_temp_max_c',
        'design_temp_min_value', 'design_temp_min_unit', 'design_temp_max_value', 'design_temp_max_unit',
        'corrosion_allowance_value', 'corrosion_allowance_unit',
        'corrosion_allowance_mm', 'corrosion_allowance_as_written',
        'material_group_code', 'material_spec_code', 'material_grade_key',
        'material_as_written',
        'special_requirements', 'default_flange_series',
    ];

    protected $casts = [
        'entry_version' => 'integer', 'entry_provenance' => 'array',
        'design_temp_min_c' => 'float',
        'design_temp_max_c' => 'float',
        'corrosion_allowance_mm' => 'float',
        'special_requirements' => 'array',
        // B31.3 300.2: a CONDITION the owner designates, not a fluid service.
        'severe_cyclic_conditions' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PipingLine::class, 'piping_class_id');
    }

    /** Size-banded rules: schedule, end preparation, and the rest (ADR-008 §3). */
    public function rules(): HasMany
    {
        return $this->hasMany(PipingClassRule::class, 'piping_class_id');
    }

    /** The branch table (ADR-008 §4). */
    public function branchRules(): HasMany
    {
        return $this->hasMany(PipingClassBranchRule::class, 'piping_class_id');
    }

    /** Source NDT requirements, independent of material inspection (ADR-008 §25). */
    public function ndtRequirements(): HasMany
    {
        return $this->hasMany(PipingClassNdtRequirement::class, 'piping_class_id')->orderBy('created_at')->orderBy('id');
    }

    /** Numbered header notes, cited by body rows (ADR-008 §11.6). */
    public function notes(): HasMany
    {
        return $this->hasMany(PipingClassNote::class, 'piping_class_id')
            ->orderBy('number');
    }

    /**
     * Does the header name a material the library can resolve?
     *
     * False does NOT mean the class is invalid. The PMS is transcribed from an
     * outside document and the transcriber records what it says; an
     * unresolvable material is a discrepancy to chase, not a write to refuse.
     */
    public function hasResolvedMaterial(): bool
    {
        return $this->material_group_code !== null && $this->material_spec_code !== null;
    }

    /**
     * What to show a reader: the library's description when the reference
     * resolves, otherwise the transcriber's own words. Never nothing, because
     * a blank material on a class reads as "no material specified" when the
     * source document plainly specified one.
     */
    public function materialLabel(): ?string
    {
        return $this->material_as_written
            ?? ($this->hasResolvedMaterial()
                ? trim($this->material_spec_code.' '.(string) $this->material_grade_key)
                : null);
    }

    public function description(string $lang): string
    {
        return (string) ($lang === 'fa'
            ? ($this->description_fa ?? $this->description_en ?? $this->code)
            : ($this->description_en ?? $this->description_fa ?? $this->code));
    }
}
