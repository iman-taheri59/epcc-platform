<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ingestion of a 3D model extract (ADR-005 phase 2).
 *
 * Kept as a record even when nothing changed, because "the model was
 * re-issued at rev C and nothing moved" is itself a finding worth being able
 * to prove.
 */
final class ModelImport extends Model
{
    use HasUuid;

    public const STATE_PARSED = 'PARSED';

    public const STATE_APPLIED = 'APPLIED';

    public const STATE_FAILED = 'FAILED';

    public const STATE_DRY_RUN = 'DRY_RUN';

    protected $fillable = [
        'project_id', 'company_id', 'piping_line_id',
        'filename', 'source_kind',
        'pipeline_reference', 'revision', 'piping_spec',
        'units_bore', 'units_coords', 'content_hash', 'state',
        'components_seen', 'components_created', 'components_updated',
        'components_unchanged', 'components_protected',
        'joints_created', 'unmapped_count',
        'warnings', 'imported_by', 'imported_at',
    ];

    protected $casts = [
        'warnings' => 'array',
        'imported_at' => 'datetime',
        'components_seen' => 'integer',
        'components_created' => 'integer',
        'components_updated' => 'integer',
        'components_unchanged' => 'integer',
        'components_protected' => 'integer',
        'joints_created' => 'integer',
        'unmapped_count' => 'integer',
    ];

    public function line(): BelongsTo
    {
        return $this->belongsTo(PipingLine::class, 'piping_line_id');
    }

    public function diffs(): HasMany
    {
        return $this->hasMany(ModelImportDiff::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    /** Did anything need a human to look at it? */
    public function needsAttention(): bool
    {
        return $this->components_protected > 0 || $this->unmapped_count > 0;
    }
}
