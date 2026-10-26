<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Exact content-bound independent opinions; no evidence/engineering approval or admission capability. */
final class ScopeStructureReviewService
{
    public function __construct(private readonly TenantContext $tenant, private readonly ScopeStructureDeclarationService $structure,
        private readonly ScopeAdmissionAuthorityService $authority, private readonly ProjectArchiveService $archives) {}

    private function flags(): array
    {
        return ['content_binding_verified' => true, 'evidence_verified' => false, 'engineering_approval' => false,
            'ready_to_admit' => false, 'package_verified' => false, 'operationally_authorized' => false, 'admission_state' => 'UNADMITTED', 'gross' => null];
    }

    public function review(Project $project, User $actor, array $input): array
    {
        $d = Validator::make(['review' => $input], [
            'review' => ['required', 'array:declaration_id,expected_declaration_version,expected_declaration_digest,expected_proposal_id,expected_proposal_version,expected_proposal_digest,outcome,reason'],
            'review.declaration_id' => ['required', 'uuid'], 'review.expected_proposal_id' => ['required', 'uuid'],
            'review.expected_declaration_version' => ['required', 'integer', 'min:1'], 'review.expected_proposal_version' => ['required', 'integer', 'min:1'],
            'review.expected_declaration_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'review.expected_proposal_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'review.outcome' => ['required', Rule::in(['COMMENTED', 'CHANGES_REQUESTED'])],
            'review.reason' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
        ])->validate()['review'];

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): array {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->archives->assertWritable($p);
            $view = $this->structure->inspect($p, $actor, $d['declaration_id']);
            $e = DB::table('scope_proposal_envelopes')->where('id', $view['proposal_id'])->where('project_id', $p->id)->where('company_id', $actor->company_id)->first();
            $source = array_intersect_key((array) $e, array_flip(['batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id']));
            $this->authority->preflight($p, $actor, $source, 'review', $e->proposer_id, $actor->id);
            if (! $view['is_latest'] || strtolower($d['expected_proposal_id']) !== $e->id
                || (int) $d['expected_proposal_version'] !== (int) $e->version || (int) $d['expected_declaration_version'] !== $view['version']
                || ! hash_equals($e->digest, $d['expected_proposal_digest']) || ! hash_equals($view['digest'], $d['expected_declaration_digest'])
                || DB::table('scope_structure_reviews')->where('declaration_id', $view['id'])->where('reviewer_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['review' => __('scope_structure_reviews.stale')]);
            }
            $id = (string) Str::uuid();
            DB::table('scope_structure_reviews')->insert(['id' => $id, 'project_id' => $p->id, 'company_id' => $actor->company_id,
                'declaration_id' => $view['id'], 'declaration_version' => $view['version'], 'declaration_digest' => $view['digest'],
                'proposal_id' => $e->id, 'proposal_version' => $e->version, 'proposal_digest' => $e->digest, 'reviewer_id' => $actor->id, 'outcome' => $d['outcome'], 'reason' => $d['reason']]);
            $this->structure->inspect($p, $actor, $view['id']);
            $this->authority->preflight($p, $actor, $source, 'review', $e->proposer_id, $actor->id);
            $saved = DB::table('scope_structure_reviews')->where('id', $id)->first();

            return ['id' => $id, 'digest' => $saved->digest, 'proposal_id' => $e->id, 'proposal_version' => (int) $e->version, 'proposal_digest' => $e->digest,
                'declaration_id' => $view['id'], 'declaration_version' => $view['version'], 'declaration_digest' => $view['digest'], 'outcome' => $saved->outcome, 'state' => $saved->state] + $this->flags();
        }, $project->id);
    }

    public function inspect(Project $project, User $actor, string $id): array
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $id): array {
            $m = DB::table('scope_structure_reviews')->where('id', $id)->where('project_id', $project->id)->where('company_id', $actor->company_id)->first(['declaration_id']);
            abort_unless($m, 404);
            $view = $this->structure->inspect($project, $actor, $m->declaration_id);
            $r = DB::table('scope_structure_reviews')->where('id', $id)->where('project_id', $project->id)->where('company_id', $actor->company_id)
                ->select('*')->selectRaw("digest=encode(sha256(convert_to(envelope::text,'UTF8')),'hex')
 AND envelope->>'id'=id::text AND envelope->>'project_id'=project_id::text AND envelope->>'company_id'=company_id::text
 AND envelope->'schema_version'='1'::jsonb AND envelope->>'kind'='UNVERIFIED_STRUCTURE_OPINION'
 AND envelope->>'nomination_policy_id'=nomination_policy_id::text AND envelope->>'reader_policy_id'=reader_policy_id::text
 AND envelope->>'reviewer_id'=reviewer_id::text AND envelope->>'proposal_id'=proposal_id::text
 AND envelope->>'proposal_digest'=proposal_digest AND envelope->'proposal_version'=to_jsonb(proposal_version)
 AND envelope->>'declaration_id'=declaration_id::text AND envelope->>'declaration_digest'=declaration_digest
 AND envelope->'declaration_version'=to_jsonb(declaration_version) AND envelope->>'outcome'=outcome AND envelope->>'reason'=reason AS integrity_ok")->first();
            $e = DB::table('scope_proposal_envelopes')->where('id', $view['proposal_id'])->where('project_id', $project->id)->where('company_id', $actor->company_id)->first(['id', 'proposer_id', 'version', 'digest', 'binding']);
            $content = $r ? json_decode($r->envelope, true, flags: JSON_THROW_ON_ERROR) : [];
            abort_unless($r && $r->integrity_ok && $r->state === 'UNVERIFIED_OPINION' && in_array($r->outcome, ['COMMENTED', 'CHANGES_REQUESTED'], true)
                && ($content['source_binding'] ?? null) === json_decode($e->binding, true, flags: JSON_THROW_ON_ERROR) && $r->reviewer_id !== $e->proposer_id && $r->proposal_id === $e->id && $r->proposal_digest === $e->digest
                && (int) $r->proposal_version === (int) $e->version && $r->declaration_digest === $view['digest'] && (int) $r->declaration_version === $view['version'], 404);
            $this->structure->inspect($project, $actor, $m->declaration_id);

            return array_intersect_key((array) $r, array_flip(['id', 'reviewer_id', 'proposal_id', 'proposal_version', 'proposal_digest', 'declaration_id', 'declaration_version', 'declaration_digest', 'outcome', 'reason', 'created_at', 'digest', 'state', 'nomination_policy_id', 'reader_policy_id']))
                + ['is_latest' => $view['is_latest']] + $this->flags();
        }, $project->id);
    }
}
