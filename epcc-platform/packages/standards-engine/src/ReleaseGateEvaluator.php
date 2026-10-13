<?php

declare(strict_types=1);

namespace Epcc\StandardsEngine;

/**
 * Evaluates a standards-pack release gate against a set of facts.
 *
 * Deliberately framework-free (ADR-003 Annex A §6): no Laravel, no Eloquent,
 * no container, no config, no clock, no I/O. It takes decoded pack JSON and
 * a fact array, and returns a verdict.
 *
 * This is the code the conformance corpus pins (Annex A §4), so that a
 * second implementation in another language can be proven to agree.
 */
final class ReleaseGateEvaluator
{
    /** @var array<string, callable(array<string,mixed>, ?string): bool> */
    private array $predicates;

    /** @param array<string, callable(array<string,mixed>, ?string): bool>|null $predicates */
    public function __construct(?array $predicates = null)
    {
        $this->predicates = $predicates ?? Predicates::registry();
    }

    /**
     * @param array<string,mixed> $pack   Decoded standards pack.
     * @param array<string,mixed> $facts  Facts about the object under evaluation.
     */
    public function evaluate(array $pack, string $objectType, array $facts): Verdict
    {
        $gate = $this->findGate($pack, $objectType);

        if ($gate === null) {
            // No gate defined for this object type: nothing blocks it. It
            // passes, but flag that nothing actually examined it so callers
            // never present this as "passed the checks".
            return new Verdict(true, [], false, false, null, false, false);
        }

        $failures = [];

        foreach ($gate['rules'] ?? [] as $rule) {
            if (! is_array($rule) || ! isset($rule['check']) || ! is_string($rule['check'])) {
                continue;
            }

            [$name, $arg] = $this->parseCheck($rule['check']);

            if (! isset($this->predicates[$name])) {
                // Fail closed. An unknown check on a release gate must never
                // silently pass - that is how a gate quietly stops gating.
                $failures[] = new Failure(
                    $rule['check'],
                    'Unknown check "'.$name.'" - not implemented by this engine.',
                    'بررسی ناشناخته «'.$name.'» - در این موتور پیاده‌سازی نشده است.',
                );
                continue;
            }

            if (($this->predicates[$name])($facts, $arg) !== true) {
                $msg = is_array($rule['message'] ?? null) ? $rule['message'] : [];
                $failures[] = new Failure(
                    $rule['check'],
                    is_string($msg['en'] ?? null) ? $msg['en'] : 'Check failed: '.$rule['check'],
                    is_string($msg['fa'] ?? null) ? $msg['fa'] : 'بررسی ناموفق: '.$rule['check'],
                );
            }
        }

        $waiver         = is_array($gate['waiver'] ?? null) ? $gate['waiver'] : [];
        $waiverAllowed  = ($waiver['allowed'] ?? false) === true;
        $hardBlock      = ($gate['hardBlock'] ?? false) === true;

        return new Verdict(
            passed: $failures === [],
            failures: $failures,
            hardBlock: $hardBlock,
            waiverAllowed: $waiverAllowed,
            waiverCapability: is_string($waiver['approverCapability'] ?? null)
                ? $waiver['approverCapability']
                : null,
            justificationRequired: ($waiver['justificationRequired'] ?? false) === true,
        );
    }

    /**
     * "noOpenPunchOfCategory:A" -> ["noOpenPunchOfCategory", "A"]
     * "asBuiltSubmitted"        -> ["asBuiltSubmitted", null]
     *
     * @return array{0:string,1:?string}
     */
    private function parseCheck(string $check): array
    {
        $pos = strpos($check, ':');
        if ($pos === false) {
            return [$check, null];
        }
        return [substr($check, 0, $pos), substr($check, $pos + 1)];
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<string,mixed>|null
     */
    private function findGate(array $pack, string $objectType): ?array
    {
        foreach ($pack['releaseGates'] ?? [] as $gate) {
            if (is_array($gate) && ($gate['objectType'] ?? null) === $objectType) {
                return $gate;
            }
        }
        return null;
    }
}
