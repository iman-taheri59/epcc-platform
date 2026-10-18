<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * ADR-008 §7 question 4 (answered: yes). A piping class is drafted, then
 * published; a published class is read-only — corrections create a new
 * revision (revisions land in ADR-008 build phase 5). A gapped class may
 * exist as a draft but may never be published, which is what keeps lines
 * from referencing a PMS with holes.
 */
final class PipingClassState
{
    public const DRAFT = 'DRAFT';
    public const PUBLISHED = 'PUBLISHED';

    public const ALL = [self::DRAFT, self::PUBLISHED];

    /** @return array{fa:string,en:string} */
    public static function label(string $state): array
    {
        return match ($state) {
            self::DRAFT => ['fa' => 'پیش‌نویس', 'en' => 'Draft'],
            self::PUBLISHED => ['fa' => 'منتشرشده', 'en' => 'Published'],
            default => ['fa' => $state, 'en' => $state],
        };
    }
}
