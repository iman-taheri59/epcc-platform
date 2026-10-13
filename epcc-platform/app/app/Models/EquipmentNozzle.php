<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A nozzle on a piece of equipment (ADR-010 section 3.1).
 *
 * The load-bearing idea: a nozzle is a PORT OWNER, exactly like a component.
 * That is why `joints` needed no change to let a pipe terminate at a vessel -
 * a pipe-to-nozzle joint is just two ports, and the compatibility engine does
 * not care which kind of thing owns them.
 *
 * Each nozzle owns exactly one port (`ports.owner_kind = NOZZLE`), created with
 * it. Nozzles with two ports exist in Plant 3D's model, but a second port here
 * would be a nozzle that connects two pipes - which is a fitting, not a nozzle.
 */
final class EquipmentNozzle extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'equipment_id',
        'tag', 'nozzle_type',
        'nominal_size', 'nominal_size_decimal',
        'end_type', 'rating', 'facing', 'schedule',
        'elevation_mm', 'orientation', 'is_spare',
        'datasheet_ref', 'external_ref',
        'is_deleted', 'deleted_at', 'deleted_by', 'deletion_reason',
    ];

    protected $casts = [
        'nominal_size_decimal' => 'float',
        'elevation_mm' => 'float',
        'is_spare' => 'boolean',
        'is_deleted' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public const TYPES = ['PROCESS', 'VENT', 'DRAIN', 'MANWAY', 'INSTRUMENT', 'RELIEF', 'SPARE'];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** The one port this nozzle owns. */
    public function port(): HasOne
    {
        return $this->hasOne(Port::class, 'nozzle_id');
    }

    public function isConnected(): bool
    {
        return (bool) ($this->port?->is_connected ?? false);
    }

    /** "N-1 6in 150# RF" - what the nozzle schedule and the attach picker show. */
    public function label(): string
    {
        $parts = [$this->tag];

        if ($this->nominal_size !== null) {
            $parts[] = $this->nominal_size.'"';
        }

        if ($this->rating !== null) {
            $parts[] = $this->rating;
        }

        if ($this->facing !== null) {
            $parts[] = $this->facing;
        }

        return implode(' ', $parts);
    }
}
