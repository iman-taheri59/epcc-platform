<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Same-company read-only exact-ID projection, never a package verification/approval capability. */
final class ScopeProposalEnvelopeReader
{
    public function __construct(private readonly TenantContext $tenant, private readonly ScopeAdmissionAuthorityService $authority,
        private readonly ProjectArchiveService $archives) {}

    private function authorize(Project $p, User $actor, object $metadata): void
    {
        $source = array_intersect_key((array) $metadata, array_flip(['batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id']));
        try {
            $a = $this->authority->diagnostics($p, $actor, $source);
        } catch (HttpException $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }
            abort(404);
        }
        $action = $metadata->proposer_id === $actor->id ? 'propose' : 'review';
        abort_unless($a['status'] === 'UNRESOLVED' && $a['actions'][$action], 404);
        $b = DB::selectOne('SELECT epcc_scope_dossier_binding(?,?,?,?,?,?,?)::text AS binding', [$p->id, $actor->company_id, $actor->id, $metadata->batch_id, $metadata->document_id, $metadata->scope_id, $metadata->hierarchy_revision_id]);
        abort_unless($b?->binding && $b->binding === $metadata->binding, 404);
    }

    public function inspect(Project $project, User $actor, string $proposalId): array
    {
        abort_unless(Str::isUuid($proposalId), 404);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $proposalId): array {
            $p = Project::whereKey($project->id)->firstOrFail();
            // No narrative, review text or full envelope selected before independent per-human gates.
            $metadata = DB::table('scope_proposal_envelopes')->where('id', $proposalId)->where('project_id', $p->id)->where('company_id', $actor->company_id)
                ->first(['id', 'proposer_id', 'batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id', 'binding']);
            abort_unless($metadata, 404);
            $this->authorize($p, $actor, $metadata);
            $e = DB::table('scope_proposal_envelopes')->where('id', $proposalId)->where('project_id', $p->id)->where('company_id', $actor->company_id)
                ->select('*')->selectRaw("digest=encode(sha256(convert_to(envelope::text,'UTF8')),'hex')
 AND envelope->>'id'=id::text AND envelope->>'proposer_id'=proposer_id::text
 AND envelope->>'statement'=statement AND envelope->'source_binding'=binding
 AND envelope->'version'=to_jsonb(version) AND envelope->>'project_id'=project_id::text
 AND envelope->>'company_id'=company_id::text AS integrity_ok")->first();
            abort_unless($e && $e->integrity_ok && $e->state === 'UNVERIFIED_PROPOSAL', 404);
            $reviews = DB::table('scope_proposal_reviews')->where('project_id', $p->id)->where('company_id', $actor->company_id)->where('proposal_id', $e->id)
                ->select('*')->selectRaw("digest=encode(sha256(convert_to(envelope::text,'UTF8')),'hex')
 AND envelope->>'id'=id::text AND envelope->>'proposal_id'=proposal_id::text
 AND envelope->>'proposal_digest'=proposal_digest AND envelope->'proposal_version'=to_jsonb(proposal_version)
 AND envelope->>'reviewer_id'=reviewer_id::text AND envelope->>'outcome'=outcome AND envelope->>'reason'=reason
 AND envelope->>'project_id'=project_id::text AND envelope->>'company_id'=company_id::text AS integrity_ok")
                ->orderBy('created_at')->orderBy('id')->limit(21)->get();
            foreach ($reviews as $r) {
                abort_unless($r->integrity_ok && $r->proposal_digest === $e->digest && (int) $r->proposal_version === (int) $e->version
                    && $r->reviewer_id !== $e->proposer_id && in_array($r->outcome, ['COMMENTED', 'CHANGES_REQUESTED'], true), 404);
            }
            $latest = DB::table('scope_proposal_envelopes')->where(['project_id' => $p->id, 'company_id' => $actor->company_id, 'proposer_id' => $e->proposer_id,
                'batch_id' => $e->batch_id, 'scope_id' => $e->scope_id, 'hierarchy_revision_id' => $e->hierarchy_revision_id])->orderByDesc('version')->first(['id', 'version']);
            // No authorization lease: repeat source/role/nomination checks before returning private content.
            $this->authorize($p, $actor, $metadata);
            $publicProposal = array_intersect_key((array) $e, array_flip(['id', 'version', 'proposer_id', 'statement', 'digest', 'created_at', 'state', 'previous_id', 'nomination_policy_id', 'reader_policy_id']));
            $publicProposal['binding'] = json_decode($e->binding, true, flags: JSON_THROW_ON_ERROR);

            return ['proposal' => $publicProposal, 'reviews' => $reviews->take(20)->map(fn ($r) => array_intersect_key((array) $r, array_flip(['id', 'reviewer_id', 'proposal_version', 'proposal_digest', 'outcome', 'reason', 'created_at', 'digest', 'nomination_policy_id', 'reader_policy_id'])))->all(),
                'reviews_has_more' => $reviews->count() > 20, 'latest_id' => $latest->id, 'latest_version' => (int) $latest->version,
                'is_latest' => $latest->id === $e->id, 'viewer_relation' => $e->proposer_id === $actor->id ? 'original_proposer' : 'independent_review_nominee',
                'writable' => $this->archives->isWritable(Project::whereKey($p->id)->firstOrFail()),
                'ready_to_admit' => false, 'package_verified' => false, 'operationally_authorized' => false, 'gross' => null];
        }, $project->id);
    }
}
