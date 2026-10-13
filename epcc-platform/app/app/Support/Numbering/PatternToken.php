<?php

declare(strict_types=1);

namespace App\Support\Numbering;

use App\Support\Numbering\Exceptions\InvalidPatternException;

/**
 * One typed token in a number pattern (ADR-006 §4).
 *
 * Immutable and free of I/O: a token knows its shape, never where the values
 * come from. Vocabularies are injected at compose/parse time by the engine,
 * because the list of piping classes is a per-project database query and this
 * object has to stay cheap enough to build on every render.
 */
final class PatternToken
{
    /**
     * @param  string  $name       identifier used in the token map: 'size', 'service'…
     * @param  string  $kind       TokenKind::*
     * @param  string|null  $attribute  column this token reads, when it differs from the name
     * @param  int  $width         zero-padding for counters; 0 means no padding
     * @param  bool  $optional     may be absent from the number entirely
     * @param  list<string>  $values  closed vocabulary, when it is fixed rather than looked up
     */
    public function __construct(
        public readonly string $name,
        public readonly string $kind,
        public readonly ?string $attribute = null,
        public readonly int $width = 0,
        public readonly bool $optional = false,
        public readonly array $values = [],
    ) {
        if ($this->name === '') {
            throw new InvalidPatternException('A token needs a name.');
        }

        if (! TokenKind::isKnown($this->kind)) {
            throw new InvalidPatternException("Unknown token kind '{$this->kind}' for token '{$this->name}'.");
        }

        if ($this->width < 0) {
            throw new InvalidPatternException("Token '{$this->name}' has a negative width.");
        }

        if ($this->kind === TokenKind::LITERAL && $this->values === []) {
            throw new InvalidPatternException("Literal token '{$this->name}' needs its text in values[0].");
        }

        if ($this->kind === TokenKind::COUNTER && $this->optional) {
            // An optional counter means "sometimes numbered, sometimes not",
            // which is a numbering_policy decision (ADR-006 §10.1) and must not
            // hide inside a format string.
            throw new InvalidPatternException("Counter token '{$this->name}' cannot be optional.");
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $name = $row['token'] ?? $row['name'] ?? null;
        if (! is_string($name)) {
            throw new InvalidPatternException('Each token needs a "token" name.');
        }

        $kind = $row['source'] ?? $row['kind'] ?? TokenKind::ATTRIBUTE;
        if (! is_string($kind)) {
            throw new InvalidPatternException("Token '{$name}' has a non-string source.");
        }

        /** @var list<string> $values */
        $values = [];
        if (isset($row['values']) && is_array($row['values'])) {
            foreach ($row['values'] as $v) {
                // Cast rather than reject: PHP turns the numeric-string array
                // keys of a JSON object into integers, a trap already recorded
                // in HANDOFF §8 from the reference packs.
                $values[] = (string) $v;
            }
        }

        return new self(
            name: $name,
            kind: $kind,
            attribute: isset($row['attribute']) && is_string($row['attribute']) ? $row['attribute'] : null,
            width: isset($row['width']) ? (int) $row['width'] : 0,
            optional: (bool) ($row['optional'] ?? false),
            values: $values,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['token' => $this->name, 'source' => $this->kind];

        if ($this->attribute !== null) {
            $out['attribute'] = $this->attribute;
        }
        if ($this->width > 0) {
            $out['width'] = $this->width;
        }
        if ($this->optional) {
            $out['optional'] = true;
        }
        if ($this->values !== []) {
            $out['values'] = $this->values;
        }

        return $out;
    }

    /** The record column this token reads; defaults to the token's own name. */
    public function attributeName(): string
    {
        return $this->attribute ?? $this->name;
    }

    public function hasVocabulary(): bool
    {
        return $this->values !== [];
    }

    /** The fixed text of a literal token. */
    public function literal(): string
    {
        return $this->values[0] ?? '';
    }

    /**
     * Render a value for inclusion in a number.
     *
     * Padding is applied HERE and never stored: ADR-006 §11.1 is explicit that
     * joint_seq is an integer and "007" is presentation. Same reasoning for
     * line sequences.
     */
    public function format(string|int $value): string
    {
        $text = (string) $value;

        if ($this->kind === TokenKind::COUNTER && $this->width > 0) {
            return str_pad($text, $this->width, '0', STR_PAD_LEFT);
        }

        return $text;
    }
}
