<?php

declare(strict_types=1);

namespace App\Support\Numbering;

/**
 * Where a token in a number pattern gets its value from (ADR-006 §4).
 *
 * Three sources, and the distinction is what makes parsing possible:
 *
 *   ATTRIBUTE  a column on the record itself (size, service, class, coating).
 *              Has a closed vocabulary, so it can be validated and, crucially,
 *              recognised when reading a number back.
 *   PARENT     borrowed from a parent row (the subsystem code). Also closed,
 *              but the vocabulary is a database lookup rather than a register.
 *   COUNTER    allocated by us. The only token that is not knowable in advance,
 *              and therefore the only one matched by shape (digits) rather
 *              than by value.
 *   LITERAL    a fixed string in the middle of a pattern, e.g. the "J" in
 *              a support tag. Carries no data; exists so a project whose
 *              format includes a constant does not need code.
 */
final class TokenKind
{
    public const ATTRIBUTE = 'attribute';
    public const PARENT = 'parent';
    public const COUNTER = 'counter';
    public const LITERAL = 'literal';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::ATTRIBUTE, self::PARENT, self::COUNTER, self::LITERAL];
    }

    public static function isKnown(string $kind): bool
    {
        return in_array($kind, self::all(), true);
    }

    /**
     * Does this kind carry a value that must be validated against a vocabulary?
     *
     * A counter is validated by shape and a literal is validated by being
     * itself; the other two must match something already registered, which is
     * exactly the check the user asked for in ADR-006 §11.5.
     */
    public static function needsVocabulary(string $kind): bool
    {
        return $kind === self::ATTRIBUTE || $kind === self::PARENT;
    }
}
