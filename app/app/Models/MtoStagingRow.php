<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Draft MTO row loaded from a manual entry/import before immutable acceptance. */
final class MtoStagingRow extends Model
{
    use HasUuid;

    public const ACCEPTABLE = 'ACCEPTABLE';
    public const BLOCKED_UNMATCHED_MATERIAL = 'BLOCKED_UNMATCHED_MATERIAL';
    public const BLOCKED_UNIT_INCOMPATIBLE = 'BLOCKED_UNIT_INCOMPATIBLE';
    public const BLOCKED_DUPLICATE_IDENTITY = 'BLOCKED_DUPLICATE_IDENTITY';
    public const BLOCKED_QUANTITY_INVALID = 'BLOCKED_QUANTITY_INVALID';

    protected $fillable = [
        'project_id', 'mto_revision_id', 'line_no', 'mto_item_no',
        'raw_description', 'raw_quantity', 'raw_unit', 'material_item_id',
        'row_scope_id', 'piping_class_id', 'validation_state', 'validation_errors',
    ];

    protected $casts = [
        'raw_quantity' => 'decimal:3',
        'validation_errors' => 'array',
    ];

    public static function validationStates(): array
    {
        return [
            self::ACCEPTABLE,
            self::BLOCKED_UNMATCHED_MATERIAL,
            self::BLOCKED_UNIT_INCOMPATIBLE,
            self::BLOCKED_DUPLICATE_IDENTITY,
            self::BLOCKED_QUANTITY_INVALID,
        ];
    }

    public function revision(): BelongsTo { return $this->belongsTo(MtoRevision::class, 'mto_revision_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function materialItem(): BelongsTo { return $this->belongsTo(MaterialItem::class); }
    public function rowScope(): BelongsTo { return $this->belongsTo(Subsystem::class, 'row_scope_id'); }
    public function pipingClass(): BelongsTo { return $this->belongsTo(PipingClass::class); }
}
