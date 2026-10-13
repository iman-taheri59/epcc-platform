<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\ProjectState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Project extends Model
{
    use HasUuid;

    protected $fillable = ['code', 'name_en', 'name_fa', 'standards_pack', 'owner_company_id'];

    protected $casts = ['archived_at' => 'datetime'];

    public function ownerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'owner_company_id');
    }

    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Live projects only - the default for every picker and dashboard.
     *
     * Archived projects stay fully readable by direct id, which is what makes
     * an archive different from a delete. They are merely out of the way.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('state', ProjectState::ACTIVE);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('state', ProjectState::ARCHIVED);
    }

    public function isArchived(): bool
    {
        return $this->state === ProjectState::ARCHIVED;
    }

    public function isWritable(): bool
    {
        return ProjectState::isWritable((string) $this->state);
    }

    public function name(string $lang): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }
}
