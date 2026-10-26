<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentFile;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Manual QA assessment. Neither an automatic technical checker nor a stock transaction. */
final class PipingQaDecisionService
{
    public const DECLARATIONS = ['source_match', 'quantity_unit_match', 'heat_traceability', 'mandatory_documents_complete', 'nonconformities_closed', 'requirements_met'];

    public function __construct(private readonly PipingCustodyService $custody, private readonly ProjectArchiveService $archives, private readonly DocumentFileStore $files) {}

    private function documentReader(Project $project, User $actor): bool
    {
        return (bool) DB::scalar('SELECT epcc_piping_qa_document_reader(?,?,?)', [$project->id, $actor->id, $actor->company_id]);
    }

    private function available(Project $project)
    {
        return DB::table('document_files as f')->join('document_revisions as r', 'r.id', '=', 'f.revision_id')->join('documents as d', 'd.id', '=', 'f.document_id')
            ->where('f.project_id', $project->id)->where('r.project_id', $project->id)->where('d.project_id', $project->id)->whereColumn('r.document_id', 'f.document_id')
            ->whereIn('r.cde_state', ['PUBLISHED', 'ARCHIVED'])->select('f.*', 'r.revision', 'r.ordinal', 'r.cde_state', 'r.suitability', 'd.number');
    }

    public function dashboard(Project $project, User $actor, string $custodyId): array
    {
        return $this->custody->scoped($project, $actor, function () use ($project, $actor, $custodyId): array {
            $receipt = $this->custody->detail($project, $actor, $custodyId);
            $review = DB::table('piping_receiving_reviews')->where('custody_id', $custodyId)->orderByDesc('sequence')->first();
            $basis = $this->custody->basis($project, $actor, $custodyId);
            $amendmentId = DB::table('piping_custody_amendments')->where('custody_id', $custodyId)->orderByDesc('sequence')->value('id');
            $policyId = $this->custody->policyId($project, $actor, 'acceptance_qa');
            $writable = ! DB::table('piping_grn_posted_index')->where('project_id', $project->id)->where('custody_id', $custodyId)->exists() && $basis !== null && $policyId !== null && $review && ($review->amendment_id ?? null) === $amendmentId && $receipt->created_by !== $actor->id && $review->reviewed_by !== $actor->id && $this->archives->isWritable($project);
            $query = DB::table('piping_qa_decisions')->where('custody_id', $custodyId)->orderByDesc('sequence');
            $latest = (clone $query)->first();
            $decisions = $query->paginate(20)->withQueryString();
            $documentReader = $this->documentReader($project, $actor);
            // This is the existing document read capability AND ordinary CDE RLS, not delegation from QA.
            $fileOptions = $documentReader ? $this->available($project)->orderBy('d.number')->orderByDesc('r.ordinal')->orderBy('f.id')->paginate(50, ['*'], 'files_page')->withQueryString() : null;
            $citations = DB::table('piping_qa_evidence')->whereIn('decision_id', $decisions->pluck('id'))->orderBy('kind')->orderBy('file_id')->get()->groupBy('decision_id');

            $amendedSourceBases = DB::table('piping_qa_amendment_source_bases')->whereIn('decision_id', $decisions->pluck('id'))->get()->keyBy('decision_id');

            return compact('amendedSourceBases', 'basis', 'amendmentId', 'receipt', 'review', 'policyId', 'writable', 'latest', 'decisions', 'documentReader', 'fileOptions', 'citations');
        });
    }

    public function create(Project $project, User $actor, string $custodyId, array $input): string
    {
        try {
            return $this->custody->scoped($project, $actor, function () use ($project, $actor, $custodyId, $input): string {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$project->id]);
                $project = Project::whereKey($project->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                $policy = $this->custody->policyId($project, $actor, 'acceptance_qa');
                abort_unless($policy !== null, 403);
                $this->archives->assertWritable($project);
                $receipt = $this->custody->detail($project, $actor, $custodyId);
                app(PipingGrnService::class)->assertUnposted($project, $custodyId);
                $rules = [
                    'qa' => ['required', 'array:amendment_id,expected_policy_id,previous_decision_id,review_id,request_id,decision,reason,open_conditions,declarations,requirements_file_ids,evidence_file_ids'],
                    'qa.expected_policy_id' => ['required', 'uuid'], 'qa.previous_decision_id' => ['present', 'nullable', 'uuid'],
                    'qa.amendment_id' => ['nullable', 'uuid'], 'qa.review_id' => ['required', 'uuid'], 'qa.request_id' => ['required', 'uuid'], 'qa.decision' => ['required', 'in:ACCEPTED,HOLD,REJECTED'],
                    'qa.reason' => ['required', 'string', 'max:4000', 'regex:/\S/u'], 'qa.open_conditions' => ['present', 'nullable', 'string', 'max:4000'],
                    'qa.declarations' => ['required', 'array:'.implode(',', self::DECLARATIONS)],
                    'qa.requirements_file_ids' => ['present', 'array', 'max:20'], 'qa.evidence_file_ids' => ['present', 'array', 'max:20'],
                    'qa.requirements_file_ids.*' => ['required', 'uuid', 'distinct'], 'qa.evidence_file_ids.*' => ['required', 'uuid', 'distinct'],
                ];
                foreach (self::DECLARATIONS as $key) {
                    $rules['qa.declarations.'.$key] = ['required', 'boolean'];
                }
                $data = Validator::make(['qa' => $input], $rules, array_fill_keys(['required', 'present', 'array', 'uuid', 'string', 'max', 'regex', 'in', 'boolean', 'distinct'], __('piping_qa.invalid')))->validate()['qa'];
                $data['amendment_id'] = $data['amendment_id'] ?? null;
                foreach (['reason', 'open_conditions'] as $key) {
                    if (str_contains($data[$key] ?? '', "\0")) {
                        $this->fail();
                    }
                    $data[$key] = trim($data[$key] ?? '');
                }
                $data['open_conditions'] = $data['open_conditions'] === '' ? null : $data['open_conditions'];
                $decl = [];
                foreach (self::DECLARATIONS as $key) {
                    $decl[$key] = (bool) $data['declarations'][$key];
                }
                $data['declarations'] = $decl;
                foreach (['requirements_file_ids', 'evidence_file_ids'] as $key) {
                    $data[$key] = array_values($data[$key]);
                    sort($data[$key], SORT_STRING);
                }
                if ($policy !== $data['expected_policy_id']) {
                    $this->fail('policy_stale');
                }
                $fileIds = array_values(array_unique(array_merge($data['requirements_file_ids'], $data['evidence_file_ids'])));
                if ($fileIds !== []) {
                    abort_unless($this->documentReader($project, $actor), 403);
                    if ($this->available($project)->whereIn('f.id', $fileIds)->count() !== count($fileIds)) {
                        $this->fail('evidence_invalid');
                    }
                }
                // Fixed key ordering makes identical JSON and form retries equivalent. Citation order is immaterial.
                $canonical = [];
                foreach (['expected_policy_id', 'previous_decision_id', 'review_id', 'request_id', 'decision', 'reason', 'open_conditions', 'declarations', 'requirements_file_ids', 'evidence_file_ids'] as $key) {
                    $canonical[$key] = $data[$key];
                }
                if ($data['amendment_id'] !== null) {
                    $canonical['amendment_id'] = $data['amendment_id'];
                } // Preserve legacy null-basis retry hashes.
                $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
                $old = DB::table('piping_qa_decisions')->where('project_id', $project->id)->where('request_id', $data['request_id'])->first();
                if ($old) {
                    if ($old->custody_id !== $custodyId || $old->decided_by !== $actor->id || $old->company_id !== $actor->company_id || $old->policy_id !== $policy || ! hash_equals($old->payload_hash, $hash)) {
                        $this->fail('retry_conflict');
                    }

                    return $old->id; // Historical receipt only; never reactivates a superseded acceptance.
                }
                $basis = $this->custody->basis($project, $actor, $custodyId);
                abort_unless($basis !== null, 403);
                if ($data['amendment_id'] !== $basis['amendment_id']) {
                    $this->fail('amendment_stale');
                }
                $review = DB::table('piping_receiving_reviews')->where('custody_id', $custodyId)->orderByDesc('sequence')->first();
                if (! $review || $review->id !== $data['review_id'] || ($review->amendment_id ?? null) !== $data['amendment_id']) {
                    $this->fail('qc_stale');
                }
                if ($receipt->created_by === $actor->id || $review->reviewed_by === $actor->id) {
                    $this->fail('separation');
                }
                $head = DB::table('piping_qa_decisions')->where('custody_id', $custodyId)->orderByDesc('sequence')->first();
                if (($head->id ?? null) !== $data['previous_decision_id']) {
                    $this->fail('stale');
                }
                if ($data['decision'] === 'ACCEPTED' && ($data['open_conditions'] !== null || in_array(false, $decl, true) || ! $data['requirements_file_ids'] || ! $data['evidence_file_ids'])) {
                    $this->fail('acceptance_blocked');
                }
                foreach (DocumentFile::whereIn('id', $fileIds)->get() as $file) {
                    try {
                        $valid = $this->files->verify($file) && Storage::disk($file->disk)->size($file->storage_path) === $file->size_bytes;
                    } catch (\Throwable) {
                        $valid = false;
                    }
                    if (! $valid) {
                        $this->fail('evidence_invalid');
                    }
                }
                $id = (string) Str::uuid();
                DB::table('piping_qa_decisions')->insert([
                    'id' => $id, 'project_id' => $project->id, 'custody_id' => $custodyId, 'company_id' => $actor->company_id, 'decided_by' => $actor->id, 'policy_id' => $policy,
                    'amendment_id' => $data['amendment_id'], 'previous_decision_id' => $data['previous_decision_id'], 'review_id' => $data['review_id'], 'request_id' => $data['request_id'], 'payload_hash' => $hash,
                    'decision' => $data['decision'], 'reason' => $data['reason'], 'open_conditions' => $data['open_conditions'], 'declarations' => json_encode($decl, JSON_THROW_ON_ERROR),
                ]);
                foreach (['requirements_file_ids' => 'REQUIREMENT', 'evidence_file_ids' => 'EVIDENCE'] as $key => $kind) {
                    foreach ($data[$key] as $fileId) {
                        DB::table('piping_qa_evidence')->insert(['decision_id' => $id, 'project_id' => $project->id, 'file_id' => $fileId, 'kind' => $kind]);
                    }
                }
                DB::statement('SET CONSTRAINTS piping_qa_complete IMMEDIATE');
                DB::statement('SET CONSTRAINTS piping_qa_complete DEFERRED');

                return $id;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'PIPING_AMENDMENT_SOURCE')) {
                abort(403);
            }
            if (str_contains($e->getMessage(), 'PIPING_AMENDMENT_STALE')) {
                $this->fail('amendment_stale');
            }
            if (str_contains($e->getMessage(), 'PIPING_QA_AUTHORITY') || str_contains($e->getMessage(), 'PIPING_QA_PARENT')) {
                abort(403);
            }
            foreach (['QC_STALE' => 'qc_stale', 'SEPARATION' => 'separation', 'SOURCE_BLOCKED' => 'source_blocked', 'ACCEPTANCE_BLOCKED' => 'acceptance_blocked', 'EVIDENCE_' => 'evidence_invalid', 'STALE' => 'stale'] as $code => $message) {
                if (str_contains($e->getMessage(), 'PIPING_QA_'.$code)) {
                    $this->fail($message);
                }
            }
            if ((string) $e->getCode() === '23505') {
                $this->fail('retry_conflict');
            }
            throw $e;
        }
    }

    private function fail(string $key = 'invalid'): never
    {
        throw ValidationException::withMessages(['qa' => __('piping_qa.'.$key)]);
    }
}
