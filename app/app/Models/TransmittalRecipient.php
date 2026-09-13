<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One receiving company on a transmittal, with its own acknowledgement.
 *
 * Per-recipient rather than one flag on the letter: a transmittal to four
 * parties is acknowledged four separate times, and "who has not replied" is
 * the question the sender actually asks.
 */
final class TransmittalRecipient extends Model
{
    use HasUuid;

    protected $fillable = [
        'transmittal_id', 'project_id', 'company_id', 'recipient_company_id',
        'acknowledged_at', 'acknowledged_by', 'acknowledgement_note',
    ];

    protected $casts = ['acknowledged_at' => 'datetime'];

    public function transmittal(): BelongsTo
    {
        return $this->belongsTo(Transmittal::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'recipient_company_id');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function hasAcknowledged(): bool
    {
        return $this->acknowledged_at !== null;
    }
}
