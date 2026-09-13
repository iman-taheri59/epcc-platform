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
        'project_id', 'company_id', 'code', 'name_en', 'name_fa', 'discipline',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
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
