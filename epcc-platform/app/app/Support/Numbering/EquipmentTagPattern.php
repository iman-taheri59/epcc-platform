<?php

declare(strict_types=1);

namespace App\Support\Numbering;

/**
 * The default equipment tag pattern, and the vocabulary it validates against.
 *
 * This is NOT a second numbering engine. `NumberEngine` already composes,
 * parses and validates any pattern of tokens; an equipment tag is simply a
 * different pattern fed to the same engine. Writing a parallel engine would
 * give two implementations of "does this number fit the format" that drift -
 * the same argument that produced one `ports` table rather than siblings.
 *
 * The shape follows the structure the user documented (IPS-E-PR-308, NPC,
 * Shell DEP, Total):
 *
 *     [Unit] - [Type] - [Sequence][Suffix]        11-P-101A
 *
 * The suffix is part of the sequence token rather than a token of its own,
 * because 'A' and 'B' identify parallel or standby items of ONE tagged
 * position - `101A` and `101B` are the two halves of pump 101, not pumps 101
 * and 102. Splitting them would let the system allocate `101` to one pump and
 * `101A` to an unrelated one.
 */
final class EquipmentTagPattern
{
    /**
     * @return array{separator: string, tokens: list<array<string, mixed>>}
     */
    public static function default(): array
    {
        return [
            'separator' => '-',
            'tokens' => [
                ['token' => 'unit', 'source' => 'attribute', 'attribute' => 'unit_code'],
                ['token' => 'type', 'source' => 'attribute', 'attribute' => 'equipment_type'],

                // Three digits is the near-universal convention (101, 201).
                // Width may grow but never shrink - the rule line numbering
                // already enforces, because a tag issued as 101 must not later
                // be read as 0101.
                ['token' => 'sequence', 'source' => 'counter', 'width' => 3],
            ],
        ];
    }

    /**
     * Equipment type codes for a project, as a NumberEngine vocabulary.
     *
     * Falls back to the default list when the project has not defined its own.
     * A closed vocabulary is what makes a typo'd 'PP-101' a refusal instead of
     * a new equipment class nobody meant to create.
     *
     * @param  array<string, mixed>|null  $configured
     * @return list<string>
     */
    public static function typeVocabulary(?array $configured): array
    {
        if ($configured === null || $configured === []) {
            return array_keys(\App\Models\Equipment::DEFAULT_TYPES);
        }

        // Accept either ['V','P'] or ['V' => [...labels], 'P' => [...]].
        return array_is_list($configured)
            ? array_map(strval(...), $configured)
            : array_keys($configured);
    }
}
