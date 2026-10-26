<?php

declare(strict_types=1);

namespace Epcc\Prototype;

/** Executable ADR-024 design contract. NOT an application service or persistence adapter. */
final class EffectiveMtoSelection
{
    /**
     * Synthetic snapshots carry a proposed serialized acceptance_order and explicit
     * coverage manifest, neither of which can currently be recovered reliably from
     * accepted_at + rows alone. Paths come from a trusted pinned hierarchy adapter.
     * Evidence here is already admitted by a FUTURE controlled evidence adapter.
     * No string/hash supplied by a caller is considered admission by this class.
     */
    public function select(string $project, array $snapshots, array $paths, array $admittedEvidence = []): array
    {
        $fail = static fn (string $code): array => [
            'ready' => false, 'findings' => [$code], 'selected_batches' => [],
            'selected_rows' => [], 'totals_milli' => null,
        ];
        $ids = $orders = $rowIds = $documents = $revisions = [];
        foreach ($snapshots as $s) {
            foreach (['id', 'project', 'document', 'revision', 'hierarchy'] as $field) {
                if (!isset($s[$field]) || !is_string($s[$field]) || $s[$field] === '') {
                    return $fail('INVALID_SNAPSHOT');
                }
            }
            if ($s['project'] !== $project) {
                return $fail('PROJECT_MISMATCH');
            }
            if (($s['state'] ?? null) !== 'ACCEPTED') {
                return $fail('NOT_ACCEPTED');
            }
            if (!is_int($s['acceptance_order'] ?? null) || $s['acceptance_order'] < 1
                || isset($orders[$s['acceptance_order']]) || isset($ids[$s['id']])) {
                return $fail('AMBIGUOUS_ACCEPTANCE_ORDER');
            }
            if (isset($revisions[$s['revision']])) {
                return $fail('DUPLICATE_REVISION');
            }
            $revisions[$s['revision']] = true;
            $ids[$s['id']] = true;
            $orders[$s['acceptance_order']] = true;
            if (!is_array($s['coverage'] ?? null) || $s['coverage'] === []
                || !is_array($s['rows'] ?? null)) {
                return $fail('MISSING_COVERAGE');
            }
            if ($s['rows'] === []) {
                return $fail('EMPTY_ACCEPTED_BATCH');
            }
            $coverage = $s['coverage'];
            if (count(array_unique($coverage)) !== count($coverage)) {
                return $fail('INVALID_COVERAGE');
            }
            foreach ($coverage as $scope) {
                if (!is_string($scope) || $scope === '') {
                    return $fail('UNRESOLVED_SCOPE');
                }
                $path = $paths[$s['hierarchy']][$scope] ?? null;
                if (!is_array($path) || $path === [] || end($path) !== $scope
                    || count(array_unique($path)) !== count($path)) {
                    return $fail('UNRESOLVED_SCOPE');
                }
                // Every ancestor must have the same pinned prefix: no forged leaf
                // path may make a physical parent/descendant pair look disjoint.
                foreach ($path as $i => $ancestor) {
                    if (($paths[$s['hierarchy']][$ancestor] ?? null) !== array_slice($path, 0, $i + 1)) {
                        return $fail('INVALID_HIERARCHY');
                    }
                }
            }
            foreach ($s['rows'] as $r) {
                if (!is_string($r['id'] ?? null) || $r['id'] === '' || isset($rowIds[$r['id']])) {
                    return $fail('DUPLICATE_OR_MISSING_ROW');
                }
                $rowIds[$r['id']] = true;
                if (!in_array($r['scope'] ?? null, $coverage, true)) {
                    return $fail('ROW_OUTSIDE_COVERAGE');
                }
                if (!is_string($r['material'] ?? null) || $r['material'] === ''
                    || !is_string($r['unit'] ?? null) || $r['unit'] === '') {
                    return $fail('UNRESOLVED_MATERIAL_OR_UNIT');
                }
                if (!is_int($r['quantity_milli'] ?? null) || $r['quantity_milli'] <= 0) {
                    return $fail('INVALID_QUANTITY');
                }
            }
            foreach ($coverage as $scope) {
                if (!in_array($scope, array_column($s['rows'], 'scope'), true)) {
                    return $fail('EMPTY_SCOPE_REQUIRES_DISPOSITION');
                }
            }
            sort($coverage, SORT_STRING);
            $d = $documents[$s['document']] ?? null;
            if ($d !== null && ($d['hierarchy'] !== $s['hierarchy'] || $d['coverage'] !== $coverage)) {
                // A changed scope set/version needs explicit migration/retirement
                // semantics; do not carry omitted scopes forward or erase them.
                return $fail('SCOPE_CHANGE_REQUIRES_EXPLICIT_DISPOSITION');
            }
            if ($d === null || $s['acceptance_order'] > $d['snapshot']['acceptance_order']) {
                $documents[$s['document']] = ['hierarchy' => $s['hierarchy'], 'coverage' => $coverage, 'snapshot' => $s];
            }
        }
        ksort($documents, SORT_STRING);
        $selected = array_column(array_values($documents), 'snapshot');
        for ($i = 0; $i < count($selected); $i++) {
            for ($j = $i + 1; $j < count($selected); $j++) {
                $a = $selected[$i];
                $b = $selected[$j];
                if ($a['hierarchy'] !== $b['hierarchy']) {
                    return $fail('CROSS_VERSION_SCOPE_UNRESOLVED');
                }
                $overlap = false;
                foreach ($a['coverage'] as $sa) {
                    foreach ($b['coverage'] as $sb) {
                        $pa = $paths[$a['hierarchy']][$sa];
                        $pb = $paths[$b['hierarchy']][$sb];
                        if (in_array($sa, $pb, true) || in_array($sb, $pa, true)) {
                            $overlap = true;
                        }
                    }
                }
                if (!$overlap) {
                    continue;
                }
                $admitted = false;
                foreach ($admittedEvidence as $e) {
                    $pair = $e['batch_ids'] ?? [];
                    sort($pair, SORT_STRING);
                    $expected = [$a['id'], $b['id']];
                    sort($expected, SORT_STRING);
                    if (($e['project'] ?? null) === $project && $pair === $expected
                        && is_string($e['controlled_evidence_id'] ?? null) && $e['controlled_evidence_id'] !== ''
                        && is_string($e['reason'] ?? null) && trim($e['reason']) !== '') {
                        $admitted = true;
                    }
                }
                if (!$admitted) {
                    return $fail('OVERLAP_WITHOUT_EXACT_REVISION_EVIDENCE');
                }
            }
        }
        $totals = $rows = $batches = [];
        foreach ($selected as $s) {
            $batches[] = $s['id'];
            foreach ($s['rows'] as $r) {
                // Nested identity/unit keys avoid delimiter collisions and never
                // mix purchase EA with engineering M. No unit conversion here.
                $old = $totals[$r['material']][$r['unit']] ?? 0;
                if ($r['quantity_milli'] > PHP_INT_MAX - $old) {
                    return $fail('QUANTITY_OVERFLOW');
                }
                $totals[$r['material']][$r['unit']] = $old + $r['quantity_milli'];
                $rows[] = $r['id'];
            }
        }
        sort($batches, SORT_STRING);
        sort($rows, SORT_STRING);
        ksort($totals, SORT_STRING);
        foreach ($totals as &$units) {
            ksort($units, SORT_STRING);
        }
        unset($units);
        return ['ready' => true, 'findings' => [], 'selected_batches' => $batches,
            'selected_rows' => $rows, 'totals_milli' => $totals];
    }
}
