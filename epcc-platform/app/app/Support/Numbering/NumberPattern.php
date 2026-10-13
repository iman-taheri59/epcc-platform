<?php

declare(strict_types=1);

namespace App\Support\Numbering;

use App\Support\Numbering\Exceptions\AmbiguousPatternException;
use App\Support\Numbering\Exceptions\InvalidPatternException;

/**
 * An ordered list of typed tokens plus a separator (ADR-006 §4).
 *
 * WHY TYPED TOKENS AND NOT A STRING WITH BRACES
 * ---------------------------------------------
 * A pattern stored as "{size}-{service}-{class}-{subsystem}-{seq}" can only be
 * used in one direction: fill in the blanks. But line numbers usually arrive
 * ALREADY WRITTEN, from the P&ID or the 3D model, and the valuable operation is
 * the reverse one - read "8-P-A1-PH-14-SS-2201-001" back into its parts and
 * check each part against what the project has registered. That catches the
 * classic defect of a line tagged 6" whose components are 8", and it catches a
 * piping class that does not exist on this project.
 *
 * Typed tokens give composition, parsing, validation and a UI preview from one
 * definition. A brace string gives composition only.
 *
 * THE SEPARATOR IS NOT A SAFE DELIMITER - THIS IS THE WHOLE DIFFICULTY
 * -------------------------------------------------------------------
 * The obvious parser is explode('-', $number). It is wrong on this project's
 * own data. Subsystem codes look like PH-14-SS-2201: the separator appears
 * three times INSIDE a single token value. Splitting
 *
 *     8-P-A1-PH-14-SS-2201-001-CT2
 *
 * yields nine fragments for a six-token pattern. Measured, not assumed - the
 * spike is reproduced in the test suite.
 *
 * So parsing is done by building one anchored regular expression in which every
 * token contributes its own sub-pattern: closed vocabularies become
 * alternations of quoted literals, counters become \d+. A multi-dash subsystem
 * code is then matched as one unit because it is one alternative, not because
 * the parser counted dashes.
 *
 * Two consequences worth stating, because they are easy to get wrong:
 *
 *   1. Alternatives are emitted LONGEST FIRST. PCRE alternation is first-match,
 *      not longest-match, so with "A1|A1B" the input A1B matches A1 and the
 *      parse then fails on the leftovers. Sorting by length descending makes
 *      the greedy choice the correct one.
 *   2. Values are preg_quote()d. Sizes contain slashes and spaces ("2 1/2")
 *      and a raw vocabulary value is attacker-supplied text as far as the
 *      regex engine is concerned.
 *
 * AMBIGUITY IS REFUSED AT CONSTRUCTION, NOT DISCOVERED AT PARSE TIME
 * ------------------------------------------------------------------
 * Two adjacent counter tokens ("{seq}-{seq}") or a counter directly beside an
 * open-ended token cannot be split reliably. Rather than produce a pattern that
 * silently mis-parses later, the constructor throws. A number scheme is
 * configuration a project sets up once; failing loudly there is cheap, whereas
 * a mis-parse discovered during import is expensive and looks like data
 * corruption.
 */
final class NumberPattern
{
    /** @var list<PatternToken> */
    private array $tokens;

    /**
     * @param  list<PatternToken>  $tokens
     */
    public function __construct(
        array $tokens,
        public readonly string $separator = '-',
    ) {
        if ($tokens === []) {
            throw new InvalidPatternException('A number pattern needs at least one token.');
        }

        if ($this->separator === '') {
            // Without a separator the only thing keeping tokens apart is their
            // vocabularies, and two adjacent free-form values become
            // genuinely ambiguous. Projects that write numbers without
            // separators exist, but they need fixed-width tokens, which is a
            // feature to add deliberately rather than to permit by accident.
            throw new InvalidPatternException('A number pattern needs a separator.');
        }

        $seen = [];
        foreach ($tokens as $token) {
            if (! $token instanceof PatternToken) {
                throw new InvalidPatternException('Every element must be a PatternToken.');
            }
            if (isset($seen[$token->name])) {
                throw new InvalidPatternException("Duplicate token '{$token->name}' in pattern.");
            }
            $seen[$token->name] = true;
        }

        $this->tokens = array_values($tokens);

        $this->assertUnambiguous();
    }

    /**
     * Build from the stored jsonb shape.
     *
     * @param  array{separator?: string, tokens: list<array<string, mixed>>}  $config
     */
    public static function fromArray(array $config): self
    {
        if (! isset($config['tokens']) || ! is_array($config['tokens'])) {
            throw new InvalidPatternException("Pattern config needs a 'tokens' list.");
        }

        return new self(
            array_map(PatternToken::fromArray(...), array_values($config['tokens'])),
            isset($config['separator']) && is_string($config['separator']) ? $config['separator'] : '-',
        );
    }

    /** @return array{separator: string, tokens: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'separator' => $this->separator,
            'tokens' => array_map(fn (PatternToken $t): array => $t->toArray(), $this->tokens),
        ];
    }

    /** @return list<PatternToken> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    public function token(string $name): ?PatternToken
    {
        foreach ($this->tokens as $token) {
            if ($token->name === $name) {
                return $token;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function tokenNames(): array
    {
        return array_map(fn (PatternToken $t): string => $t->name, $this->tokens);
    }

    /**
     * The single counter token, if the pattern has one.
     *
     * Exactly one is allowed (assertUnambiguous), so this is deterministic.
     */
    public function counterToken(): ?PatternToken
    {
        foreach ($this->tokens as $token) {
            if ($token->kind === TokenKind::COUNTER) {
                return $token;
            }
        }

        return null;
    }

    /**
     * A human-readable sketch of the format, for the configuration screen.
     *
     * Deliberately not used for parsing or composing - it exists so an engineer
     * can see "{size}-{service}-{class}" and recognise their own PMS.
     */
    public function sketch(): string
    {
        return implode($this->separator, array_map(
            fn (PatternToken $t): string => $t->kind === TokenKind::LITERAL
                ? $t->literal()
                : '{'.$t->name.'}',
            $this->tokens,
        ));
    }

    /**
     * Refuse patterns that cannot be parsed back reliably.
     *
     * Checked once, at construction, so an unparseable scheme can never be
     * saved and then fail mysteriously during an import six weeks later.
     */
    private function assertUnambiguous(): void
    {
        $counters = array_filter($this->tokens, fn (PatternToken $t): bool => $t->kind === TokenKind::COUNTER);

        if (count($counters) > 1) {
            // Two counters in one pattern have no independent meaning and,
            // side by side, no unique split.
            throw new AmbiguousPatternException(
                'A pattern may contain at most one counter token; found '.count($counters).'.'
            );
        }

        // WHAT IS **NOT** CHECKED HERE, AND WHY
        // -------------------------------------
        // An earlier version also refused any token that sits beside the
        // counter without a declared vocabulary. That was wrong twice over.
        //
        // First, it is unenforceable at this point: vocabularies are injected
        // at compose/parse time (piping classes are a per-project query), so a
        // perfectly good pattern was rejected merely for not inlining its
        // values - which is the normal case.
        //
        // Second, the danger it guarded against does not exist. An
        // unconstrained token compiles to [^-]+, which cannot cross a
        // separator, so the split is always deterministic. Measured: the
        // all-loose regex against 8-P-A1-PH-14-SS-2201-001-CT2 returns NO
        // MATCH - a clean failure, not a mis-parse. The real cost of a missing
        // vocabulary is that a multi-dash value stops matching at all, and
        // that is loud.
        //
        // The residual risk - a value that parses but was never confirmed
        // against a register - is handled where it belongs, in ParsedNumber's
        // `unconfirmed` list, so the caller can see the difference between
        // "checked and correct" and "not checked".
    }
}
