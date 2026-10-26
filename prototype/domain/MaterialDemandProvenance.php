<?php

declare(strict_types=1);

namespace Epcc\Prototype;

/** Offline synthetic contract only: no DB, source admission, permission or allocation writer. */
final class MaterialDemandProvenance
{
    private const MAX_MILLI = 999999999999999999;

    public function assess(array $basis, string $currentVersion, array $mr, array $ledger, array $proposal, bool $ledgerComplete): array
    {
        $fail = static fn (string $code): array => ['status' => 'UNRESOLVED', 'findings' => [$code],
            'operationally_authorized' => false, 'remaining_milli_by_row' => null, 'provenance' => []];
        if (($basis['ready'] ?? null) !== true || !self::id($basis['project'] ?? null)
            || !self::id($basis['version'] ?? null) || !self::id($currentVersion)) {
            return $fail('BASIS_UNRESOLVED');
        }
        if ($basis['version'] !== $currentVersion) {
            return $fail('BASIS_STALE');
        }
        if (($mr['project'] ?? null) !== $basis['project'] || !self::id($mr['id'] ?? null) || !self::id($mr['version'] ?? null)) {
            return $fail('MR_PROJECT_OR_VERSION_UNRESOLVED');
        }
        if (!$ledgerComplete) {
            return $fail('COMMITMENTS_NOT_RECONCILED');
        }
        if (!is_array($basis['rows'] ?? null) || $basis['rows'] === [] || !is_array($mr['lines'] ?? null) || $mr['lines'] === []) {
            return $fail('EMPTY_OR_MISSING_MANIFEST');
        }
        $rows = $lines = $used = $mapped = [];
        foreach ($basis['rows'] as $r) {
            if (!is_array($r)) {
                return $fail('INVALID_SOURCE_ROW');
            }
            foreach (['id', 'batch', 'revision', 'scope', 'hierarchy', 'material', 'unit'] as $key) {
                if (!self::id($r[$key] ?? null)) {
                    return $fail('SOURCE_IDENTITY_UNRESOLVED');
                }
            }
            if (($r['project'] ?? null) !== $basis['project'] || !self::quantity($r['quantity_milli'] ?? null) || isset($rows[$r['id']])) {
                return $fail('INVALID_OR_DUPLICATE_SOURCE_ROW');
            }
            $rows[$r['id']] = $r;
            $used[$r['id']] = 0;
        }
        foreach ($mr['lines'] as $l) {
            if (!is_array($l)) {
                return $fail('INVALID_MR_LINE');
            }
            foreach (['id', 'material', 'unit', 'scope'] as $key) {
                if (!self::id($l[$key] ?? null)) {
                    return $fail('MR_IDENTITY_UNRESOLVED');
                }
            }
            if (!self::quantity($l['quantity_milli'] ?? null) || isset($lines[$l['id']])) {
                return $fail('INVALID_OR_DUPLICATE_MR_LINE');
            }
            $lines[$l['id']] = $l;
            $mapped[$l['id']] = 0;
        }
        $ids = $proof = [];
        foreach (['ledger' => $ledger, 'proposal' => $proposal] as $kind => $entries) {
            foreach ($entries as $e) {
                if (!is_array($e) || !self::id($e['id'] ?? null) || isset($ids[$e['id']])) {
                    return $fail('INVALID_OR_DUPLICATE_ENTRY');
                }
                $ids[$e['id']] = true;
                if (($e['project'] ?? null) !== $basis['project'] || ($e['basis_version'] ?? null) !== $basis['version']) {
                    return $fail('ENTRY_PROJECT_OR_BASIS_MISMATCH');
                }
                $r = $rows[$e['row_id'] ?? ''] ?? null;
                if ($r === null || !self::quantity($e['quantity_milli'] ?? null)) {
                    return $fail('SOURCE_NOT_SELECTED_OR_QUANTITY_INVALID');
                }
                if ($kind === 'ledger') {
                    if (($e['state'] ?? null) !== 'COMMITTED') {
                        return $fail('LEDGER_STATE_REQUIRES_RECONCILIATION');
                    }
                } else {
                    $l = $lines[$e['mr_line_id'] ?? ''] ?? null;
                    if ($l === null || ($e['mr_id'] ?? null) !== $mr['id'] || ($e['mr_version'] ?? null) !== $mr['version']) {
                        return $fail('MR_LINE_OR_VERSION_MISMATCH');
                    }
                    if ($l['material'] !== $r['material'] || $l['unit'] !== $r['unit'] || $l['scope'] !== $r['scope']) {
                        return $fail('EXACT_IDENTITY_UNIT_SCOPE_MISMATCH');
                    }
                    if ($e['quantity_milli'] > $l['quantity_milli'] - $mapped[$l['id']]) {
                        return $fail('MR_LINE_OVERMAPPED');
                    }
                    $mapped[$l['id']] += $e['quantity_milli'];
                    $proof[] = ['entry_id' => $e['id'], 'mr_line_id' => $l['id'], 'row_id' => $r['id'],
                        'batch' => $r['batch'], 'revision' => $r['revision'], 'hierarchy' => $r['hierarchy'],
                        'scope' => $r['scope'], 'material' => $r['material'], 'unit' => $r['unit'],
                        'quantity_milli' => $e['quantity_milli']];
                }
                if ($e['quantity_milli'] > $r['quantity_milli'] - $used[$r['id']]) {
                    return $fail('DEMAND_ROW_OVERCOMMITTED');
                }
                $used[$r['id']] += $e['quantity_milli'];
            }
        }
        foreach ($lines as $id => $l) {
            if ($mapped[$id] !== $l['quantity_milli']) {
                return $fail('MR_LINE_NOT_FULLY_EXPLAINED');
            }
        }
        $remaining = [];
        foreach ($rows as $id => $r) {
            $remaining[$id] = $r['quantity_milli'] - $used[$id];
        }
        ksort($remaining, SORT_STRING);
        usort($proof, static fn ($a, $b) => strcmp($a['entry_id'], $b['entry_id']));

        return ['status' => 'CONSISTENT_PROPOSAL', 'findings' => [], 'operationally_authorized' => false,
            'basis_version' => $basis['version'], 'mr_version' => $mr['version'],
            'remaining_milli_by_row' => $remaining, 'provenance' => $proof];
    }

    private static function id(mixed $v): bool
    {
        return is_string($v) && trim($v) !== '';
    }

    private static function quantity(mixed $v): bool
    {
        return is_int($v) && $v > 0 && $v <= self::MAX_MILLI;
    }
}
