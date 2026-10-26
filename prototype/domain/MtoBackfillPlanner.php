<?php

declare(strict_types=1);

namespace Epcc\Prototype;

/** Offline review planner only: no database, inferred chronology or authority admission. */
final class MtoBackfillPlanner
{
    public function fingerprint(array $batches): string
    {
        $copy = $batches;
        foreach ($copy as &$b) {
            if (is_array($b['rows'] ?? null)) {
                usort($b['rows'], static fn ($a, $c) => strcmp($a['id'] ?? '', $c['id'] ?? ''));
            }
            if (is_array($b['coverage'] ?? null)) {
                sort($b['coverage'], SORT_STRING);
            }
        }
        unset($b);
        usort($copy, static fn ($a, $b) => strcmp($a['id'] ?? '', $b['id'] ?? ''));
        return hash('sha256', json_encode($this->canonical($copy), JSON_THROW_ON_ERROR));
    }

    public function assess(string $project, array $batches, array $review = []): array
    {
        $hash = $this->fingerprint($batches);
        $findings = $notices = $documents = $seen = $revisions = [];
        $add = static function (string $code, string $subject) use (&$findings): void {
            $findings[] = ['code' => $code, 'subject' => $subject];
        };
        foreach ($batches as $b) {
            $id = $b['id'] ?? '';
            if (!is_string($id) || $id === '' || isset($seen[$id])) {
                $add('INVALID_OR_DUPLICATE_BATCH', is_string($id) ? $id : '');
                continue;
            }
            $seen[$id] = true;
            if (($b['project'] ?? null) !== $project) {
                $add('PROJECT_MISMATCH', $id);
                continue;
            }
            if (($b['state'] ?? null) !== 'ACCEPTED') {
                $add('NOT_ACCEPTED', $id);
            }
            $doc = $b['document'] ?? '';
            $rev = $b['revision'] ?? '';
            if (!is_string($doc) || $doc === '' || !is_string($rev) || $rev === '') {
                $add('MISSING_SOURCE_IDENTITY', $id);
                continue;
            }
            if (isset($revisions[$rev])) {
                $add('DUPLICATE_ACCEPTED_REVISION', $id);
            }
            $revisions[$rev] = true;
            $documents[$doc][] = $b;
            $time = $b['accepted_at_utc'] ?? '';
            $parsed = is_string($time) ? \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $time, new \DateTimeZone('UTC')) : false;
            if (!$parsed || $parsed->format('Y-m-d\TH:i:s.u\Z') !== $time) {
                $add('INVALID_ACCEPTANCE_TIMESTAMP', $id);
            }
            foreach (['hierarchy', 'scope_manifest_ref', 'topology_ref'] as $field) {
                if (!is_string($b[$field] ?? null) || trim($b[$field]) === '') {
                    $add('MISSING_'.strtoupper($field), $id);
                }
            }
            $coverage = $b['coverage'] ?? [];
            if (!is_array($coverage) || $coverage === [] || count(array_filter($coverage, 'is_string')) !== count($coverage)
                || in_array('', $coverage, true) || count(array_unique($coverage)) !== count($coverage)) {
                $add('UNRESOLVED_COVERAGE', $id);
                $coverage = [];
            }
            if (!is_array($b['rows'] ?? null) || $b['rows'] === []) {
                $add('EMPTY_OR_MISSING_ACCEPTED_ROWS', $id);
            }
            foreach (is_array($b['rows'] ?? null) ? $b['rows'] : [] as $row) {
                if (!is_string($row['scope'] ?? null) || !in_array($row['scope'], $coverage, true)) {
                    $add('UNRESOLVED_ROW_SCOPE', $id);
                }
            }
        }
        $bound = ($review['project'] ?? null) === $project && ($review['inventory_sha256'] ?? null) === $hash;
        if ($review !== [] && !$bound) {
            $add('STALE_OR_FOREIGN_REVIEW_PACKAGE', $project);
        }
        $chains = [];
        ksort($documents, SORT_STRING);
        foreach ($documents as $doc => $items) {
            $ids = array_column($items, 'id');
            sort($ids, SORT_STRING);
            $times = array_column($items, 'accepted_at_utc');
            if (count(array_unique($times)) !== count($times)) {
                $notices[] = ['code' => 'TIMESTAMP_TIE_NO_AUTOMATIC_WINNER', 'subject' => $doc];
            }
            $scopeSets = [];
            foreach ($items as $item) {
                $scopes = is_array($item['coverage'] ?? null) ? $item['coverage'] : [];
                sort($scopes, SORT_STRING);
                $scopeSets[] = json_encode([$item['hierarchy'] ?? null, $scopes]);
            }
            if (count(array_unique($scopeSets)) > 1) {
                $add('SCOPE_CHANGE_REQUIRES_DISPOSITION', $doc);
            }
            $d = $bound ? ($review['documents'][$doc] ?? null) : null;
            if (!is_array($d)) {
                $add('EXPLICIT_DOCUMENT_ORDER_REVIEW_REQUIRED', $doc);
                continue;
            }
            $order = $d['batch_order'] ?? [];
            $sorted = is_array($order) ? $order : [];
            sort($sorted, SORT_STRING);
            if ($sorted !== $ids) {
                $add('ORDER_MUST_COVER_EXACT_BATCH_SET_ONCE', $doc);
                continue;
            }
            if (!is_string($d['reason'] ?? null) || trim($d['reason']) === ''
                || !is_string($d['controlled_evidence_ref'] ?? null) || trim($d['controlled_evidence_ref']) === '') {
                $add('ORDER_EVIDENCE_REQUIRED', $doc);
                continue;
            }
            $chains[$doc] = ['batch_order' => $order, 'controlled_evidence_ref' => $d['controlled_evidence_ref'], 'reason' => $d['reason']];
        }
        if ($bound) {
            foreach (array_keys($review['documents'] ?? []) as $doc) {
                if (!isset($documents[$doc])) {
                    $add('UNKNOWN_DOCUMENT_IN_REVIEW', (string) $doc);
                }
            }
        }
        usort($findings, static fn ($a, $b) => [$a['subject'], $a['code']] <=> [$b['subject'], $b['code']]);
        $ready = $findings === [];
        $payload = ['project' => $project, 'inventory_sha256' => $hash, 'document_chains' => $ready ? $chains : []];
        return $payload + ['status' => $ready ? 'CANDIDATE_FOR_CONTROLLED_REVIEW' : 'BLOCKED',
            'ready_to_apply' => false, 'writes_performed' => 0, 'findings' => $findings,
            'notices' => $notices, 'candidate_sha256' => $ready ? hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR)) : null,
            'limitations' => 'No evidence admission, overlap approval, RLS, signatures or database preflight. No synthetic historical global ordinal.'];
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map(fn ($v) => $this->canonical($v), $value);
    }
}
