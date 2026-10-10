<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Editable MTO revision with one immutable accepted snapshot at most. */
final class MtoRevision extends Model
{
    use HasUuid;

    public const DRAFT = 'DRAFT';
    public const UNDER_REVIEW = 'UNDER_REVIEW';
    public const ACCEPTED = 'ACCEPTED';
    public const SUPERSEDED = 'SUPERSEDED';
    public const REJECTED = 'REJECTED';

    public const SCOPE_HEADER = 'HEADER_SCOPE';
    public const SCOPE_ROW = 'ROW_SCOPE';
    public const SCOPE_MIXED = 'MIXED';

    protected $fillable = [
        'project_id', 'company_id', 'mto_document_id', 'revision_code',
        'revision_date', 'state', 'source_file_hash', 'scope_mode',
        'header_scope_id', 'hierarchy_revision_id',
    ];

    protected $casts = [
        'revision_date' => 'date',
    ];

    public static function states(): array
    {
        return [self::DRAFT, self::UNDER_REVIEW, self::ACCEPTED, self::SUPERSEDED, self::REJECTED];
    }

    public static function scopeModes(): array
    {
        return [self::SCOPE_HEADER, self::SCOPE_ROW, self::SCOPE_MIXED];
    }

    public function document(): BelongsTo { return $this->belongsTo(MtoDocument::class, 'mto_document_id'); }
    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function headerScope(): BelongsTo { return $this->belongsTo(Subsystem::class, 'header_scope_id'); }
    public function rows(): HasMany { return $this->hasMany(MtoStagingRow::class, 'mto_revision_id'); }
    public function acceptedBatch(): HasOne { return $this->hasOne(AcceptedMtoBatch::class, 'mto_revision_id'); }
}
