<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A register entry (SRS FR-ENG-01).
 *
 * The document is the thing that has a number and a title and lives for the
 * whole project; its REVISIONS carry the states and dates. See the migration
 * for why those are separate tables.
 */
final class Document extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'number', 'discipline', 'doc_type',
        'sequence', 'title_en', 'title_fa', 'current_revision_id',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class)->orderByDesc('ordinal');
    }

    /** Every file across every revision. Usually you want $rev->files. */
    public function files(): HasMany
    {
        return $this->hasMany(DocumentFile::class);
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'current_revision_id');
    }

    public function title(string $lang): string
    {
        return $lang === 'fa' ? $this->title_fa : $this->title_en;
    }
}
