<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A piping class from the project's PMS (ADR-006 §11.5).
 *
 * Per-project, not universal: two projects both "on ISO" will have different
 * class codes, so this is a tenant table rather than a reference pack. ADR-008
 * will hang size bands and branch tables off it; nothing here anticipates that
 * shape, because guessing it now would be a migration to undo later.
 */
final class PipingClass extends Model
{
    use HasUuid;

    protected $table = 'piping_classes';

    protected $fillable = [
        'project_id', 'company_id', 'code',
        'description_en', 'description_fa',
        'material', 'pressure_rating', 'corrosion_allowance',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PipingLine::class, 'piping_class_id');
    }

    public function description(string $lang): string
    {
        return (string) ($lang === 'fa'
            ? ($this->description_fa ?? $this->description_en ?? $this->code)
            : ($this->description_en ?? $this->description_fa ?? $this->code));
    }
}
