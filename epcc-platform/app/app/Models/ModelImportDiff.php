<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One difference between the incoming model and what the system already holds.
 *
 * PROTECTED is the row that matters: the model wanted to change something that
 * carries recorded progress, and the import refused. That is not an error, it
 * is a conversation between engineering and construction, and it needs to
 * survive in a table rather than scroll past in a log.
 */
final class ModelImportDiff extends Model
{
    use HasUuid;

    public const CREATED = 'CREATED';

    public const UPDATED = 'UPDATED';

    public const UNCHANGED = 'UNCHANGED';

    public const PROTECTED = 'PROTECTED';

    public const REMOVED_IN_MODEL = 'REMOVED_IN_MODEL';

    public const UNMAPPED = 'UNMAPPED';

    protected $fillable = [
        'project_id', 'company_id', 'model_import_id', 'component_id',
        'change', 'pcf_type', 'template_code', 'source_line', 'detail',
        'before', 'after', 'protected_reason',
        'acknowledged', 'acknowledged_by', 'acknowledged_at',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
        'source_line' => 'integer',
    ];

    public function import(): BelongsTo
    {
        return $this->belongsTo(ModelImport::class, 'model_import_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
