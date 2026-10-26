<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Automatically recorded NEW chronology, NEVER proof of effective scope. */
final class MtoAcceptanceJournal extends Model
{
    use HasUuid;

    protected $table = 'mto_acceptance_journal';
    public $timestamps = false;
    protected $guarded = ['*'];
    protected $casts = ['ordinal' => 'integer', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(static fn () => throw new LogicException('The database records new acceptance provenance.'));
        static::updating(static fn () => throw new LogicException('Acceptance provenance is immutable.'));
        static::deleting(static fn () => throw new LogicException('Acceptance provenance is immutable.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AcceptedMtoBatch::class, 'accepted_mto_batch_id');
    }
}
