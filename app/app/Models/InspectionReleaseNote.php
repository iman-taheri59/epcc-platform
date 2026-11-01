<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An Inspection Release Note (تأییدیهٔ آزادسازیِ بازرسی) — ADR-060.
 *
 * The release is the act an inspection is NOT: an observation says what the
 * inspector saw, a release says what may now happen to the material. It is the
 * record a warehouse reads before it lets goods go and a site reads before it
 * signs a receipt — the answer to "did anyone with authority say this may
 * move?".
 *
 * Third-party by nature: the inspector may be a platform user or a person at an
 * inspection firm who will never hold an account, so the attribution is either
 * a user or a named person at a company — never blank.
 */
final class InspectionReleaseNote extends Model
{
    use HasUuid;

    public const STAGES = ['PRE_SHIPMENT', 'ON_ARRIVAL'];

    public const OUTCOMES = ['RELEASED', 'RELEASED_WITH_HOLDS', 'NOT_RELEASED'];

    /** Append-only: there is no updated_at to write. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id', 'company_id', 'purchase_order_id', 'purchase_order_item_id',
        'source_inspection_id', 'number', 'sequence', 'stage', 'outcome',
        'inspector_company_id', 'inspector_user_id', 'inspector_name',
        'holds_note_en', 'holds_note_fa', 'released_at',
        'issued_by', 'issuer_policy_id', 'previous_release_note_id',
    ];

    protected $casts = ['released_at' => 'datetime', 'created_at' => 'datetime'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    /** The inspection this release answers, when it answers one. */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(MaterialInspection::class, 'source_inspection_id');
    }

    public function inspectorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_user_id');
    }

    /** The inspection firm — the third party, when it is not a platform user. */
    public function inspectorCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'inspector_company_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** Material may move. With holds, it may move but not be put to work. */
    public function releases(): bool
    {
        return in_array($this->outcome, ['RELEASED', 'RELEASED_WITH_HOLDS'], true);
    }

    public function holds(string $lang = 'fa'): ?string
    {
        return $lang === 'fa' ? $this->holds_note_fa : $this->holds_note_en;
    }

    /** Who released it — a platform user's name, or the external inspector. */
    public function attributedTo(): string
    {
        if ($this->inspectorUser !== null) {
            return $this->inspectorUser->name;
        }

        $name = (string) $this->inspector_name;
        $company = $this->inspectorCompany?->code;

        return $company !== null && $company !== '' ? $name.' · '.$company : $name;
    }
}
