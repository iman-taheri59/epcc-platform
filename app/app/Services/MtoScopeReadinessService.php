<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcceptedMtoBatch;
use App\Models\MtoScopeObservation;
use Illuminate\Support\Facades\DB;

/** Read-only diagnostics. Observed database topology is NOT admitted scope. */
final class MtoScopeReadinessService
{
    public function assess(AcceptedMtoBatch $batch): array
    {
        return DB::transaction(function () use ($batch): array {
            $source = AcceptedMtoBatch::query()->find($batch->getKey());
            $result = ['ready' => false, 'status' => 'UNRESOLVED', 'gross' => null, 'observation_id' => null, 'findings' => []];
            if ($source === null) {
                $result['findings'] = ['SOURCE_NOT_VISIBLE'];
                return $result;
            }
            // Use fresh rows, not caller attributes or a cached loaded relation.
            $o = MtoScopeObservation::query()->where('accepted_mto_batch_id', $source->id)->first();
            if ($o === null) {
                $result['findings'] = ['SCOPE_OBSERVATION_MISSING'];
                return $result;
            }
            $result['observation_id'] = $o->id;
            $p = $o->payload;
            $findings = ['NO_ADMITTED_TOPOLOGY'];
            $check = DB::selectOne(<<<'SQL'
                SELECT source_rows_hash=encode(sha256(convert_to(epcc_mto_observed_source_rows(accepted_mto_batch_id,project_id)::text,'UTF8')),'hex') AS rows_match,
                  payload_hash=encode(sha256(convert_to(payload::text,'UTF8')),'hex') AS payload_match
                FROM mto_scope_observations WHERE id=?
            SQL, [$o->id]);
            if (! $check || ! $check->rows_match) {
                $findings[] = 'SOURCE_ROWS_DRIFT';
            }
            if (! $check || ! $check->payload_match) {
                $findings[] = 'PAYLOAD_DIGEST_MISMATCH';
            }
            if (($p['schema_version'] ?? null) !== 1) {
                $findings[] = 'UNSUPPORTED_OBSERVATION_VERSION';
            }
            $findings[] = empty($p['claimed_hierarchy_revision_id']) ? 'MISSING_HIERARCHY_PIN' : 'HIERARCHY_PIN_NOT_ADMITTED';
            $leaves = array_column($p['subsystems'], null, 'id');
            $areas = array_column($p['areas'], null, 'id');
            $facilities = array_column($p['facilities'], null, 'id');
            if ($p['rows'] === []) {
                $findings[] = 'EMPTY_OBSERVED_ROW_SET';
            }
            foreach ($p['rows'] as $row) {
                if (empty($row['hierarchy_revision_id'])) {
                    $findings[] = 'MISSING_ROW_HIERARCHY_PIN';
                } elseif ($row['hierarchy_revision_id'] !== $p['claimed_hierarchy_revision_id']) {
                    $findings[] = 'ROW_HIERARCHY_PIN_MISMATCH';
                }
                $scope = $row['scope_id'];
                if ($scope === null) {
                    $findings[] = 'MISSING_ROW_SCOPE';
                    continue;
                }
                if (! isset($leaves[$scope])) {
                    $findings[] = 'SCOPE_NOT_VISIBLE_AT_CAPTURE';
                    continue;
                }
                $leaf = $leaves[$scope];
                if ($leaf['facility_id'] === null || ! isset($facilities[$leaf['facility_id']])) {
                    $findings[] = 'FACILITY_NOT_RESOLVED_AT_CAPTURE';
                }
                if ($leaf['area_id'] !== null) {
                    $area = $areas[$leaf['area_id']] ?? null;
                    if ($area === null || $area['facility_id'] !== $leaf['facility_id']) {
                        $findings[] = 'AREA_PARENT_NOT_RESOLVED_AT_CAPTURE';
                    }
                }
                if ($p['header_scope_id'] !== null && $p['header_scope_id'] !== $scope) {
                    $findings[] = 'HEADER_SCOPE_RELATION_UNRESOLVED';
                }
            }
            $result['findings'] = array_values(array_unique($findings));
            sort($result['findings'], SORT_STRING);
            return $result;
        });
    }
}
