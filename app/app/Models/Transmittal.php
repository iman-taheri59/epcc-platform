<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\TransmittalPurpose;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A transmittal: the formal record that documents were issued (FR-ENG-05).
 */
final class Transmittal extends Model
{
    use HasUuid;

    public const DRAFT = 'DRAFT';
    public const ISSUED = 'ISSUED';

    /**
     * The two streams (FR-ENG-05b).
     *
     * CONTRACTOR: the EPC's contractual submission to the Employer/Consultant.
     * VENDOR:     a supplier's letter to the EPC, recorded by the EPC's
     *             document controller. Separate number series, separate
     *             screen, and never counted as a submission to the client.
     */
    public const CONTRACTOR = 'CONTRACTOR';
    public const VENDOR = 'VENDOR';

    protected $fillable = [
        'project_id', 'company_id', 'number', 'sequence', 'subject_en',
        'subject_fa', 'purpose', 'status', 'notes', 'issued_by', 'issued_at',
        'due_at', 'kind', 'vendor_company_id', 'vendor_reference',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'due_at' => 'datetime',
        'sequence' => 'integer',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The sending company - the tenant that owns the row. */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * The originating vendor, for a VENDOR transmittal.
     *
     * Deliberately NOT the same as sender(): the EPC records the letter, so it
     * owns the row for isolation purposes, while the vendor is who actually
     * wrote it.
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'vendor_company_id');
    }

    public function isVendor(): bool
    {
        return $this->kind === self::VENDOR;
    }

    public function isContractor(): bool
    {
        return $this->kind === self::CONTRACTOR;
    }

    /** Bilingual label for the stream. */
    public function kindLabel(string $lang = 'fa'): string
    {
        return match ($this->kind) {
            self::VENDOR => $lang === 'fa' ? 'وندور' : 'Vendor',
            default => $lang === 'fa' ? 'پیمانکار' : 'Contractor',
        };
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(TransmittalRecipient::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransmittalItem::class);
    }

    public function subject(string $lang): string
    {
        return $lang === 'fa' ? $this->subject_fa : $this->subject_en;
    }

    public function purposeLabel(string $lang): string
    {
        return TransmittalPurpose::label($this->purpose, $lang);
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isIssued(): bool
    {
        return $this->status === self::ISSUED;
    }

    /**
     * Overdue = issued, past due, and at least one recipient has not
     * acknowledged. DERIVED, never stored: a stored flag is wrong the moment
     * the clock passes it and nobody has run the job that updates it.
     */
    public function isOverdue(): bool
    {
        if (! $this->isIssued() || $this->due_at === null || $this->due_at->isFuture()) {
            return false;
        }

        return $this->recipients->contains(fn (TransmittalRecipient $r) => $r->acknowledged_at === null);
    }

    public function acknowledgedCount(): int
    {
        return $this->recipients->whereNotNull('acknowledged_at')->count();
    }
}
