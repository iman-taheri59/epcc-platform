<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tagged item of equipment - a vessel, pump, exchanger (ADR-010 section 3.3).
 *
 * WHY tag_number IS STORED AND fullCode() IS NOT
 * ----------------------------------------------
 * ADR-004 established that composed codes are never stored: they drift the
 * first time a parent is renamed. Equipment tags are the deliberate exception.
 * A tag is not a derived view of a hierarchy - it is an IDENTIFIER stamped on
 * the nameplate, printed on the datasheet, quoted on the PO and carried by the
 * material certificate. If renaming an area silently rewrote it, the database
 * would disagree with the steel.
 *
 * So the unit code inside the tag is a copy, and that is by design. The
 * `unit_code` column beside it records which unit the tag was composed FROM,
 * so a later mismatch is detectable rather than invisible.
 */
final class Equipment extends Model
{
    use HasUuid;

    protected $table = 'equipment';

    protected $fillable = [
        'project_id', 'company_id', 'subsystem_id', 'area_id', 'unit_code',
        'tag_number', 'equipment_type', 'tag_sequence', 'tag_suffix',
        'name_en', 'name_fa', 'service_description',
        'design_pressure', 'design_pressure_unit', 'design_pressure_kpa',
        'design_temperature', 'design_temperature_unit', 'design_temperature_c',
        'design_code', 'material_spec', 'insulation_type',
        'weight_empty_kg', 'weight_operating_kg',
        'po_item_id', 'vendor_company_id', 'datasheet_document_id', 'ga_drawing_document_id',
        'state', 'source', 'external_ref', 'created_by',
        'deleted_at', 'deleted_by', 'deletion_reason',
    ];

    protected $casts = [
        'tag_sequence' => 'integer',
        'deleted_at' => 'datetime',
        'design_pressure' => 'float',
        'design_pressure_kpa' => 'float',
        'design_temperature' => 'float',
        'design_temperature_c' => 'float',
        'weight_empty_kg' => 'float',
        'weight_operating_kg' => 'float',
    ];

    public const STATE_DELETED = 'DELETED';

    public const STATES = ['PLANNED', 'ORDERED', 'DELIVERED', 'INSTALLED', 'COMMISSIONED', self::STATE_DELETED];

    /**
     * The states a register counts as real work.
     *
     * Note this is NOT used as a global scope. A withdrawn pump stays visible,
     * greyed and labelled, because "where did P-101A go?" is a question
     * somebody will ask and silence is a bad answer. Callers opt in.
     *
     * @var list<string>
     */
    public const LIVE_STATES = ['PLANNED', 'ORDERED', 'DELIVERED', 'INSTALLED', 'COMMISSIONED'];

    /**
     * The types a tag may be built for.
     *
     * A default list, not a closed one: `project_piping_config.equipment_types`
     * overrides it, because a refinery and a tank farm do not tag the same
     * things. The codes are the common ISA/process letters.
     */
    public const DEFAULT_TYPES = [
        'V' => ['en' => 'Vessel', 'fa' => 'مخزن تحت فشار'],
        'P' => ['en' => 'Pump', 'fa' => 'پمپ'],
        'E' => ['en' => 'Heat exchanger', 'fa' => 'مبدل حرارتی'],
        'TK' => ['en' => 'Tank', 'fa' => 'مخزن ذخیره'],
        'K' => ['en' => 'Compressor', 'fa' => 'کمپرسور'],
        'F' => ['en' => 'Filter', 'fa' => 'فیلتر'],
        'H' => ['en' => 'Fired heater', 'fa' => 'کوره'],
        'C' => ['en' => 'Column', 'fa' => 'برج'],
        'SK' => ['en' => 'Package / skid', 'fa' => 'پکیج / اسکید'],
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function nozzles(): HasMany
    {
        return $this->hasMany(EquipmentNozzle::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'vendor_company_id');
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('equipment_type', $type);
    }

    /** Equipment that has not been withdrawn. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('state', '!=', self::STATE_DELETED);
    }

    public function isDeleted(): bool
    {
        return $this->state === self::STATE_DELETED;
    }

    public function name(string $lang = 'en'): string
    {
        $name = $lang === 'fa' ? $this->name_fa : $this->name_en;

        return $name !== null && $name !== '' ? $name : $this->tag_number;
    }

    /**
     * Nozzles with nothing connected, excluding declared spares.
     *
     * A capped spare is not an open end. Without that exclusion the worklist
     * fills with false positives and people stop reading it - the same reason
     * `numbering_policy` distinguishes "never numbered" from "not yet".
     */
    public function openNozzles(): \Illuminate\Support\Collection
    {
        return $this->nozzles()
            ->where('is_deleted', false)
            ->where('is_spare', false)
            ->with('port')
            ->get()
            ->filter(fn (EquipmentNozzle $n): bool => ! ($n->port?->is_connected ?? false))
            ->values();
    }
}
