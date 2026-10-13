<?php

declare(strict_types=1);

namespace Epcc\StandardsEngine;

/**
 * The result of evaluating a release gate.
 *
 * Note `passed` and `blocked` are NOT the same thing: a gate can fail without
 * blocking (advisory), and a failing hard gate may still be waivable by a
 * sufficiently privileged approver.
 */
final class Verdict implements \JsonSerializable
{
    /** @param list<Failure> $failures */
    public function __construct(
        public readonly bool $passed,
        public readonly array $failures,
        public readonly bool $hardBlock,
        public readonly bool $waiverAllowed,
        public readonly ?string $waiverCapability = null,
        public readonly bool $justificationRequired = false,
        public readonly bool $gateEvaluated = true,
    ) {
    }

    /**
     * True when this pack defines no gate for the object type.
     *
     * Such an object passes (see MISC-01), but it passed because nothing
     * examined it - not because it satisfied any check. Callers that show a
     * verdict to a human MUST distinguish the two: a green "cleared" badge on
     * an ungated object tells the reader a lie about the assurance behind it.
     */
    public function isUngated(): bool
    {
        return ! $this->gateEvaluated;
    }

    /** Release is prevented unless a waiver is granted. */
    public function isBlocked(): bool
    {
        return ! $this->passed && $this->hardBlock;
    }

    /** Blocked, but a waiver could unblock it. */
    public function isWaivable(): bool
    {
        return $this->isBlocked() && $this->waiverAllowed;
    }

    /** Blocked with no way out - the non-waivable case (e.g. NAZER, صورت‌وضعیت). */
    public function isAbsolutelyBlocked(): bool
    {
        return $this->isBlocked() && ! $this->waiverAllowed;
    }

    /** @return list<string> */
    public function failedChecks(): array
    {
        return array_map(static fn (Failure $f): string => $f->check, $this->failures);
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return [
            'passed'                => $this->passed,
            'gateEvaluated'         => $this->gateEvaluated,
            'ungated'               => $this->isUngated(),
            'hardBlock'             => $this->hardBlock,
            'blocked'               => $this->isBlocked(),
            'waiverAllowed'         => $this->waiverAllowed,
            'waiverCapability'      => $this->waiverCapability,
            'justificationRequired' => $this->justificationRequired,
            'failures'              => $this->failures,
        ];
    }
}
