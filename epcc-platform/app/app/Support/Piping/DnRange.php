<?php

declare(strict_types=1);

namespace App\Support\Piping;

use InvalidArgumentException;

/**
 * A half-open DN band, [from, to) — ADR-008 §4.
 *
 * NOW USED ONLY BY THE BRANCH TABLE. The user removed bands from the size
 * rules: each size a class carries gets its own row, because that is how a PMS
 * is read. The branch table keeps ranges, because overlap there is deliberate
 * (a general rule plus narrower exceptions) and a cell-by-cell matrix would be
 * 120 rows for a class whose document states three.
 *
 * The class still exists because the half-open convention must not live only in
 * comments. PostgreSQL normalises whatever it is given: write '[15,100]' and it
 * silently becomes '[15,101)'. So the boundary is decided HERE, once.
 *
 * Why DN integers rather than NPS: NPS is a label, not a number. "1 1/4",
 * "1.25", "1-1/4" and "۱¼" are one size written four ways, and range logic on
 * floats invites 3.5 != 3.4999999.
 */
final readonly class DnRange
{
    private function __construct(
        public int $from,
        public int $toExclusive,
    ) {
    }

    /** Inclusive lower bound, exclusive upper bound. */
    public static function of(int $from, int $toExclusive): self
    {
        if ($from < 0) {
            throw new InvalidArgumentException("A DN band cannot start below zero, got {$from}.");
        }

        if ($toExclusive <= $from) {
            throw new InvalidArgumentException(
                "A DN band must be ascending and non-empty, got [{$from},{$toExclusive})."
            );
        }

        return new self($from, $toExclusive);
    }

    /** The literal PostgreSQL int4range wants. Always half-open. */
    public function toSql(): string
    {
        return "[{$this->from},{$this->toExclusive})";
    }

    /** Parse what PostgreSQL gives back. Normalised, so always [n,m). */
    public static function fromSql(string $literal): self
    {
        if (preg_match('/^\[(\d+),(\d+)\)$/', trim($literal), $m) !== 1) {
            throw new InvalidArgumentException(
                "Expected a normalised half-open int4range like [15,100), got '{$literal}'."
            );
        }

        return self::of((int) $m[1], (int) $m[2]);
    }

    public function contains(int $dn): bool
    {
        return $dn >= $this->from && $dn < $this->toExclusive;
    }

    /** How wide the band is — the specificity metric for branch rules. */
    public function span(): int
    {
        return $this->toExclusive - $this->from;
    }

    public function overlaps(self $other): bool
    {
        return $this->from < $other->toExclusive && $other->from < $this->toExclusive;
    }

    /**
     * For a screen. The stored bound is exclusive; a human reads the inclusive
     * one, so this shows the band the way the PMS wrote it.
     */
    public function label(): string
    {
        return "DN{$this->from}–DN".($this->toExclusive - 1);
    }
}
