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

/** Internal single-scope unverified claims. No evidence references, technical review or admission writer. */
final class ScopeStructureDeclarationService
{
    public function __construct(private readonly TenantContext $tenant, private readonly ScopeProposalEnvelopeReader $reader,
        private readonly ScopeAdmissionAuthorityService $authority, private readonly ProjectArchiveService $archives) {}

    private function claim(object $e, array $nodes, array $coverage): ?object
    {
        $r = DB::selectOne('SELECT epcc_scope_structure_claim(?,?,?,?,?,?::jsonb,?::jsonb)::text AS claim', [$e->project_id, $e->batch_id, $e->scope_id, $e->hierarchy_revision_id, $e->observation_id, json_encode($nodes, JSON_THROW_ON_ERROR), json_encode($coverage, JSON_THROW_ON_ERROR)]);

        return $r?->claim ? json_decode($r->claim, flags: JSON_THROW_ON_ERROR) : null;
    }

    public function declare(Project $project, User $actor, array $input): array
    {
        $d = Validator::make(['declaration' => $input], [
            'declaration' => ['required', 'array:proposal_id,expected_proposal_version,expected_proposal_digest,nodes,coverage'],
            'declaration.proposal_id' => ['required', 'uuid'], 'declaration.expected_proposal_version' => ['required', 'integer', 'min:1'],
            'declaration.expected_proposal_digest' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'declaration.nodes' => ['required', 'array', 'list', 'min:1', 'max:500'],
            'declaration.nodes.*' => ['required', 'array:id,project_id,kind,parent_id'],
            'declaration.nodes.*.id' => ['required', 'uuid'], 'declaration.nodes.*.project_id' => ['required', 'uuid'],
            'declaration.nodes.*.kind' => ['required', Rule::in(['PROJECT', 'FACILITY', 'AREA', 'SUBSYSTEM'])],
            'declaration.nodes.*.parent_id' => ['present', 'nullable', 'uuid'],
            'declaration.coverage' => ['required', 'array', 'list', 'size:1'], 'declaration.coverage.*' => ['required', 'array:scope_id,row_ids'],
            'declaration.coverage.*.scope_id' => ['required', 'uuid'], 'declaration.coverage.*.row_ids' => ['required', 'array', 'list', 'min:1', 'max:10000'],
            'declaration.coverage.*.row_ids.*' => ['required', 'uuid'],
        ])->validate()['declaration'];
        if (strlen(json_encode([$d['nodes'], $d['coverage']], JSON_THROW_ON_ERROR)) > 262144) {
            throw ValidationException::withMessages(['declaration' => __('scope_structure.invalid')]);
        }

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $d): array {
            $p = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->archives->assertWritable($p);
            $view = $this->reader->inspect($p, $actor, $d['proposal_id']);
            abort_unless($view['proposal']['proposer_id'] === $actor->id, 403);
            $e = DB::table('scope_proposal_envelopes')->where('id', $d['proposal_id'])->where('project_id', $p->id)->where('company_id', $actor->company_id)->first();
            if (! $view['is_latest'] || (int) $e->version !== (int) $d['expected_proposal_version'] || ! hash_equals($e->digest, $d['expected_proposal_digest'])
                || DB::table('scope_structure_declarations')->where('proposal_id', $e->id)->exists()
                || DB::table('scope_proposal_reviews')->where('proposal_id', $e->id)->exists()) {
                throw ValidationException::withMessages(['declaration' => __('scope_structure.stale')]);
            }
            $claim = $this->claim($e, $d['nodes'], $d['coverage']);
            if (! $claim) {
                throw ValidationException::withMessages(['declaration' => __('scope_structure.invalid')]);
            }
            $id = (string) Str::uuid();
            DB::table('scope_structure_declarations')->insert(['id' => $id, 'project_id' => $p->id, 'company_id' => $actor->company_id, 'declared_by' => $actor->id,
                'proposal_id' => $e->id, 'version' => $e->version, 'proposal_digest' => $e->digest, 'nodes' => json_encode($d['nodes'], JSON_THROW_ON_ERROR), 'coverage' => json_encode($d['coverage'], JSON_THROW_ON_ERROR)]);
            $this->authority->preflight($p, $actor, array_intersect_key((array) $e, array_flip(['batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id'])), 'propose');
            $saved = DB::table('scope_structure_declarations')->where('id', $id)->first();

            return ['id' => $id, 'version' => (int) $saved->version, 'digest' => $saved->digest, 'proposal_id' => $e->id, 'proposal_digest' => $e->digest,
                'ready_to_admit' => false, 'package_verified' => false, 'operationally_authorized' => false, 'gross' => null, 'state' => 'UNVERIFIED_STRUCTURE'];
        }, $project->id);
    }

    public function inspect(Project $project, User $actor, string $id): array
    {
        abort_unless(Str::isUuid($id), 404);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $id): array {
            $m = DB::table('scope_structure_declarations')->where('id', $id)->where('project_id', $project->id)->where('company_id', $actor->company_id)->first(['proposal_id']);
            abort_unless($m, 404);
            $view = $this->reader->inspect($project, $actor, $m->proposal_id);
            $d = DB::table('scope_structure_declarations')->where('id', $id)->where('project_id', $project->id)->where('company_id', $actor->company_id)
                ->select('*')->selectRaw("digest=encode(sha256(convert_to(envelope::text,'UTF8')),'hex') AND envelope->'nodes'=nodes AND envelope->'coverage'=coverage
 AND envelope->>'id'=id::text AND envelope->>'proposal_id'=proposal_id::text AND envelope->>'proposal_digest'=proposal_digest
 AND envelope->>'declared_by'=declared_by::text AND envelope->'version'=to_jsonb(version)
 AND envelope->>'project_id'=project_id::text AND envelope->>'company_id'=company_id::text AS integrity_ok")->first();
            abort_unless($d && $d->integrity_ok && $d->state === 'UNVERIFIED_STRUCTURE' && $d->declared_by === $view['proposal']['proposer_id'] && $d->proposal_digest === $view['proposal']['digest'] && (int) $d->version === (int) $view['proposal']['version'], 404);
            $e = DB::table('scope_proposal_envelopes')->where('id', $m->proposal_id)->where('project_id', $project->id)->where('company_id', $actor->company_id)->first();
            $claim = $this->claim($e, json_decode($d->nodes, true, flags: JSON_THROW_ON_ERROR), json_decode($d->coverage, true, flags: JSON_THROW_ON_ERROR));
            abort_unless($claim, 404);
            $this->reader->inspect($project, $actor, $m->proposal_id);
            // Original-reader gates and independent visibility must both remain satisfied.
            abort_unless($this->claim($e, json_decode($d->nodes, true, flags: JSON_THROW_ON_ERROR), json_decode($d->coverage, true, flags: JSON_THROW_ON_ERROR)), 404);

            return ['id' => $d->id, 'version' => (int) $d->version, 'previous_id' => $d->previous_id, 'digest' => $d->digest, 'proposal_id' => $d->proposal_id, 'proposal_digest' => $d->proposal_digest,
                'nodes' => $claim->nodes, 'coverage' => $claim->coverage, 'is_latest' => $view['is_latest'], 'evidence_attached' => false, 'technical_review_bound' => false,
                'ready_to_admit' => false, 'package_verified' => false, 'operationally_authorized' => false, 'gross' => null, 'state' => 'UNVERIFIED_STRUCTURE'];
        }, $project->id);
    }
}
