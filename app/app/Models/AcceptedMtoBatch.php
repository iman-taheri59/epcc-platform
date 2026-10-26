<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Immutable acceptance event for one MTO document revision. */
final class AcceptedMtoBatch extends \Illuminate\Database\Eloquent\Model
{
    use HasUuid;

    public const ACCEPTED = 'ACCEPTED';

    protected $fillable = [
        'project_id', 'company_id', 'mto_document_id', 'mto_revision_id',
        'document_number', 'revision_code', 'state', 'source_file_hash',
        'scope_mode', 'header_scope_id', 'hierarchy_revision_id', 'accepted_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Accepted MTO batches are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Accepted MTO batches are immutable.');
        });
    }

    public function scopeObservation(): HasOne { return $this->hasOne(MtoScopeObservation::class, 'accepted_mto_batch_id'); }

    public function acceptanceJournal(): HasOne { return $this->hasOne(MtoAcceptanceJournal::class, 'accepted_mto_batch_id'); }

    public function project(): BelongsTo { return $this->belongsTo(Project::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function document(): BelongsTo { return $this->belongsTo(MtoDocument::class, 'mto_document_id'); }
    public function revision(): BelongsTo { return $this->belongsTo(MtoRevision::class, 'mto_revision_id'); }
    public function headerScope(): BelongsTo { return $this->belongsTo(Subsystem::class, 'header_scope_id'); }
    public function rows(): HasMany { return $this->hasMany(AcceptedMtoRow::class, 'accepted_mto_batch_id'); }
}
