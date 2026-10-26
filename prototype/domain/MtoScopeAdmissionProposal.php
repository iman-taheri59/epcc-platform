<?php

declare(strict_types=1);

namespace Epcc\Prototype;

/** Offline structure review only. Neither argument is an authenticated DB export. */
final class MtoScopeAdmissionProposal
{
    private const BINDING = ['project_id', 'batch_id', 'observation_id', 'payload_hash', 'source_rows_hash', 'hierarchy_revision_id', 'header_scope_id'];

    public function assess(array $proposal, array $observed): array
    {
        $errors = [];
        $add = static function (string $code) use (&$errors): void { $errors[] = $code; };
        $text = static fn ($v): bool => is_string($v) && trim($v) !== '';
        $hash = static fn ($v): bool => is_string($v) && preg_match('/^[a-f0-9]{64}$/D', $v) === 1;
        $keys = static function (array $a, array $expected): bool {
            $actual = array_keys($a); sort($actual); sort($expected); return $actual === $expected;
        };
        if (! $keys($proposal, ['schema_version', 'source_binding', 'nodes', 'coverage', 'topology_evidence', 'coverage_evidence'])
            || ($proposal['schema_version'] ?? null) !== 1) {
            $add('PROPOSAL_SCHEMA_INVALID');
        }
        $binding = $proposal['source_binding'] ?? null;
        if (! is_array($binding) || ! $keys($binding, self::BINDING)) {
            $add('SOURCE_BINDING_INVALID');
            $binding = [];
        }
        foreach (self::BINDING as $key) {
            if ($key === 'header_scope_id') {
                if (! array_key_exists($key, $binding) || ! array_key_exists($key, $observed)
                    || $binding[$key] !== $observed[$key]
                    || ! ($binding[$key] === null || $text($binding[$key]))) { $add('HEADER_BINDING_INVALID'); }
                continue;
            }
            if (! $text($binding[$key] ?? null) || ! $text($observed[$key] ?? null)
                || ($binding[$key] ?? null) !== ($observed[$key] ?? null)) {
                $add('SOURCE_BINDING_MISSING_OR_MISMATCHED');
            }
        }
        foreach (['payload_hash', 'source_rows_hash'] as $key) {
            if (! $hash($binding[$key] ?? null)) { $add('SOURCE_DIGEST_INVALID'); }
        }
        $nodes = $proposal['nodes'] ?? null;
        if (! is_array($nodes) || ! array_is_list($nodes) || $nodes === []) {
            $add('TOPOLOGY_REQUIRED'); $nodes = [];
        }
        $graph = []; $roots = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || ! $keys($node, ['id', 'project_id', 'kind', 'parent_id'])
                || ! $text($node['id'] ?? null) || ! is_string($node['kind'] ?? null)
                || ! in_array($node['kind'], ['PROJECT', 'FACILITY', 'AREA', 'SUBSYSTEM'], true)
                || ! (($node['parent_id'] ?? null) === null || $text($node['parent_id']))) {
                $add('NODE_INVALID'); continue;
            }
            $id = $node['id'];
            if (isset($graph[$id])) { $add('DUPLICATE_NODE'); continue; }
            if ($node['project_id'] !== ($binding['project_id'] ?? null)) { $add('FOREIGN_NODE'); }
            $graph[$id] = $node;
            if ($node['kind'] === 'PROJECT') { $roots[] = $id; }
        }
        if (count($roots) !== 1 || ($roots[0] ?? null) !== ($binding['project_id'] ?? null)) { $add('EXACT_PROJECT_ROOT_REQUIRED'); }
        foreach ($graph as $id => $node) {
            $parent = $node['parent_id'];
            if ($node['kind'] === 'PROJECT') {
                if ($parent !== null) { $add('PROJECT_ROOT_HAS_PARENT'); }
                continue;
            }
            if ($parent === null || ! isset($graph[$parent])) { $add('PARENT_NOT_IN_MANIFEST'); continue; }
            $allowed = match ($node['kind']) {
                'FACILITY' => ['PROJECT'], 'AREA' => ['FACILITY'], 'SUBSYSTEM' => ['FACILITY', 'AREA'],
            };
            if (! in_array($graph[$parent]['kind'], $allowed, true)) { $add('PARENT_KIND_INVALID'); }
            $seen = []; $cursor = $id;
            while (isset($graph[$cursor])) {
                if (isset($seen[$cursor])) { $add('TOPOLOGY_CYCLE'); break; }
                $seen[$cursor] = true;
                $next = $graph[$cursor]['parent_id'];
                if ($next === null) { break; }
                $cursor = $next;
            }
        }
        $rows = $observed['rows'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) { $add('OBSERVED_ROWS_REQUIRED'); $rows = []; }
        $sourceRows = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! $text($row['id'] ?? null) || ! $text($row['scope_id'] ?? null)) {
                $add('OBSERVED_ROW_ID_OR_SCOPE_MISSING'); continue;
            }
            if (isset($sourceRows[$row['id']])) { $add('DUPLICATE_OBSERVED_ROW'); }
            $sourceRows[$row['id']] = $row['scope_id'];
            if (($row['hierarchy_revision_id'] ?? null) !== ($binding['hierarchy_revision_id'] ?? null)
                || ! $text($row['hierarchy_revision_id'] ?? null)) { $add('ROW_PIN_MISMATCH_OR_MISSING'); }
        }
        $coverage = $proposal['coverage'] ?? null;
        if (! is_array($coverage) || ! array_is_list($coverage) || $coverage === []) { $add('EXPLICIT_COVERAGE_REQUIRED'); $coverage = []; }
        $scopes = []; $coveredRows = [];
        foreach ($coverage as $entry) {
            if (! is_array($entry) || ! $keys($entry, ['scope_id', 'row_ids']) || ! $text($entry['scope_id'] ?? null)
                || ! is_array($entry['row_ids'] ?? null) || ! array_is_list($entry['row_ids'])) { $add('COVERAGE_ENTRY_INVALID'); continue; }
            $scope = $entry['scope_id'];
            if (isset($scopes[$scope])) { $add('DUPLICATE_COVERED_SCOPE'); }
            $scopes[$scope] = true;
            // Accepted rule: a row may refine its header to a valid descendant.
            // This proves only ancestry in the supplied same-version proposal,
            // never historical authenticity or admission authority.
            $header = $binding['header_scope_id'] ?? null;
            if ($header !== null) {
                $inside = false; $seen = []; $cursor = $scope;
                if (is_string($header) && isset($graph[$header])) {
                    while (isset($graph[$cursor]) && ! isset($seen[$cursor])) {
                        $seen[$cursor] = true;
                        if ($cursor === $header) { $inside = true; break; }
                        $parent = $graph[$cursor]['parent_id'];
                        if ($parent === null) { break; }
                        $cursor = $parent;
                    }
                }
                if (! $inside) { $add('HEADER_BOUNDARY_NOT_PROVEN'); }
            }
            if (($graph[$scope]['kind'] ?? null) !== 'SUBSYSTEM') { $add('COVERAGE_MUST_REFERENCE_EXPLICIT_LEAF'); }
            // No implicit retirement/approved-zero interpretation for an empty scope.
            if ($entry['row_ids'] === []) { $add('EMPTY_SCOPE_REQUIRES_SEPARATE_DISPOSITION'); }
            foreach ($entry['row_ids'] as $id) {
                if (! $text($id)) { $add('COVERED_ROW_ID_INVALID'); continue; }
                if (isset($coveredRows[$id])) { $add('ROW_COVERED_MORE_THAN_ONCE'); }
                $coveredRows[$id] = true;
                if (! isset($sourceRows[$id])) { $add('UNKNOWN_COVERED_ROW'); }
                elseif ($sourceRows[$id] !== $scope) { $add('ROW_OUTSIDE_DECLARED_SCOPE'); }
            }
        }
        if (array_diff_key($sourceRows, $coveredRows) !== []) { $add('OBSERVED_ROWS_NOT_FULLY_COVERED'); }
        foreach (['topology_evidence', 'coverage_evidence'] as $key) {
            $e = $proposal[$key] ?? null;
            if (! is_array($e) || ! $keys($e, ['record_id', 'issuer', 'number', 'revision', 'date', 'retained_uri', 'file_sha256'])) {
                $add('CONTROLLED_RECORD_DECLARATION_REQUIRED'); continue;
            }
            foreach (['record_id', 'issuer', 'number', 'revision', 'retained_uri'] as $field) {
                if (! $text($e[$field] ?? null)) { $add('CONTROLLED_RECORD_DECLARATION_INCOMPLETE'); }
            }
            $date = $e['date'] ?? null;
            try {
                $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            } catch (\ValueError) { $parsed = false; }
            if (! $parsed || $parsed->format('Y-m-d') !== $date || ! $hash($e['file_sha256'] ?? null)) {
                $add('CONTROLLED_RECORD_DATE_OR_DIGEST_INVALID');
            }
        }
        $errors = array_values(array_unique($errors)); sort($errors, SORT_STRING);
        return ['status' => $errors === [] ? 'STRUCTURALLY_REVIEWABLE_PROPOSAL' : 'BLOCKED',
            'structurally_reviewable' => $errors === [], 'ready_to_admit' => false, 'admission_state' => 'UNADMITTED',
            'ready' => false, 'gross' => null, 'writes_performed' => 0, 'findings' => $errors,
            'unverified_requirements' => ['AUTHENTICATED_SOURCE_EXPORT', 'CONTROLLED_EVIDENCE_CONTENT_AND_RETENTION',
                'OBSERVATION_TO_TOPOLOGY_RECONCILIATION', 'HISTORICAL_TOPOLOGY_AUTHORITY', 'COVERAGE_COMPLETENESS_AUTHORITY', 'APPROVED_ACTOR_POLICY',
                'REVOCATION_AND_CORRECTION_LIFECYCLE', 'TRANSACTIONAL_REVALIDATION_AND_REPLAY_GUARDS']];
    }
}
