<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One row of a piping class's BODY (ADR-008 §11.4, §11.12 step 5).
 *
 * The axis is component type x qualifier x size: "at DN 150 this class buys a
 * weld-neck flange in A105, forged, to B16.5, Class 150, raised face". One size
 * carries several rows of the same component type, which is why the qualifier
 * is part of the key.
 */
final class PipingClassItem extends Model
{
    use HasUuid;

    protected $table = 'piping_class_items';

    protected $fillable = [
        'project_id', 'company_id', 'piping_class_id',
        'component_type', 'qualifier', 'dn', 'nps',
        'material_group_code', 'material_spec_code', 'material_grade_key',
        'product_form', 'dimensional_standard', 'schedule', 'pressure_rating',
        'end_type', 'facing',
        'description_as_written', 'source_ref', 'note_numbers',
    ];

    protected $casts = [
        'dn' => 'integer',
        'entry_version' => 'integer', 'entry_provenance' => 'array',
        'note_numbers' => 'array',
    ];

    public function pipingClass(): BelongsTo
    {
        return $this->belongsTo(PipingClass::class, 'piping_class_id');
    }

    public function valveSpec(): HasOne
    {
        return $this->hasOne(PipingClassValveSpec::class, 'piping_class_item_id');
    }

    public function hasResolvedMaterial(): bool
    {
        return $this->material_group_code !== null && $this->material_spec_code !== null;
    }

    /**
     * What a reader sees. The transcriber's line when there is one, because
     * the source document is the authority on what it says; otherwise a
     * description assembled from the reference columns.
     */
    public function label(): string
    {
        if ($this->description_as_written !== null
            && trim($this->description_as_written) !== '') {
            return trim($this->description_as_written);
        }

        return trim(implode(' ', array_filter([
            $this->material_spec_code,
            $this->material_grade_key,
            $this->product_form,
            $this->schedule !== null ? 'SCH '.$this->schedule : null,
            $this->pressure_rating,
            $this->facing,
            $this->dimensional_standard,
        ])));
    }
}
