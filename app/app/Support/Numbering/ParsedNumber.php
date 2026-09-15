<?php

declare(strict_types=1);

namespace App\Support\Numbering;

/**
 * The outcome of reading a number back into its parts.
 *
 * WHY THIS IS NOT JUST AN ARRAY OR A BOOLEAN
 * ------------------------------------------
 * Parsing a supplied line number has three distinguishable outcomes, and an
 * import that collapses them into "ok / not ok" throws away the only
 * information the engineer can act on:
 *
 *   1. It does not match the project's format at all      -> matched = false
 *   2. It matches, and every value is registered          -> valid
 *   3. It matches, but a value is not in the register     -> matched with
 *      (e.g. piping class "A1" is not on this project)       unknown values
 *
 * Case 3 is the useful one and the one a boolean destroys. The number is
 * well-formed, so we know exactly which token is wrong and can say "piping
 * class A1 is not registered on this project" instead of "invalid line
 * number" - which would send someone hunting through a 40,000-row import for
 * a formatting error that is not there.
 */
final class ParsedNumber
{
    /**
     * @param  array<string, string>  $tokens        token name => matched text
     * @param  array<string, string>  $unknownValues token name => value not in the vocabulary
     */
    /**
     * @param  array<string, string>  $tokens        token name => matched text
     * @param  array<string, string>  $unknownValues token name => value not in the vocabulary
     * @param  list<string>  $unconfirmed  tokens matched with no vocabulary to check against
     */
    private function __construct(
        public readonly bool $matched,
        public readonly string $number,
        public readonly array $tokens = [],
        public readonly array $unknownValues = [],
        public readonly ?string $reason = null,
        public readonly array $unconfirmed = [],
    ) {}

    /**
     * @param  array<string, string>  $tokens
     * @param  array<string, string>  $unknownValues
     */
    /**
     * @param  array<string, string>  $tokens
     * @param  array<string, string>  $unknownValues
     * @param  list<string>  $unconfirmed
     */
    public static function match(string $number, array $tokens, array $unknownValues = [], array $unconfirmed = []): self
    {
        return new self(true, $number, $tokens, $unknownValues, null, $unconfirmed);
    }

    public static function noMatch(string $number, string $reason): self
    {
        return new self(false, $number, [], [], $reason);
    }

    /** Matched the shape AND every value is registered. */
    public function isValid(): bool
    {
        return $this->matched && $this->unknownValues === [];
    }

    /** Well-formed, but at least one value is not registered on this project. */
    public function hasUnknownValues(): bool
    {
        return $this->matched && $this->unknownValues !== [];
    }

    /**
     * Was every token actually checked against a register?
     *
     * isValid() answers "nothing was found wrong", which is not the same as
     * "everything was verified". When no vocabulary is supplied for a token it
     * is matched loosely and nothing contradicts it - so an import preview that
     * reports such a number as clean would be overstating what it knows.
     */
    public function isFullyChecked(): bool
    {
        return $this->matched && $this->unconfirmed === [];
    }

    public function token(string $name): ?string
    {
        return $this->tokens[$name] ?? null;
    }

    /**
     * The allocated counter value, as an integer.
     *
     * Returns null when the pattern has no counter or it did not match, so a
     * caller can tell "no sequence in this scheme" from "sequence 0".
     */
    public function sequence(string $counterName = 'sequence'): ?int
    {
        $raw = $this->tokens[$counterName] ?? null;

        return $raw === null ? null : (int) $raw;
    }

    /**
     * A single sentence explaining the outcome, for an import preview.
     *
     * @return array{en: string, fa: string}
     */
    public function explain(): array
    {
        if (! $this->matched) {
            return [
                'en' => 'Does not match the project line number format: '.($this->reason ?? 'no match'),
                'fa' => 'با قالب شمارهٔ خط پروژه مطابقت ندارد: '.($this->reason ?? 'عدم تطابق'),
            ];
        }

        if ($this->unknownValues === []) {
            if ($this->unconfirmed !== []) {
                $list = implode(', ', $this->unconfirmed);

                return [
                    'en' => "Format is correct. Not checked against a register: {$list}.",
                    'fa' => "قالب درست است. بدون بررسی در برابر فهرست ثبت‌شده: {$list}.",
                ];
            }

            return ['en' => 'Valid.', 'fa' => 'معتبر.'];
        }

        $parts = [];
        foreach ($this->unknownValues as $token => $value) {
            $parts[] = "{$token}='{$value}'";
        }
        $list = implode(', ', $parts);

        return [
            'en' => "Format is correct but these values are not registered on this project: {$list}.",
            'fa' => "قالب درست است ولی این مقادیر در این پروژه ثبت نشده‌اند: {$list}.",
        ];
    }
}
