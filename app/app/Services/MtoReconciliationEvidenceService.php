<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AcceptedMtoBatch;
use Illuminate\Support\Str;

/** Internal diagnostic composition, NEVER an evidence-validity/admission decision. */
final class MtoReconciliationEvidenceService
{
    public function __construct(
        private readonly MtoTopologyReconciliationService $topology,
        private readonly CdeEvidenceIntegrityService $integrity,
    ) {}

    public function inspect(AcceptedMtoBatch $batch, array $binding, array $nodes, array $references): array
    {
        $r = ['status' => 'INCOMPLETE', 'diagnostic_complete' => false,
            'all_evidence_bytes_verified' => false, 'evidence_relationship_verified' => false,
            'evidence_validity_verified' => false, 'ready_to_admit' => false,
            'admission_state' => 'UNADMITTED', 'ready' => false, 'gross' => null,
            'comparison' => $this->topology->compare($batch, $binding, $nodes),
            'evidence' => [], 'findings' => []];
        if (! $r['comparison']['comparison_complete']) {
            $r['findings'] = ['SOURCE_OR_COMPARISON_INCOMPLETE'];
            return $r;
        }
        // These are caller-claimed purposes, NOT authenticated file-to-topology
        // or file-to-coverage links. Sharing a file across purposes is no proof.
        $roles = array_keys($references); sort($roles);
        if ($roles !== ['coverage', 'topology']) {
            $r['findings'] = ['INVALID_EVIDENCE_REFERENCE_SHAPE'];
            return $r;
        }
        foreach ($references as $ref) {
            if (! is_array($ref)) {
                $r['findings'] = ['INVALID_EVIDENCE_REFERENCE_SHAPE']; return $r;
            }
            $keys = array_keys($ref); sort($keys);
            if ($keys !== ['document_id', 'file_id', 'project_id', 'revision_id', 'sha256']) {
                $r['findings'] = ['INVALID_EVIDENCE_REFERENCE_SHAPE']; return $r;
            }
            foreach (['document_id', 'file_id', 'project_id', 'revision_id'] as $key) {
                if (! is_string($ref[$key]) || ! Str::isUuid($ref[$key])) {
                    $r['findings'] = ['INVALID_EXACT_REFERENCE']; return $r;
                }
            }
            if (! is_string($ref['sha256']) || preg_match('/^[a-f0-9]{64}$/D', $ref['sha256']) !== 1) {
                $r['findings'] = ['INVALID_EXPECTED_DIGEST']; return $r;
            }
            // Binding was strictly checked against fresh source by compare().
            if (strtolower($ref['project_id']) !== $binding['project_id']) {
                $r['findings'] = ['EVIDENCE_PROJECT_MISMATCH']; return $r;
            }
        }
        $all = true;
        foreach (['topology', 'coverage'] as $role) {
            $ref = $references[$role];
            $check = $this->integrity->verify($binding['project_id'], $ref['document_id'],
                $ref['revision_id'], $ref['file_id'], $ref['sha256']);
            $r['evidence'][$role] = $check;
            $all = $all && $check['integrity_verified'];
        }
        // A best-effort second source read, not a transaction/FS lock or a
        // durable cutoff. Caller snapshot visibility/ABA/later-change limits apply.
        $after = $this->topology->compare($batch, $binding, $nodes);
        if (! $after['comparison_complete'] || $after !== $r['comparison']) {
            $r['comparison'] = $after;
            $r['evidence'] = [];
            $r['findings'] = ['SOURCE_CHANGED_OR_NOT_VISIBLE_AFTER_EVIDENCE_CHECK'];
            return $r;
        }
        $r['all_evidence_bytes_verified'] = $all;
        $r['diagnostic_complete'] = $all;
        $r['status'] = $all ? 'DEPENDENCY_BYTES_CHECKED' : 'INCOMPLETE';
        $r['findings'] = ['EVIDENCE_ROLES_AND_CONTENT_RELATIONSHIP_UNVERIFIED',
            'HISTORICAL_TOPOLOGY_AUTHORITY_NOT_ESTABLISHED', 'COVERAGE_NOT_VERIFIED',
            'RETENTION_AND_REVOCATION_NOT_ESTABLISHED', 'EVIDENCE_CHECKS_NOT_ATOMIC'];
        if (! $all) { $r['findings'][] = 'EVIDENCE_BYTES_INCOMPLETE'; }
        return $r;
    }
}
