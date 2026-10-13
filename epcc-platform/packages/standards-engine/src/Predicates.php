<?php

declare(strict_types=1);

namespace Epcc\StandardsEngine;

/**
 * The predicate registry.
 *
 * This is the ONLY place where a `check` string from a standards pack is
 * turned into a decision about a fact set. Every predicate is a pure
 * function of (facts, argument) -> bool. No I/O, no framework, no clock.
 *
 * Purity is what makes the conformance corpus possible: the same inputs
 * must produce the same verdict here and in every other implementation
 * (see conformance/README.md).
 */
final class Predicates
{
    /**
     * @return array<string, callable(array<string,mixed>, ?string): bool>
     */
    public static function registry(): array
    {
        return [
            // ---- Iranian payment certificate (صورت‌وضعیت) ----
            'measurementSheetsAttached' => static fn (array $f, ?string $a): bool =>
                count(self::arr($f, 'measurementSheets')) > 0,

            'progressVerifiedByConsultant' => static fn (array $f, ?string $a): bool =>
                in_array('CONSULTANT', self::arr($f, 'verifiedBy'), true),

            'deductionsCalculated' => static function (array $f, ?string $a): bool {
                $d = self::map($f, 'deductions');
                foreach (['retention', 'advanceRecovery', 'priceAdjustment', 'tax'] as $k) {
                    if (! array_key_exists($k, $d) || $d[$k] === null) {
                        return false;
                    }
                }
                return true;
            },

            'budgetWithinAuthority' => static function (array $f, ?string $a): bool {
                $amount = self::num($f, 'netAmount');
                $limit  = self::num($f, 'approverAuthorityLimit');
                return $amount <= $limit;
            },

            // ---- Acceptance / تحویل موقت ----
            'noOpenPunchOfCategory' => static function (array $f, ?string $a): bool {
                foreach (self::arr($f, 'punchItems') as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $open = ($item['status'] ?? '') !== 'CLOSED';
                    if ($open && ($item['category'] ?? null) === $a) {
                        return false;
                    }
                }
                return true;
            },

            'asBuiltSubmitted' => static fn (array $f, ?string $a): bool =>
                (self::map($f, 'asBuilt')['status'] ?? null) === 'ACCEPTED',

            'allItrsSigned' => static function (array $f, ?string $a): bool {
                $itrs = self::arr($f, 'itrs');
                if ($itrs === []) {
                    return false; // vacuous truth is dangerous on a release gate
                }
                foreach ($itrs as $itr) {
                    if (! is_array($itr) || ($itr['signed'] ?? false) !== true) {
                        return false;
                    }
                }
                return true;
            },

            // ---- AWP (retained so the engine stays generic - ADR-002 seam) ----
            'allHardConstraintsCleared' => static function (array $f, ?string $a): bool {
                foreach (self::arr($f, 'constraints') as $c) {
                    if (! is_array($c)) {
                        continue;
                    }
                    if (($c['hard'] ?? false) === true && ($c['cleared'] ?? false) !== true) {
                        return false;
                    }
                }
                return true;
            },

            'linkedDrawingsAtStatus' => static function (array $f, ?string $a): bool {
                $drawings = self::arr($f, 'drawings');
                if ($drawings === []) {
                    return false;
                }
                foreach ($drawings as $d) {
                    if (! is_array($d) || ($d['status'] ?? null) !== $a) {
                        return false;
                    }
                }
                return true;
            },

            'materialAvailability' => static fn (array $f, ?string $a): bool =>
                self::num($f, 'materialAvailabilityPct') >= (float) ($a ?? '100'),

            'permitValidForWindow' => static fn (array $f, ?string $a): bool =>
                (self::map($f, 'permit')['validForWindow'] ?? false) === true,

            'iwpSizeWithinLimits' => static function (array $f, ?string $a): bool {
                $hours = self::num($f, 'estimatedHours');
                $limit = self::num($f, 'maxHours');
                return $limit > 0.0 && $hours <= $limit;
            },

            'allEwpDeliverablesIssued' => static function (array $f, ?string $a): bool {
                $items = self::arr($f, 'ewpDeliverables');
                if ($items === []) {
                    return false;
                }
                foreach ($items as $d) {
                    if (! is_array($d) || ($d['issued'] ?? false) !== true) {
                        return false;
                    }
                }
                return true;
            },

            'pwpMaterialsDelivered' => static fn (array $f, ?string $a): bool =>
                self::num($f, 'pwpMaterialsDeliveredPct') >= 100.0,
        ];
    }

    /** @param array<string,mixed> $f @return array<int,mixed> */
    private static function arr(array $f, string $key): array
    {
        $v = $f[$key] ?? [];
        return is_array($v) ? array_values($v) : [];
    }

    /** @param array<string,mixed> $f @return array<string,mixed> */
    private static function map(array $f, string $key): array
    {
        $v = $f[$key] ?? [];
        return is_array($v) ? $v : [];
    }

    /** @param array<string,mixed> $f */
    private static function num(array $f, string $key): float
    {
        $v = $f[$key] ?? 0;
        return is_numeric($v) ? (float) $v : 0.0;
    }
}
