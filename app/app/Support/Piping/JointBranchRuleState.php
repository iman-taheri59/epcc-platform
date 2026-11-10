<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * Whether a branch weld has a governing PMS rule, and if not, why not.
 *
 * Three states rather than a nullable verdict, because "no rule" has two very
 * different causes that two different people have to fix:
 *
 *   - `NO_SNAPSHOT` — the line was never given an issued PMS class. That is an
 *     upstream data gap: someone has to classify the line.
 *   - `NO_RULE` — the class exists and is issued, but says nothing about this
 *     header x branch size pair. That is a PMS authoring gap: someone has to
 *     add the cell to the branch table.
 *
 * Collapsing them into a null would make every ungoverned branch weld look the
 * same, and the two are chased by different people.
 *
 * `NO_RULE` also covers the cases where the question could not be asked at all
 * — a size missing from the ports, or a branch larger than its header. Those
 * are malformed rather than merely unmatched, but the action is the same
 * either way: someone opens the branch table and looks.
 */
final class JointBranchRuleState
{
    /** A rule was found. The verdict columns are populated. */
    public const RULE = 'RULE';

    /** The line has no issued PMS class, so there was nothing to ask. */
    public const NO_SNAPSHOT = 'NO_SNAPSHOT';

    /** The class was asked and had no cell for this size pair (or could not be asked). */
    public const NO_RULE = 'NO_RULE';

    /** @var list<string> */
    public const ALL = [
        self::RULE,
        self::NO_SNAPSHOT,
        self::NO_RULE,
    ];

    /** @return array<string, array{en: string, fa: string}> */
    public static function labels(): array
    {
        return [
            self::RULE => ['en' => 'Governed by a class rule', 'fa' => 'دارای قاعدهٔ کلاس'],
            self::NO_SNAPSHOT => ['en' => 'Line has no issued class', 'fa' => 'خط کلاسِ صادرشده ندارد'],
            self::NO_RULE => ['en' => 'No rule for this size pair', 'fa' => 'قاعده‌ای برای این زوجِ سایز نیست'],
        ];
    }
}
