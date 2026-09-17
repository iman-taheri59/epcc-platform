<?php

declare(strict_types=1);

namespace App\Support\Piping;

/**
 * The outcome of checking whether two ports may be joined (ADR-006 section 5.4).
 *
 * Three outcomes, not a boolean. A boolean forces every spec-break transition
 * to be either a lie or a blocker, and the EPCC reality is that the designer is
 * often right and the checker must be able to say "unusual, confirm" - the same
 * advisory pattern the user asked for in procurement inspection.
 *
 * The reasons travel with the verdict rather than being re-derived for display:
 * a WARNING whose reason is lost is indistinguishable from an unexplained one,
 * and nobody clears a warning they cannot read.
 */
final class Verdict
{
    public const VALID = 'VALID';

    public const WARNING = 'WARNING';

    public const INVALID = 'INVALID';

    /**
     * @param  list<array{code: string, params: array<string, scalar|null>}>  $reasons
     */
    private function __construct(
        public readonly string $state,
        public readonly array $reasons,
    ) {}

    /** @param list<array{code: string, params: array<string, scalar|null>}> $reasons */
    public static function make(string $state, array $reasons = []): self
    {
        return new self($state, $reasons);
    }

    public function isValid(): bool
    {
        return $this->state === self::VALID;
    }

    public function isInvalid(): bool
    {
        return $this->state === self::INVALID;
    }

    public function isWarning(): bool
    {
        return $this->state === self::WARNING;
    }

    /**
     * Reasons rendered in the caller's locale.
     *
     * Translation happens HERE and not inside the evaluator, so that the
     * evaluator stays a pure function with no locale and its verdicts can be
     * compared in a test without string matching against a language file.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(
            static fn (array $r): string => __('epcc.joint.reason.'.$r['code'], $r['params']),
            $this->reasons,
        );
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_map(static fn (array $r): string => $r['code'], $this->reasons);
    }

    public function has(string $code): bool
    {
        return in_array($code, $this->codes(), true);
    }

    /** @return array<string, mixed>|null */
    public function toJson(): ?array
    {
        return $this->reasons === [] ? null : ['reasons' => $this->reasons];
    }
}
