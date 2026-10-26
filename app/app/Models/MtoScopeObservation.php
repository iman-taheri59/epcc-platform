<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable observed facts; never scope approval or a verified hierarchy pin. */
final class MtoScopeObservation extends Model
{
    use HasUuid;

    protected $table = 'mto_scope_observations';
    public $timestamps = false;
    protected $guarded = ['*'];
    protected $casts = ['payload' => 'array', 'observed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::creating(static fn () => throw new LogicException('The database captures scope observations.'));
        static::updating(static fn () => throw new LogicException('Scope observations are immutable.'));
        static::deleting(static fn () => throw new LogicException('Scope observations are immutable.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AcceptedMtoBatch::class, 'accepted_mto_batch_id');
    }
}
