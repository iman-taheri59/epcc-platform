<?php

declare(strict_types=1);

namespace App\Support\Numbering;

use App\Support\Numbering\Exceptions\UnknownTokenValueException;

/**
 * Composes and parses numbers from a typed pattern (ADR-006 §4).
 *
 * Pure: no database, no locale, no clock. Vocabularies arrive as an array from
 * the caller, which is what lets the same engine serve piping lines today and
 * pipe supports in phase 5 (ADR-006 §11.3) - one engine, two configurations.
 *
 * THE PARSER IS THE REASON THIS CLASS EXISTS
 * ------------------------------------------
 * Composing is easy and could have been sprintf. Reading a number BACK is the
 * operation that earns its keep, because lines usually arrive already numbered
 * from the P&ID or the 3D model, and the check that matters is whether each
 * part of that number is something the project has actually registered.
 *
 * It cannot be done by splitting on the separator. Subsystem codes on this very
 * project look like PH-14-SS-2201, so the separator occurs three times inside
 * one token value and explode() returns nine fragments for a six-token pattern.
 * Instead every token contributes a sub-pattern to one anchored regex:
 * vocabularies become alternations (longest first, since PCRE alternation is
 * first-match, not longest-match), counters become \d+, and a multi-dash value
 * is matched as a unit because it is one alternative.
 */
final class NumberEngine
{
    /**
     * Build a number from a record's values.
     *
     * @param  array<string, string|int|null>  $values  token name => value
     * @param  array<string, list<string>>  $vocabularies  token name => allowed values
     *
     * @throws UnknownTokenValueException when a value is missing or unregistered
     */
    public function compose(NumberPattern $pattern, array $values, array $vocabularies = []): string
    {
        $parts = [];

        foreach ($pattern->tokens() as $token) {
            if ($token->kind === TokenKind::LITERAL) {
                $parts[] = $token->literal();

                continue;
            }

            $raw = $values[$token->name] ?? null;

            if ($raw === null || $raw === '') {
                if ($token->optional) {
                    continue;
                }

                throw new UnknownTokenValueException(
                    "Cannot compose the number: token '{$token->name}' has no value."
                );
            }

            $text = $token->format($raw);

            // Validate against the vocabulary while composing, not only while
            // parsing. Otherwise the system happily mints a number containing a
            // piping class that does not exist, and the error surfaces later as
            // an unparseable number of our own making.
            if ($token->kind !== TokenKind::COUNTER) {
                $allowed = $this->vocabularyFor($token, $vocabularies);

                if ($allowed !== null && ! in_array($text, $allowed, true)) {
                    throw new UnknownTokenValueException(
                        "Cannot compose the number: '{$text}' is not a registered value for '{$token->name}'."
                    );
                }
            }

            $parts[] = $text;
        }

        return implode($pattern->separator, $parts);
    }

    /**
     * Read a number back into its tokens.
     *
     * Never throws: an unreadable number is an ordinary outcome during import,
     * not an exceptional one, and the caller needs the diagnosis rather than a
     * stack trace.
     *
     * @param  array<string, list<string>>  $vocabularies
     */
    public function parse(NumberPattern $pattern, string $number, array $vocabularies = []): ParsedNumber
    {
        // TWO PASSES, AND THE SECOND ONE IS WHY THE ERRORS ARE USEFUL
        // -----------------------------------------------------------
        // Pass 1 is strict: every vocabulary token is an alternation of its
        // registered values. This is the authoritative read, and it is the only
        // way a multi-dash value like PH-14-SS-2201 can be matched as one unit.
        //
        // But strictness destroys diagnosis. Feed it a number whose piping
        // class is "ZZ" and the alternation simply does not match, so the whole
        // number is reported as malformed - sending an engineer to hunt for a
        // formatting error that is not there, when the real answer is "class ZZ
        // is not registered on this project".
        //
        // So pass 2 re-reads the SAME number with vocabulary tokens relaxed to
        // "anything that is not a separator", purely to find out WHICH token is
        // wrong. It is diagnostic only: any value it recovers is reported as
        // unknown, never as accepted.
        $strict = $this->regexFor($pattern, $vocabularies);

        if (@preg_match($strict, $number, $matches) === 1) {
            return $this->collect($pattern, $number, $matches, $vocabularies);
        }

        // Relax ONE token at a time, keeping every other vocabulary strict.
        //
        // Relaxing all of them at once does not work, and the reason is the
        // same fact that shaped this whole class: a relaxed token compiles to
        // [^-]+, which cannot span the dashes inside PH-14-SS-2201. With every
        // token loose the regex fails again and the diagnosis is lost. Keeping
        // the other vocabularies strict leaves those multi-dash values anchored
        // by their own alternations, so the one relaxed token is free to absorb
        // whatever unregistered text is actually there.
        //
        // First relaxation that matches wins, and its value is reported as
        // unknown - never as accepted.
        foreach ($pattern->tokens() as $candidate) {
            if ($candidate->kind === TokenKind::COUNTER || $candidate->kind === TokenKind::LITERAL) {
                continue;
            }

            if ($this->vocabularyFor($candidate, $vocabularies) === null) {
                continue; // already loose in the strict pass
            }

            $relaxed = $this->regexFor($pattern, $vocabularies, $candidate->name);

            if (@preg_match($relaxed, $number, $matches) === 1) {
                return $this->collect($pattern, $number, $matches, $vocabularies);
            }
        }

        return ParsedNumber::noMatch($number, 'expected '.$pattern->sketch());
    }

    /**
     * Turn regex captures into a result, classifying every value.
     *
     * @param  array<string, string>  $matches
     * @param  array<string, list<string>>  $vocabularies
     */
    private function collect(NumberPattern $pattern, string $number, array $matches, array $vocabularies): ParsedNumber
    {
        $tokens = [];
        $unknown = [];
        $unconfirmed = [];

        foreach ($pattern->tokens() as $token) {
            if ($token->kind === TokenKind::LITERAL) {
                continue;
            }

            $value = $matches[$token->name] ?? '';
            if ($value === '') {
                continue; // optional token, absent
            }

            $tokens[$token->name] = $value;

            if ($token->kind === TokenKind::COUNTER) {
                continue;
            }

            $allowed = $this->vocabularyFor($token, $vocabularies);

            if ($allowed === null) {
                // Nothing to check against: matched, but unverified. Reported
                // separately so a preview cannot claim more than it knows.
                $unconfirmed[] = $token->name;
            } elseif (! in_array($value, $allowed, true)) {
                $unknown[$token->name] = $value;
            }
        }

        return ParsedNumber::match($number, $tokens, $unknown, $unconfirmed);
    }

    /**
     * Does this number fit the pattern and use only registered values?
     *
     * @param  array<string, list<string>>  $vocabularies
     */
    public function validate(NumberPattern $pattern, string $number, array $vocabularies = []): ParsedNumber
    {
        return $this->parse($pattern, $number, $vocabularies);
    }

    /**
     * The anchored regex for a pattern. Public so a test can inspect it and a
     * configuration screen can show why a number was rejected.
     *
     * @param  array<string, list<string>>  $vocabularies
     */
    public function regexFor(NumberPattern $pattern, array $vocabularies = [], ?string $relaxToken = null): string
    {
        $sep = preg_quote($pattern->separator, '/');
        $body = '';
        $first = true;

        foreach ($pattern->tokens() as $token) {
            $piece = $this->pieceFor(
                $token,
                $token->name === $relaxToken ? [] : $vocabularies,
                $token->name === $relaxToken,
            );

            if ($token->kind === TokenKind::LITERAL) {
                $body .= ($first ? '' : $sep).$piece;
                $first = false;

                continue;
            }

            if ($token->optional) {
                // The separator belongs INSIDE the optional group, or a missing
                // trailing token leaves a dangling separator that fails to
                // match. A leading optional token keeps its own trailing
                // separator instead.
                $body .= $first
                    ? '(?:'.$piece.$sep.')?'
                    : '(?:'.$sep.$piece.')?';

                continue;
            }

            $body .= ($first ? '' : $sep).$piece;
            $first = false;
        }

        return '/^'.$body.'$/u';
    }

    /**
     * The sub-pattern for one token.
     *
     * @param  array<string, list<string>>  $vocabularies
     */
    private function pieceFor(PatternToken $token, array $vocabularies, bool $forceLoose = false): string
    {
        if ($token->kind === TokenKind::LITERAL) {
            return preg_quote($token->literal(), '/');
        }

        if ($token->kind === TokenKind::COUNTER) {
            return '(?P<'.$token->name.'>\d+)';
        }

        $allowed = $forceLoose ? null : $this->vocabularyFor($token, $vocabularies);

        if ($allowed === null || $allowed === []) {
            // No vocabulary, so the value can only be recognised by shape.
            //
            // [^-]+ is the obvious choice and it is too weak: a subsystem code
            // is PH-14-SS-2201, so a token whose real value CONTAINS the
            // separator would never match and the number would be reported as
            // malformed when the only problem is an unregistered value.
            //
            // `.+?` spans separators, and the surrounding tokens - which keep
            // their strict alternations - pin it down from both sides. Lazy,
            // so it gives back as much as possible and the following token
            // still gets its match; the anchors at both ends of the whole
            // expression prevent it from swallowing the rest of the number.
            return '(?P<'.$token->name.'>.+?)';
        }

        // LONGEST FIRST. PCRE alternation takes the first alternative that
        // matches, not the longest, so with 'A1|A1B' the input A1B matches A1
        // and the remainder fails. Sorting descending by length makes the
        // first match the right one - and this is exactly what lets
        // PH-14-SS-2201 win over any shorter code sharing its prefix.
        $sorted = $allowed;
        usort($sorted, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $alts = implode('|', array_map(
            static fn (string $v): string => preg_quote($v, '/'),
            $sorted,
        ));

        return '(?P<'.$token->name.'>'.$alts.')';
    }

    /**
     * Values allowed for a token: the pattern's own list, else the supplied
     * vocabulary, else null meaning "unconstrained".
     *
     * @param  array<string, list<string>>  $vocabularies
     * @return list<string>|null
     */
    private function vocabularyFor(PatternToken $token, array $vocabularies): ?array
    {
        if ($token->hasVocabulary()) {
            return $token->values;
        }

        return $vocabularies[$token->name] ?? null;
    }

}
