<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versioned MTO document header; rows live on revisions until accepted later. */
final class MtoDocument extends Model
{
    use HasUuid;

    public const SOURCE_CDE_REVISION = 'CDE_REVISION';
    public const SOURCE_EXTERNAL_EVIDENCE = 'EXTERNAL_EVIDENCE';
    public const SOURCE_MANUAL = 'MANUAL';

    protected $fillable = [
        'project_id', 'company_id', 'discipline', 'document_number',
        'title_en', 'title_fa', 'source_type', 'cde_document_id',
    ];

    public static function sourceTypes(): array
    {
        return [self::SOURCE_CDE_REVISION, self::SOURCE_EXTERNAL_EVIDENCE, self::SOURCE_MANUAL];
    }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function cdeDocument(): BelongsTo { return $this->belongsTo(Document::class, 'cde_document_id'); }
    public function revisions(): HasMany { return $this->hasMany(MtoRevision::class, 'mto_document_id'); }

    public function title(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->title_fa : $this->title_en;
    }
}
