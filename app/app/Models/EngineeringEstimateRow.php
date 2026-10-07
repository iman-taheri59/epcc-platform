<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A provenance-bearing net engineering quantity. */
final class EngineeringEstimateRow extends Model
{
    use HasUuid;

    public const SOURCE_MANUAL_ISO = 'MANUAL_ISO';
    public const SOURCE_MODEL_DERIVED = 'MODEL_DERIVED';
    public const SOURCE_PCF_IMPORT = 'PCF_IMPORT';

    protected $fillable = [
        'project_id', 'company_id', 'source_type', 'source_revision',
        'source_evidence', 'material_item_id', 'accepted_mto_row_id',
        'net_quantity', 'unit', 'scope_id', 'piping_class_id', 'line_id',
        'provenance',
    ];

    protected $casts = [
        'net_quantity' => 'decimal:3',
        'provenance' => 'array',
    ];

    public static function sourceTypes(): array
    {
        return [self::SOURCE_MANUAL_ISO, self::SOURCE_MODEL_DERIVED, self::SOURCE_PCF_IMPORT];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function materialItem(): BelongsTo { return $this->belongsTo(MaterialItem::class); }
    public function acceptedMtoRow(): BelongsTo { return $this->belongsTo(AcceptedMtoRow::class, 'accepted_mto_row_id'); }
    public function scope(): BelongsTo { return $this->belongsTo(Subsystem::class, 'scope_id'); }
    public function pipingClass(): BelongsTo { return $this->belongsTo(PipingClass::class); }
    public function line(): BelongsTo { return $this->belongsTo(PipingLine::class, 'line_id'); }

    public function isProvisional(): bool
    {
        return $this->accepted_mto_row_id === null;
    }
}
