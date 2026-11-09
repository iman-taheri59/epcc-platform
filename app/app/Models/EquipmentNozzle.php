<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A nozzle on a piece of equipment — ADR-010 §3.
 *
 * A nozzle is a PORT OWNER, exactly like a component. That is why a pipe can
 * land on one without the joints table knowing anything about equipment: the
 * joint just sees two ports.
 *
 * `is_spare` is the difference between "nobody has connected this yet" and
 * "this is capped on purpose". Without it, every spare on the plant shows up
 * as unfinished work.
 */
final class EquipmentNozzle extends Model
{
    use HasUuid;

    public const TYPES = ['PROCESS', 'VENT', 'DRAIN', 'MANWAY', 'INSTRUMENT', 'RELIEF', 'SPARE'];

    protected $fillable = [
        'project_id', 'company_id', 'equipment_id',
        'tag', 'nozzle_type', 'nps_label', 'nps_decimal', 'dn',
        'end_type', 'rating', 'facing', 'schedule', 'wall_thickness',
        'elevation', 'orientation', 'is_spare',
        'datasheet_ref', 'external_ref', 'created_by',
    ];

    protected $casts = [
        'nps_decimal' => 'decimal:4',
        'is_spare' => 'boolean',
    ];

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /**
     * The port this nozzle owns, if one has been made for it yet.
     *
     * Nozzles can be declared before anything is connected to them, which is
     * the normal order of work — so this is nullable by design, not by
     * accident.
     */
    public function port(): HasOne
    {
        return $this->hasOne(Port::class, 'nozzle_id');
    }
}
