<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Original-caller diagnostics only. No retained manifest, package identity or evidence attachment. */
final class ScopeEvidencePreflightService
{
    public function __construct(private readonly TenantContext $tenant, private readonly ScopeStructureDeclarationService $structure,
        private readonly AuthorizationPipeline $authz, private readonly CdeEvidenceIntegrityService $integrity) {}

    private function blocked(): array
    {
        return ['status' => 'BLOCKED', 'all_evidence_bytes_verified' => false, 'evidence' => [],
            'package_persisted' => false, 'package_identity_issued' => false, 'evidence_attached' => false,
            'evidence_relationship_verified' => false, 'evidence_validity_verified' => false, 'retention_verified' => false,
            'package_verified' => false, 'engineering_approval' => false, 'ready_to_admit' => false, 'operationally_authorized' => false,
            'admission_state' => 'UNADMITTED', 'gross' => null, 'findings' => ['EVIDENCE_NOT_CURRENTLY_READABLE_OR_INTACT']];
    }

    /** Always reload eligibility: caller models/loaded relationships are not live authorization. */
    private function cdeDescriptors(Project $p, User $actor, array $refs): ?array
    {
        $u = User::whereKey($actor->id)->where('company_id', $actor->company_id)->first();
        if (! $u || ! $u->is_active || ! ($p->owner_company_id === $u->company_id || DB::table('project_company')->where('project_id', $p->id)->where('company_id', $u->company_id)->exists())) {
            return null;
        }
        $descriptors = [];
        foreach ($refs as $role => $ref) {
            $lookup = DB::table('document_files as f')->join('document_revisions as r', function ($j): void {
                $j->on('r.id', '=', 'f.revision_id')->on('r.document_id', '=', 'f.document_id')->on('r.project_id', '=', 'f.project_id');
            })->join('documents as d', function ($j): void {
                $j->on('d.id', '=', 'r.document_id')->on('d.project_id', '=', 'r.project_id');
            })
                ->where('f.project_id', $p->id)->where('d.id', $ref['document_id'])->where('r.id', $ref['revision_id'])->where('f.id', $ref['file_id']);
            $row = (clone $lookup)->first(['d.discipline']);
            if (! $row || $this->authz->check($u, 'doc.view', $p, ['discipline' => $row->discipline]) !== null) {
                return null;
            }
            $metadata = (clone $lookup)->first(['d.discipline', 'd.number', 'f.disk', 'f.storage_path', 'f.extension', 'f.sha256', 'f.size_bytes', 'r.revision', 'r.cde_state', 'r.suitability', 'r.issued_by', 'r.issued_at']);
            if (! $metadata || $metadata->discipline !== $row->discipline) {
                return null;
            }
            $descriptors[$role] = (array) $metadata;
        }

        return $descriptors;
    }

    public function inspect(Project $project, User $actor, array $input): array
    {
        $rules = ['candidate' => ['required', 'array:declaration_id,expected_declaration_version,expected_declaration_digest,expected_proposal_id,expected_proposal_version,expected_proposal_digest,references'],
            'candidate.declaration_id' => ['required', 'uuid'], 'candidate.expected_proposal_id' => ['required', 'uuid'],
            'candidate.expected_declaration_version' => ['required', 'integer', 'min:1'], 'candidate.expected_proposal_version' => ['required', 'integer', 'min:1'],
            'candidate.expected_declaration_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'candidate.expected_proposal_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'candidate.references' => ['required', 'array:topology,coverage', 'size:2']];
        foreach (['topology', 'coverage'] as $role) {
            $key = 'candidate.references.'.$role;
            $rules[$key] = ['required', 'array:document_id,revision_id,file_id,sha256'];
            foreach (['document_id', 'revision_id', 'file_id'] as $id) {
                $rules[$key.'.'.$id] = ['required', 'uuid'];
            }
            $rules[$key.'.sha256'] = ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'];
        }
        $d = Validator::make(['candidate' => $input], $rules)->validate()['candidate'];
        foreach ($d['references'] as &$ref) {
            foreach (['document_id', 'revision_id', 'file_id'] as $id) {
                $ref[$id] = strtolower($ref[$id]);
            }
        }unset($ref);
        $result = $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): ?array {
            $p = Project::whereKey($project->id)->firstOrFail();
            $view = $this->structure->inspect($p, $actor, $d['declaration_id']);
            $parent = DB::table('scope_proposal_envelopes')->where('id', $view['proposal_id'])->where('project_id', $p->id)->where('company_id', $actor->company_id)->first(['id', 'version', 'digest']);
            if (! $view['is_latest'] || $parent->id !== strtolower($d['expected_proposal_id']) || (int) $parent->version !== (int) $d['expected_proposal_version']
                || $view['version'] !== (int) $d['expected_declaration_version'] || ! hash_equals($parent->digest, $d['expected_proposal_digest']) || ! hash_equals($view['digest'], $d['expected_declaration_digest'])) {
                throw ValidationException::withMessages(['candidate' => __('scope_evidence_preflight.stale')]);
            }
            // All exact dependencies must pass original-user CDE authorization BEFORE any Storage access.
            $before = $this->cdeDescriptors($p, $actor, $d['references']);
            if ($before === null) {
                return null;
            }
            $evidence = [];
            foreach (['topology', 'coverage'] as $role) {
                $ref = $d['references'][$role];
                $check = $this->integrity->verify($p->id, $ref['document_id'], $ref['revision_id'], $ref['file_id'], $ref['sha256']);
                if (! $check['integrity_verified']) {
                    return $this->blocked();
                }
                $evidence[$role] = ['claimed_purpose' => $role, 'source' => $check['source'], 'findings' => $check['findings']];
            }
            // Recheck eligibility and all metadata; not a DB/filesystem lock, retained-object lease or cutoff.
            $fresh = Project::whereKey($p->id)->firstOrFail();
            $descriptors = $this->cdeDescriptors($fresh, $actor, $d['references']);
            if ($descriptors === null) {
                return null;
            }
            $after = $this->structure->inspect($fresh, $actor, $view['id']);
            if (! $after['is_latest'] || $after['digest'] !== $view['digest'] || $descriptors !== $before) {
                return $this->blocked();
            }

            return array_replace($this->blocked(), ['status' => 'BYTES_CHECKED_UNRETAINED', 'all_evidence_bytes_verified' => true, 'evidence' => $evidence,
                'proposal_id' => $parent->id, 'proposal_version' => (int) $parent->version, 'proposal_digest' => $parent->digest,
                'declaration_id' => $view['id'], 'declaration_version' => $view['version'], 'declaration_digest' => $view['digest'],
                'findings' => ['NO_CONTROLLED_PACKAGE_IDENTITY', 'EVIDENCE_PURPOSE_RELATIONSHIP_UNVERIFIED', 'RETENTION_AND_CURRENT_VALIDITY_NOT_ESTABLISHED', 'CHECKS_NOT_ATOMIC']]);
        }, $project->id);
        // When outermost, commit the normal pipeline denial audit before opaque refusal.
        // An enclosing caller transaction still owns audit durability; there are no domain writes here.
        abort_if($result === null, 404);

        return $result;
    }
}
