<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\PunchCategory;
use App\Support\PunchState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A punch item: a defect found during a walkdown (FR-COM-04).
 */
final class PunchItem extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'subsystem_id', 'company_id', 'responsible_company_id',
        'number', 'sequence', 'category', 'state', 'description_en',
        'description_fa', 'location', 'raised_by', 'raised_at', 'fixed_by',
        'fixed_at', 'fix_note', 'verified_by', 'verified_at',
        'verification_note', 'closed_by', 'closed_at',
    ];

    protected $casts = [
        'raised_at' => 'datetime',
        'fixed_at' => 'datetime',
        'verified_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function subsystem(): BelongsTo
    {
        return $this->belongsTo(Subsystem::class);
    }

    /** The company that RAISED the item. */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /** The company that must FIX it. */
    public function responsibleCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'responsible_company_id');
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function description(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->description_fa : $this->description_en;
    }

    public function isOpen(): bool
    {
        return PunchState::isOpen($this->state);
    }

    /**
     * Does this item stand between its subsystem and Mechanical Completion?
     * Both halves matter: a closed A-item blocks nothing, and an open C-item
     * blocks nothing either.
     */
    public function blocksMc(): bool
    {
        return PunchCategory::blocksMechanicalCompletion($this->category) && $this->isOpen();
    }

    public function stateLabel(string $lang = 'fa'): string
    {
        return PunchState::label($this->state)[$lang] ?? $this->state;
    }

    public function categoryLabel(string $lang = 'fa'): string
    {
        return PunchCategory::shortLabel($this->category, $lang);
    }
}
