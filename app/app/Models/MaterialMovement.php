<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the append-only stock ledger.
 *
 * There is no update path and no delete path. A wrong receipt is corrected by
 * posting a reversing movement that points back at it, so the register can
 * always answer "what did the warehouse hold on the day of the audit" - which
 * an editable ledger cannot.
 */
final class MaterialMovement extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'lot_id', 'number', 'sequence', 'type',
        'quantity', 'from_location', 'to_location', 'subsystem_id', 'note',
        'performed_by', 'performed_at', 'reverses_movement_id',
    ];

    protected $casts = [
        'performed_at' => 'datetime',
        'quantity' => 'decimal:3',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class, 'lot_id');
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_movement_id');
    }

    /** Signed effect on warehouse stock. */
    public function signedQuantity(): float
    {
        return MovementType::sign($this->type) * (float) $this->quantity;
    }

    public function isReversal(): bool
    {
        return $this->reverses_movement_id !== null;
    }

    public function typeName(string $lang = 'fa'): string
    {
        return MovementType::name($this->type, $lang);
    }
}
