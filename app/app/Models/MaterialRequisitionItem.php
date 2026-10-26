<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a requisition. */
final class MaterialRequisitionItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'material_item_id', 'project_id', 'requisition_id', 'subsystem_id', 'line_no',
        'item_code', 'description_en', 'description_fa', 'unit',
        'quantity', 'specification',
    ];

    protected $casts = ['quantity' => 'decimal:3'];

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(MaterialRequisition::class, 'requisition_id');
    }

    /** Nullable: bulk consumables belong to no single subsystem. */
    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    public function description(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->description_fa : $this->description_en;
    }
}
