<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A physical item on a piping line - a pipe, an elbow, a valve (ADR-006 §5.2).
 *
 * What KIND it is comes from a component template in the reference pack, not
 * from a table: `template_code` is a plain string validated by the service
 * against PipeReference. The templates are universal engineering truth (a tee
 * has three ports everywhere on earth), so they are reference data, not rows a
 * project can edit.
 */
final class Component extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id',
        'template_code', 'sequence',
        'nominal_size', 'nominal_size_decimal',
        'schedule', 'material_code', 'length_mm',
        'fabrication', 'tag', 'remark',
        'source', 'external_ref', 'created_by',
        'model_import_id', 'manually_edited_at', 'manually_edited_by',
        'pcf_identifier', 'pcf_type', 'spool_identifier',
    ];

    protected $casts = [
        'nominal_size_decimal' => 'float',
        'manually_edited_at' => 'datetime',
        'length_mm' => 'float',
        'sequence' => 'integer',
    ];

    /** Where a component came from. MANUAL today; the rest arrive at phase 6. */
    public const SOURCES = ['MANUAL', 'PCF', 'PLANT3D', 'IMPORT'];

    /** Shop or field fabrication - PCF's CATEGORY FABRICATION vs ERECTION. */
    public const FABRICATIONS = ['SHOP', 'FIELD'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function ports(): HasMany
    {
        return $this->hasMany(Port::class)->orderBy('seq');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Ports with nothing joined to them yet - the modelling worklist. */
    public function openPorts(): HasMany
    {
        return $this->ports()->where('is_connected', false);
    }

    public function scopeOnLine(Builder $query, string $lineId): Builder
    {
        return $query->where('piping_line_id', $lineId);
    }

    /**
     * Display label: "#3 TEE 6in".
     *
     * Composed at read time rather than stored, for the same reason
     * Facility::fullCode() is - a stored label drifts the moment the size or
     * the sequence changes.
     */
    public function label(): string
    {
        $parts = ['#'.$this->sequence, $this->template_code];

        if ($this->nominal_size !== null) {
            $parts[] = $this->nominal_size.'"';
        }

        return implode(' ', $parts);
    }

    /**
     * Record that a person changed this by hand.
     *
     * The stamp is what lets the NEXT model import distinguish "the model
     * changed" from "somebody here changed it", instead of picking a winner in
     * silence. From here on the importer reports the collision rather than
     * overwriting (the user's ruling, 2026-09-18).
     */
    public function markManuallyEdited(User $actor): void
    {
        $this->forceFill([
            'manually_edited_at' => now(),
            'manually_edited_by' => $actor->id,
        ])->save();
    }

    public function cameFromModel(): bool
    {
        return $this->model_import_id !== null;
    }
}
