<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Support\PunchCategory;
use App\Support\PunchState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A subsystem: the unit that gets mechanically completed and handed over.
 */
final class Subsystem extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'company_id', 'facility_id', 'area_id',
        'code', 'name_en', 'name_fa', 'discipline',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    /** Null is a valid answer: the area level is optional (ADR-004). */
    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    /**
     * The unambiguous identifier for this subsystem.
     *
     * Subsystem codes are unique per FACILITY, not per project (the user's
     * decision in ADR-004), because two plants in one project legitimately both
     * want SS-2201. So `code` alone is no longer safe to print anywhere a
     * reader might compare across facilities - a register, a report, a
     * certificate.
     *
     * Deliberately NOT stored: a composed identifier in a column is a second
     * copy of the truth that drifts the first time a facility is renamed. The
     * one place a composed code IS frozen is an issued certificate, which
     * snapshots what it printed at the time of issue.
     *
     * Falls back to the bare code when the facility is not loaded and cannot be
     * reached, so that a partially-hydrated model degrades to the old
     * behaviour rather than throwing inside a view.
     */
    public function fullCode(): string
    {
        $facility = $this->relationLoaded('facility') ? $this->facility : $this->facility()->first();

        return $facility === null ? $this->code : $facility->code.' / '.$this->code;
    }

    public function punchItems(): HasMany
    {
        return $this->hasMany(PunchItem::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(McCertificate::class);
    }

    public function name(string $lang = 'fa'): string
    {
        return $lang === 'fa' ? $this->name_fa : $this->name_en;
    }

    /**
     * The items standing between this subsystem and its MC certificate.
     *
     * Derived on every call rather than cached in a column: a stored counter is
     * wrong the moment somebody closes an item in another session, and this
     * number is what the gate refuses on.
     *
     * @return \Illuminate\Support\Collection<int,PunchItem>
     */
    public function openBlockingItems()
    {
        return $this->punchItems
            ->filter(fn (PunchItem $i) => PunchCategory::blocksMechanicalCompletion($i->category)
                && PunchState::isOpen($i->state))
            ->values();
    }

    /** Percentage of punch items closed, for the register. 100 when there are none. */
    public function closurePercent(): int
    {
        $total = $this->punchItems->count();

        if ($total === 0) {
            return 100;
        }

        $closed = $this->punchItems->filter(fn (PunchItem $i) => ! PunchState::isOpen($i->state))->count();

        return (int) round($closed / $total * 100);
    }
}
