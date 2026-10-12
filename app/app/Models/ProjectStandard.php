<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This project works to this code, at this edition."
 *
 * The applicability list. The catalogue says what exists; this says what the
 * contract signed up to, which is a different and narrower question — a
 * refinery project does not work to every standard ASME ever published.
 *
 * The `edition` column is the point of the whole table. The catalogue carries
 * the current edition; a project pins its own. When a platform administrator
 * updates the catalogue from 2020 to 2022, a project bound to 2020 must not
 * move: its acceptance criteria are contractual, and inspections already
 * signed were signed against the older text.
 */
final class ProjectStandard extends Model
{
    use HasUuid;

    protected $fillable = [
        'project_id', 'standard_id', 'edition',
        'remarks_en', 'remarks_fa', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Standard, $this> */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    /**
     * The edition in force for this project.
     *
     * Falls back to the catalogue when the project has taken no contractual
     * position — which is the honest answer for most standards, and better
     * than printing nothing.
     */
    public function effectiveEdition(): ?string
    {
        return $this->edition ?: $this->standard?->edition;
    }

    /**
     * The full citation as it should appear on a project document:
     * 'ASME B31.3-2020'.
     */
    public function citation(): string
    {
        $code = $this->standard?->code() ?? '';
        $edition = $this->effectiveEdition();

        return $edition ? $code.'-'.$edition : $code;
    }

    /**
     * Has this project deliberately frozen an older edition than the
     * catalogue's?
     *
     * Worth surfacing on screen: it is the difference between "nobody has
     * looked at this" and "we are knowingly working to the 2020 text".
     */
    public function isPinnedBehindCatalogue(): bool
    {
        $catalogue = $this->standard?->edition;

        return $this->edition !== null
            && $catalogue !== null
            && $this->edition !== $catalogue;
    }
}
