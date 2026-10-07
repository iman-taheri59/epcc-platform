<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable gross-MTO row snapshot; downstream records must use this UUID. */
final class AcceptedMtoRow extends \Illuminate\Database\Eloquent\Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'accepted_mto_batch_id', 'mto_document_id',
        'mto_revision_id', 'source_staging_row_id', 'mto_item_no',
        'material_item_id', 'gross_quantity', 'unit', 'canonical_quantity',
        'scope_id', 'hierarchy_revision_id', 'piping_class_id', 'raw_description',
        'material_description_en', 'material_description_fa',
        'material_commodity_code', 'material_base_unit', 'material_category',
        'source_row_hash', 'accepted_at',
    ];

    protected $casts = [
        'gross_quantity' => 'decimal:3',
        'canonical_quantity' => 'decimal:3',
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Accepted MTO rows are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Accepted MTO rows are immutable.');
        });
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AcceptedMtoBatch::class, 'accepted_mto_batch_id');
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function document(): BelongsTo { return $this->belongsTo(MtoDocument::class, 'mto_document_id'); }
    public function revision(): BelongsTo { return $this->belongsTo(MtoRevision::class, 'mto_revision_id'); }
    public function sourceStagingRow(): BelongsTo { return $this->belongsTo(MtoStagingRow::class, 'source_staging_row_id'); }
    public function materialItem(): BelongsTo { return $this->belongsTo(MaterialItem::class); }
    public function scope(): BelongsTo { return $this->belongsTo(Subsystem::class, 'scope_id'); }
    public function pipingClass(): BelongsTo { return $this->belongsTo(PipingClass::class); }
}
