<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One code or standard in the global catalogue: ASME B31.3, API 5L, EN 10204.
 *
 * Reference data, shared by every project and owned by whoever runs the
 * platform — the same call as `itp_templates` and the reference packs. No
 * project_id, no RLS: "which codes exist" is not tenant data, and copying the
 * catalogue into every project would guarantee forty spellings of B31.3.
 */
final class Standard extends Model
{
    use HasUuid;

    // Categories. Coarse on purpose: this is a filter on a picker, not a
    // taxonomy. A standard that spans two of them gets the one an engineer
    // would look under first.
    public const PIPING = 'PIPING';
    public const WELDING = 'WELDING';
    public const NDE = 'NDE';
    public const MATERIAL = 'MATERIAL';
    public const ELECTRICAL = 'ELECTRICAL';
    public const INSTRUMENT = 'INSTRUMENT';
    public const CIVIL = 'CIVIL';
    public const HSE = 'HSE';
    public const QUALITY = 'QUALITY';
    public const GENERAL = 'GENERAL';

    /** @var list<string> */
    public const CATEGORIES = [
        self::PIPING, self::WELDING, self::NDE, self::MATERIAL,
        self::ELECTRICAL, self::INSTRUMENT, self::CIVIL, self::HSE,
        self::QUALITY, self::GENERAL,
    ];

    protected $fillable = [
        'body', 'designation', 'normalised', 'title_en', 'title_fa',
        'edition', 'category', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Reduce a citation to its identity.
     *
     * 'ASME B31.3', 'asme b 31.3', 'ASME-B31.3' and 'ASME  B31.3' are one
     * standard written four ways. Strip everything humans vary — case, spaces,
     * dots, dashes, underscores — and what is left is the key.
     *
     * Deliberately NOT stripping digits or letters: 'B31.3' and 'B313' collide
     * to the same value, which is correct, because they are the same standard.
     */
    public static function normalise(string $body, string $designation): string
    {
        $raw = $body.$designation;

        return (string) preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($raw));
    }

    public static function categoryIsValid(string $category): bool
    {
        return in_array($category, self::CATEGORIES, true);
    }

    /**
     * The citation as an engineer writes it: 'ASME B31.3'.
     *
     * The edition is deliberately NOT included. Which edition applies is a
     * project question answered by `ProjectStandard`, and baking the
     * catalogue's edition into the label here would print the wrong year on
     * a project pinned to an older one.
     */
    public function code(): string
    {
        return trim($this->body.' '.$this->designation);
    }

    /** The label for a picker: code plus title in the reader's language. */
    public function label(string $lang = 'fa'): string
    {
        $title = $lang === 'fa' ? ($this->title_fa ?: $this->title_en) : $this->title_en;

        return $this->code().' — '.$title;
    }

    /** @return HasMany<ProjectStandard, $this> */
    public function adoptions(): HasMany
    {
        return $this->hasMany(ProjectStandard::class);
    }

    /** @return HasMany<StandardCitation, $this> */
    public function citations(): HasMany
    {
        return $this->hasMany(StandardCitation::class);
    }
}
