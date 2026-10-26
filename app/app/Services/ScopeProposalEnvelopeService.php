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

/** Internal, same-company narrative proposal/review storage; NO engineering approval or admission. */
final class ScopeProposalEnvelopeService
{
    public function __construct(private readonly TenantContext $tenant, private readonly ScopeAdmissionAuthorityService $authority,
        private readonly ProjectArchiveService $archives) {}

    private function source(object $e): array
    {
        return array_intersect_key((array) $e, array_flip(['batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id']));
    }

    private function binding(Project $p, User $actor, array $s): string
    {
        $r = DB::selectOne('SELECT epcc_scope_dossier_binding(?,?,?,?,?,?,?)::text AS binding', [$p->id, $actor->company_id, $actor->id, $s['batch_id'], $s['document_id'], $s['scope_id'], $s['hierarchy_revision_id']]);
        abort_unless($r?->binding, 403);

        return $r->binding;
    }

    private function result(object $e): array
    {
        return ['id' => $e->id, 'digest' => $e->digest, 'version' => (int) ($e->version ?? $e->proposal_version),
            'ready_to_admit' => false, 'package_verified' => false, 'operationally_authorized' => false, 'admission_state' => 'UNADMITTED', 'gross' => null];
    }

    public function submit(Project $project, User $actor, array $input): array
    {
        $d = Validator::make(['proposal' => $input], ['proposal' => ['required', 'array:batch_id,document_id,scope_id,hierarchy_revision_id,expected_version,statement'],
            'proposal.batch_id' => ['required', 'uuid'], 'proposal.document_id' => ['required', 'uuid'], 'proposal.scope_id' => ['required', 'uuid'],
            'proposal.hierarchy_revision_id' => ['required', 'string', 'max:96', 'regex:/\S/u'], 'proposal.expected_version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'proposal.statement' => ['required', 'string', 'max:4000', 'regex:/\S/u']])->validate()['proposal'];

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): array {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->archives->assertWritable($p);
            $source = array_intersect_key($d, array_flip(['batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id']));
            $this->authority->preflight($p, $actor, $source, 'propose');
            $this->binding($p, $actor, $source);
            $key = $source + ['project_id' => $p->id, 'company_id' => $actor->company_id, 'proposer_id' => $actor->id];
            $head = DB::table('scope_proposal_envelopes')->where($key)->orderByDesc('version')->first();
            if ((int) ($head->version ?? 0) !== (int) $d['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => __('scope_proposals.stale')]);
            }
            $id = (string) Str::uuid();
            DB::table('scope_proposal_envelopes')->insert($key + ['id' => $id, 'version' => (int) $d['expected_version'] + 1, 'previous_id' => $head?->id, 'statement' => $d['statement']]);
            // Trigger independently derives binding/digests/nomination IDs; client declarations cannot override them.
            $this->authority->preflight($p, $actor, $source, 'propose');

            return $this->result(DB::table('scope_proposal_envelopes')->where('id', $id)->first());
        }, $project->id);
    }

    public function review(Project $project, User $actor, array $input): array
    {
        $d = Validator::make(['review' => $input], ['review' => ['required', 'array:proposal_id,expected_version,expected_digest,outcome,reason'],
            'review.proposal_id' => ['required', 'uuid'], 'review.expected_version' => ['required', 'integer', 'min:1'],
            'review.expected_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'review.outcome' => ['required', Rule::in(['COMMENTED', 'CHANGES_REQUESTED'])], 'review.reason' => ['required', 'string', 'max:4000', 'regex:/\S/u']])->validate()['review'];

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): array {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->archives->assertWritable($p);
            $e = DB::table('scope_proposal_envelopes')->where('id', $d['proposal_id'])->where('project_id', $p->id)->where('company_id', $actor->company_id)->first();
            abort_unless($e, 404);
            $source = $this->source($e);
            $this->authority->preflight($p, $actor, $source, 'review', $e->proposer_id, $actor->id);
            abort_unless($this->binding($p, $actor, $source) === $e->binding, 403);
            $newer = DB::table('scope_proposal_envelopes')->where($source + ['project_id' => $p->id, 'company_id' => $actor->company_id, 'proposer_id' => $e->proposer_id])->where('version', '>', $e->version)->exists();
            if ($newer || (int) $e->version !== (int) $d['expected_version'] || ! hash_equals($e->digest, $d['expected_digest'])
                || DB::table('scope_proposal_reviews')->where('proposal_id', $e->id)->where('reviewer_id', $actor->id)->exists()) {
                throw ValidationException::withMessages(['expected_version' => __('scope_proposals.stale')]);
            }
            if (DB::table('scope_structure_declarations')->where('project_id', $p->id)->where('company_id', $actor->company_id)->where('proposal_id', $e->id)->exists()) {
                throw ValidationException::withMessages(['proposal_id' => __('scope_structure.bound_review_required')]);
            }
            $id = (string) Str::uuid();
            DB::table('scope_proposal_reviews')->insert(['id' => $id, 'project_id' => $p->id, 'company_id' => $actor->company_id,
                'proposal_id' => $e->id, 'proposal_version' => $e->version, 'proposal_digest' => $e->digest, 'reviewer_id' => $actor->id, 'outcome' => $d['outcome'], 'reason' => $d['reason']]);
            $this->authority->preflight($p, $actor, $source, 'review', $e->proposer_id, $actor->id);

            return $this->result(DB::table('scope_proposal_reviews')->where('id', $id)->first());
        }, $project->id);
    }
}
