<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcceptedMtoBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Read-only comparison of frozen observed edges with an UNTRUSTED proposal. */
final class MtoTopologyReconciliationService
{
    public function compare(AcceptedMtoBatch $batch, array $binding, array $nodes): array
    {
        $r = ['status' => 'INCOMPLETE', 'comparison_complete' => false, 'ready_to_admit' => false,
            'admission_state' => 'UNADMITTED', 'ready' => false, 'gross' => null,
            'observation_id' => null, 'differences' => [], 'findings' => []];
        $id = $batch->getKey();
        if (! is_string($id) || ! Str::isUuid($id)) { $r['findings'] = ['SOURCE_NOT_VISIBLE']; return $r; }
        // Single statement snapshot for source tuple, frozen JSON and current
        // row-set digest. No mutable hierarchy lookup or tenant context switch.
        $s = DB::table('accepted_mto_batches as b')
            ->leftJoin('mto_scope_observations as o', function ($j): void {
                $j->on('o.accepted_mto_batch_id', '=', 'b.id')->on('o.project_id', '=', 'b.project_id')
                    ->on('o.company_id', '=', 'b.company_id')->on('o.mto_document_id', '=', 'b.mto_document_id')
                    ->on('o.mto_revision_id', '=', 'b.mto_revision_id');
            })->where('b.id', $id)->select(['b.id', 'b.project_id', 'b.header_scope_id', 'b.hierarchy_revision_id',
                'o.id as observation_id', 'o.payload', 'o.payload_hash', 'o.source_rows_hash'])
            ->selectRaw("o.payload_hash=encode(sha256(convert_to(o.payload::text,'UTF8')),'hex') AS payload_match,
                o.source_rows_hash=encode(sha256(convert_to(epcc_mto_observed_source_rows(b.id,b.project_id)::text,'UTF8')),'hex') AS rows_match")
            ->first();
        if ($s === null) { $r['findings'] = ['SOURCE_NOT_VISIBLE']; return $r; }
        if ($s->observation_id === null) { $r['findings'] = ['SCOPE_OBSERVATION_MISSING']; return $r; }
        $r['observation_id'] = $s->observation_id;
        $fail = static function (string $code) use ($r): array { $r['findings'] = [$code]; return $r; };
        if (! $s->payload_match) { return $fail('PAYLOAD_DIGEST_MISMATCH'); }
        if (! $s->rows_match) { return $fail('SOURCE_ROWS_DRIFT'); }
        $p = json_decode($s->payload, true, 512, JSON_THROW_ON_ERROR);
        if (($p['schema_version'] ?? null) !== 1) { return $fail('UNSUPPORTED_OBSERVATION_VERSION'); }
        if (! is_string($s->hierarchy_revision_id) || trim($s->hierarchy_revision_id) === '') { return $fail('MISSING_HIERARCHY_PIN'); }
        $expected = ['project_id' => $s->project_id, 'batch_id' => $s->id, 'observation_id' => $s->observation_id,
            'payload_hash' => $s->payload_hash, 'source_rows_hash' => $s->source_rows_hash,
            'hierarchy_revision_id' => $s->hierarchy_revision_id, 'header_scope_id' => $s->header_scope_id];
        $keys = array_keys($binding); $expectedKeys = array_keys($expected); sort($keys); sort($expectedKeys);
        if ($keys !== $expectedKeys) { return $fail('SOURCE_BINDING_MISMATCH'); }
        foreach ($expected as $key => $value) {
            if ($binding[$key] !== $value) { return $fail('SOURCE_BINDING_MISMATCH'); }
        }
        $observed = [['id' => $s->project_id, 'project_id' => $s->project_id, 'kind' => 'PROJECT', 'parent_id' => null]];
        foreach ($p['facilities'] as $f) {
            $observed[] = ['id' => $f['id'], 'project_id' => $f['project_id'], 'kind' => 'FACILITY', 'parent_id' => $s->project_id];
        }
        $areas = array_column($p['areas'], null, 'id');
        foreach ($areas as $a) {
            $observed[] = ['id' => $a['id'], 'project_id' => $a['project_id'], 'kind' => 'AREA', 'parent_id' => $a['facility_id']];
        }
        foreach ($p['subsystems'] as $leaf) {
            if ($leaf['area_id'] !== null && (! isset($areas[$leaf['area_id']])
                || $areas[$leaf['area_id']]['facility_id'] !== $leaf['facility_id'])) { return $fail('OBSERVED_AREA_FACILITY_UNRESOLVED'); }
            $observed[] = ['id' => $leaf['id'], 'project_id' => $leaf['project_id'], 'kind' => 'SUBSYSTEM',
                'parent_id' => $leaf['area_id'] ?? $leaf['facility_id']];
        }
        $og = $this->graph($observed, $s->project_id);
        if ($og === null) { return $fail('OBSERVED_TOPOLOGY_INCOMPLETE'); }
        if ($p['rows'] === []) { return $fail('EMPTY_OBSERVED_ROW_SET'); }
        foreach ($p['rows'] as $row) {
            if (($row['hierarchy_revision_id'] ?? null) !== $s->hierarchy_revision_id) { return $fail('ROW_PIN_MISMATCH'); }
            if (! is_string($row['scope_id'] ?? null) || ($og[$row['scope_id']]['kind'] ?? null) !== 'SUBSYSTEM') {
                return $fail('OBSERVED_ROW_SCOPE_UNRESOLVED');
            }
        }
        // This service checks an ancestry fragment, not proposal coverage,
        // evidence, engineering approval or the full offline envelope.
        $pg = $this->graph($nodes, $s->project_id);
        if ($pg === null) { return $fail('PROPOSED_TOPOLOGY_INVALID'); }
        $ids = array_unique(array_merge(array_keys($og), array_keys($pg))); sort($ids, SORT_STRING);
        foreach ($ids as $nodeId) {
            $before = $og[$nodeId] ?? null; $after = $pg[$nodeId] ?? null;
            if ($before === $after) { continue; }
            $code = $before === null ? 'NOT_OBSERVED_AT_CAPTURE' : ($after === null ? 'NODE_NOT_IN_PROPOSAL' : 'OBSERVED_EDGE_OR_KIND_DIFFERS');
            $r['differences'][] = ['code' => $code, 'node_id' => $nodeId, 'observed' => $before, 'proposed' => $after];
        }
        $r['comparison_complete'] = true;
        $r['status'] = $r['differences'] === [] ? 'MATCHES_OBSERVATION' : 'DIFFERENCES_FOUND';
        $r['findings'] = ['ENGINEERING_AUTHORITY_NOT_ESTABLISHED', 'COVERAGE_NOT_VERIFIED', 'EVIDENCE_VALIDITY_NOT_VERIFIED'];
        return $r;
    }

    /** Bounded fixed-hierarchy structure only; no assertion of authenticity. */
    private function graph(array $nodes, string $project): ?array
    {
        if (! array_is_list($nodes) || $nodes === [] || count($nodes) > 10000) { return null; }
        $g = []; $roots = 0;
        foreach ($nodes as $n) {
            if (! is_array($n)) { return null; }
            $keys = array_keys($n); sort($keys);
            if ($keys !== ['id', 'kind', 'parent_id', 'project_id'] || ! is_string($n['id']) || ! Str::isUuid($n['id'])
                || $n['project_id'] !== $project || ! in_array($n['kind'], ['PROJECT', 'FACILITY', 'AREA', 'SUBSYSTEM'], true)
                || ! ($n['parent_id'] === null || (is_string($n['parent_id']) && Str::isUuid($n['parent_id'])))) { return null; }
            $id = strtolower($n['id']);
            if (isset($g[$id])) { return null; }
            $n['id'] = $id;
            if ($n['parent_id'] !== null) { $n['parent_id'] = strtolower($n['parent_id']); }
            $g[$id] = ['id' => $id, 'project_id' => $project, 'kind' => $n['kind'], 'parent_id' => $n['parent_id']];
            if ($n['kind'] === 'PROJECT') {
                if ($id !== $project || $n['parent_id'] !== null) { return null; }
                $roots++;
            }
        }
        if ($roots !== 1) { return null; }
        foreach ($g as $n) {
            if ($n['kind'] === 'PROJECT') { continue; }
            $parentKind = $g[$n['parent_id'] ?? '']['kind'] ?? null;
            $allowed = match ($n['kind']) { 'FACILITY' => ['PROJECT'], 'AREA' => ['FACILITY'], 'SUBSYSTEM' => ['FACILITY', 'AREA'] };
            // Strict increasing hierarchy levels also exclude every cycle.
            if (! in_array($parentKind, $allowed, true)) { return null; }
        }
        ksort($g, SORT_STRING);
        return $g;
    }
}
