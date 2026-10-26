<?php

declare(strict_types=1);

namespace Epcc\Prototype;

use InvalidArgumentException;

/** Offline content version + historical-reference diagnostics. Never signs, admits or transfers demand. */
final class DemandBasisReconciliation
{
    public function version(array $manifest): string
    {
        $this->keys($manifest, ['project', 'selection_version', 'rows']);
        foreach (['project', 'selection_version'] as $key) {
            $this->identity($manifest[$key]);
        }
        if (!is_array($manifest['rows']) || !array_is_list($manifest['rows']) || $manifest['rows'] === []) {
            throw new InvalidArgumentException('EMPTY_OR_INVALID_MANIFEST');
        }
        $seen = [];
        $rows = [];
        foreach ($manifest['rows'] as $row) {
            if (!is_array($row)) {
                throw new InvalidArgumentException('INVALID_ROW');
            }
            $this->keys($row, ['id', 'batch', 'revision', 'hierarchy', 'scope', 'material', 'unit', 'quantity_milli']);
            foreach (['id', 'batch', 'revision', 'hierarchy', 'scope', 'material', 'unit'] as $key) {
                $this->identity($row[$key]);
            }
            if (isset($seen[$row['id']]) || !$this->quantity($row['quantity_milli'])) {
                throw new InvalidArgumentException('DUPLICATE_ROW_OR_INVALID_QUANTITY');
            }
            $seen[$row['id']] = true;
            ksort($row, SORT_STRING);
            $rows[] = $row;
        }
        usort($rows, static fn ($a, $b) => strcmp($a['id'], $b['id']));
        // Domain separator and explicit contract version prevent cross-contract digest reuse.
        return 'demand-basis-v1:'.hash('sha256', json_encode([
            'contract' => 'demand-basis-v1', 'project' => $manifest['project'],
            'selection_version' => $manifest['selection_version'], 'rows' => $rows,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function review(array $old, array $current, string $expectedCurrentVersion, array $commitments, bool $inventoryComplete): array
    {
        $result = ['status' => 'REVIEW_REQUIRED', 'operationally_authorized' => false,
            'remaining_milli' => null, 'transfers' => [], 'findings' => [], 'entries' => []];
        try {
            $oldVersion = $this->version($old);
            $currentVersion = $this->version($current);
        } catch (InvalidArgumentException|\JsonException $e) {
            $result['findings'] = ['INVALID_MANIFEST'];
            return $result;
        }
        if ($old['project'] !== $current['project']) {
            $result['findings'] = ['PROJECT_MISMATCH'];
            return $result;
        }
        if ($expectedCurrentVersion !== $currentVersion) {
            $result['findings'] = ['CURRENT_VERSION_CHANGED'];
            return $result;
        }
        $oldRows = array_column($old['rows'], null, 'id');
        $currentRows = array_column($current['rows'], null, 'id');
        $seen = [];
        $used = [];
        foreach ($commitments as $c) {
            if (!is_array($c) || !is_string($c['id'] ?? null) || trim($c['id']) === '' || isset($seen[$c['id']])
                || ($c['project'] ?? null) !== $old['project'] || ($c['basis_version'] ?? null) !== $oldVersion
                || !is_string($c['row_id'] ?? null) || !isset($oldRows[$c['row_id']]) || !$this->quantity($c['quantity_milli'] ?? null)) {
                $result['findings'] = ['INVALID_OR_UNRECONCILED_HISTORY'];
                return $result;
            }
            $seen[$c['id']] = true;
            $source = $oldRows[$c['row_id']];
            $consumed = $used[$c['row_id']] ?? 0;
            if ($c['quantity_milli'] > $source['quantity_milli'] - $consumed) {
                $result['findings'] = ['HISTORICAL_OVERCOMMITMENT'];
                return $result;
            }
            $used[$c['row_id']] = $consumed + $c['quantity_milli'];
        }
        // Same immutable row ID with changed fields is an integrity defect, not a replacement revision.
        foreach ($oldRows as $id => $row) {
            if (isset($currentRows[$id]) && !$this->sameRow($row, $currentRows[$id])) {
                $result['findings'] = ['IMMUTABLE_SOURCE_IDENTITY_MUTATED'];
                return $result;
            }
        }
        foreach ($commitments as $c) {
            $result['entries'][] = ['commitment_id' => $c['id'], 'original_basis_version' => $oldVersion,
                'original_row_id' => $c['row_id'], 'quantity_milli' => $c['quantity_milli'],
                'disposition' => !isset($currentRows[$c['row_id']]) ? 'SOURCE_NOT_IN_CURRENT_BASIS'
                    : ($oldVersion === $currentVersion ? 'CURRENT_REFERENCE_ONLY' : 'UNCHANGED_ROW_NEW_BASIS_REVIEW')];
        }
        usort($result['entries'], static fn ($a, $b) => strcmp($a['commitment_id'], $b['commitment_id']));
        $result['findings'] = $inventoryComplete ? ['NO_TRANSFER_OR_RELEASE_AUTHORIZED'] : ['INVENTORY_VISIBILITY_OR_RECONCILIATION_INCOMPLETE'];
        if ($commitments === []) {
            $result['findings'][] = 'EMPTY_INPUT_NOT_PROOF_OF_NO_COMMITMENTS';
        }
        return $result + ['old_version' => $oldVersion, 'current_version' => $currentVersion];
    }

    private function sameRow(array $a, array $b): bool
    {
        ksort($a, SORT_STRING);
        ksort($b, SORT_STRING);
        return $a === $b;
    }

    private function keys(array $value, array $keys): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new InvalidArgumentException('UNSUPPORTED_MANIFEST_SHAPE');
        }
    }

    private function identity(mixed $value): void
    {
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('UNRESOLVED_IDENTITY');
        }
    }

    private function quantity(mixed $value): bool
    {
        return is_int($value) && $value > 0 && $value <= 999999999999999999;
    }
}
