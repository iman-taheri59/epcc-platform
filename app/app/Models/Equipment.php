<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tagged piece of plant a pipe can terminate at — ADR-010 §3.
 *
 * Equipment sits on a SUBSYSTEM, the same parent a piping line uses. That is
 * the whole trick: punch items, MC certificates and material status are keyed
 * to subsystems already, so a vessel becomes MC-able without new plumbing.
 *
 * Scope discipline, deliberately: no internals, trays, seals, drivers as
 * separate tags, foundations or preservation. None of that is needed for a
 * line to terminate correctly, and carrying it would turn this into an
 * equipment-engineering module.
 */
final class Equipment extends Model
{
    use HasUuid;

    public const TYPES = ['VESSEL', 'PUMP', 'EXCHANGER', 'TANK', 'COMPRESSOR', 'FILTER', 'SKID', 'OTHER'];

    public const STATES = ['PLANNED', 'ORDERED', 'DELIVERED', 'INSTALLED', 'COMMISSIONED'];

    protected $fillable = [
        'project_id', 'company_id', 'subsystem_id',
        'tag_number', 'equipment_type', 'name_en', 'name_fa', 'service_description',
        'design_pressure', 'design_temperature', 'operating_pressure', 'operating_temperature',
        'design_code', 'material_spec', 'insulation_type',
        'weight_empty_kg', 'weight_operating_kg',
        'po_item_id', 'vendor_company_id', 'datasheet_document_id', 'ga_drawing_document_id',
        'external_ref', 'state', 'created_by',
    ];

    protected $casts = [
        'weight_empty_kg' => 'decimal:3',
        'weight_operating_kg' => 'decimal:3',
    ];

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function nozzles(): HasMany
    {
        return $this->hasMany(EquipmentNozzle::class, 'equipment_id');
    }
}
