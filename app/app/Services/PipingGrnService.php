<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentFile;
use App\Models\Project;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Whole accepted receipt -> immutable controlled GRN + one exact stock credit. */
final class PipingGrnService
{
    public function __construct(private readonly PipingCustodyService $custody, private readonly ProjectArchiveService $archives, private readonly DocumentFileStore $files, private readonly AuthorizationPipeline $authz) {}

    private function fail(): never
    {
        throw ValidationException::withMessages(['grn' => __('piping_grn.blocked')]);
    }

    public function assertUnposted(Project $p, string $id): void
    {
        if (! DB::scalar('SELECT epcc_piping_grn_posted_index_ok()') || DB::table('piping_grn_posted_index')->where('project_id', $p->id)->where('custody_id', $id)->exists()) {
            $this->fail();
        }
    }

    private function proof(Project $p, User $actor, object $q): string
    {
        $rows = DB::table('piping_qa_evidence')->where('decision_id', $q->id)->get();
        $body = [];
        foreach (['expected_policy_id' => $q->policy_id, 'previous_decision_id' => $q->previous_decision_id, 'review_id' => $q->review_id, 'request_id' => $q->request_id, 'decision' => $q->decision, 'reason' => $q->reason, 'open_conditions' => $q->open_conditions] as $k => $v) {
            $body[$k] = $v;
        }
        $decl = json_decode($q->declarations, true, flags: JSON_THROW_ON_ERROR);
        $body['declarations'] = [];
        foreach (PipingQaDecisionService::DECLARATIONS as $k) {
            $body['declarations'][$k] = $decl[$k];
        }
        foreach (['requirements_file_ids' => 'REQUIREMENT', 'evidence_file_ids' => 'EVIDENCE'] as $k => $kind) {
            $ids = $rows->where('kind', $kind)->pluck('file_id')->all();
            sort($ids, SORT_STRING);
            $body[$k] = $ids;
        }
        if ($q->amendment_id !== null) {
            $body['amendment_id'] = $q->amendment_id;
        }
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        // SHA commitment closes a partly hidden citation set, including both purposes.
        if (! hash_equals($q->payload_hash, hash('sha256', $json))) {
            $this->fail();
        }
        $u = User::with('company.plan')->whereKey($actor->id)->where('company_id', $actor->company_id)->firstOrFail();
        $checks = [];
        foreach ($rows->unique('file_id') as $row) {
            $s = json_decode($row->snapshot, true, flags: JSON_THROW_ON_ERROR);
            $file = DocumentFile::where('project_id', $p->id)->find($row->file_id);
            $doc = $file ? DB::table('documents')->where('id', $file->document_id)->where('project_id', $p->id)->first(['discipline', 'number']) : null;
            $rev = $file ? DB::table('document_revisions')->where('id', $file->revision_id)->where('document_id', $file->document_id)->where('project_id', $p->id)->first(['cde_state', 'revision', 'ordinal', 'suitability']) : null;
            if (! $file || ! $doc || ! $rev || ! in_array($rev->cde_state, ['PUBLISHED', 'ARCHIVED'], true) || $this->authz->check($u, 'doc.view', $p, ['discipline' => $doc->discipline]) !== null
                || $file->id !== $s['id'] || $file->original_name !== $s['original_name'] || $doc->number !== $s['number'] || $rev->revision !== $s['revision'] || (int) $rev->ordinal !== (int) $s['ordinal'] || $rev->suitability !== $s['suitability']
                || $file->document_id !== $s['document_id'] || $file->revision_id !== $s['revision_id'] || $file->sha256 !== $s['sha256'] || (int) $file->size_bytes !== (int) $s['size_bytes']) {
                $this->fail();
            }
            $checks[] = $file;
        }
        if (! $checks) {
            $this->fail();
        }
        // All dependencies authorized before any file read. No client path/disk.
        foreach ($checks as $file) {
            try {
                $ok = $this->files->verify($file) && Storage::disk($file->disk)->size($file->storage_path) === $file->size_bytes;
            } catch (\Exception) {
                $ok = false;
            }if (! $ok) {
                $this->fail();
            }
        }

        return $json;
    }

    public function dashboard(Project $p, User $actor, string $receiptId): array
    {
        return $this->custody->scoped($p, $actor, function () use ($p, $actor, $receiptId) {
            $receipt = $this->custody->detail($p, $actor, $receiptId);
            $basis = $this->custody->basis($p, $actor, $receiptId);
            $qa = DB::table('piping_qa_decisions')->where('custody_id', $receiptId)->orderByDesc('sequence')->first();
            $grn = DB::table('piping_grn_records')->where('custody_id', $receiptId)->first();
            $credit = $grn ? DB::table('piping_accepted_stock_entries')->where('grn_id', $grn->id)->first() : null;
            $policyId = $this->custody->policyId($p, $actor, 'post_grn');
            $writable = $basis !== null && $basis['source_line_id'] !== null && ! DB::table('piping_grn_posted_index')->where('custody_id', $receiptId)->where('project_id', $p->id)->exists() && DB::scalar('SELECT epcc_piping_grn_inventory_ok()') && ! $grn && $qa && $qa->decision === 'ACCEPTED' && $policyId && $this->archives->isWritable($p);

            return compact('receipt', 'basis', 'qa', 'grn', 'credit', 'policyId', 'writable');
        });
    }

    public function post(Project $p, User $actor, string $receiptId, array $input): string
    {
        try {
            return $this->custody->scoped($p, $actor, function () use ($p, $actor, $receiptId, $input) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$p->id]);
                $p = Project::whereKey($p->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                $this->archives->assertWritable($p);
                $policy = $this->custody->policyId($p, $actor, 'post_grn');
                abort_unless($policy, 403);
                $this->custody->detail($p, $actor, $receiptId);
                $basis = $this->custody->basis($p, $actor, $receiptId);
                if ($basis === null || $basis['source_line_id'] === null) {
                    $this->fail();
                }
                $d = Validator::make(['grn' => $input], [
                    'grn' => ['required', 'array:request_id,expected_policy_id,qa_id,qa_payload_hash,location_ref,reason,warehouse_confirmed'],
                    'grn.request_id' => ['required', 'uuid'], 'grn.expected_policy_id' => ['required', 'uuid'], 'grn.qa_id' => ['required', 'uuid'],
                    'grn.qa_payload_hash' => ['required', 'regex:/^[a-f0-9]{64}$/D'], 'grn.location_ref' => ['required', 'string', 'max:200', 'regex:/\S/u'],
                    'grn.reason' => ['required', 'string', 'max:4000', 'regex:/\S/u'], 'grn.warehouse_confirmed' => ['required', 'accepted'],
                ])->validate()['grn'];
                if ($d['expected_policy_id'] !== $policy) {
                    $this->fail();
                }
                foreach (['reason', 'location_ref'] as $k) {
                    $d[$k] = trim($d[$k]);
                    if (str_contains($d[$k], "\0")) {
                        $this->fail();
                    }
                }$d['warehouse_confirmed'] = true;
                $canonical = [];
                foreach (['request_id', 'expected_policy_id', 'qa_id', 'qa_payload_hash', 'location_ref', 'reason', 'warehouse_confirmed'] as $k) {
                    $canonical[$k] = $d[$k];
                }
                $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
                $old = DB::table('piping_grn_records')->where('project_id', $p->id)->where('request_id', $d['request_id'])->first();
                if ($old) {
                    if ($old->custody_id !== $receiptId || $old->posted_by !== $actor->id || $old->company_id !== $actor->company_id || ! hash_equals($old->request_hash, $hash)) {
                        $this->fail();
                    }

                    return $old->id;
                }
                $q = DB::table('piping_qa_decisions')->where('custody_id', $receiptId)->orderByDesc('sequence')->first();
                if (! $q || $q->id !== $d['qa_id'] || $q->payload_hash !== $d['qa_payload_hash'] || $q->decision !== 'ACCEPTED') {
                    $this->fail();
                }
                $proof = $this->proof($p, $actor, $q);
                $id = (string) Str::uuid();
                DB::table('piping_grn_records')->insert(['id' => $id, 'project_id' => $p->id, 'company_id' => $actor->company_id, 'posted_by' => $actor->id, 'policy_id' => $policy,
                    'custody_id' => $receiptId, 'qa_id' => $q->id, 'qa_payload_hash' => $q->payload_hash, 'qa_payload' => $proof, 'request_id' => $d['request_id'], 'request_hash' => $hash, 'location_ref' => $d['location_ref'], 'reason' => $d['reason']]);
                // No credit survives a late dependency/eligibility change in this transaction.
                if ($this->proof($p, $actor, $q) !== $proof || $this->custody->policyId($p, $actor, 'post_grn') !== $policy) {
                    $this->fail();
                }

                DB::statement('SET CONSTRAINTS piping_grn_complete IMMEDIATE');
                DB::statement('SET CONSTRAINTS piping_grn_complete DEFERRED');

                return $id;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'PIPING_') || in_array((string) $e->getCode(), ['23505', '23514'], true)) {
                $this->fail();
            }throw $e;
        }
    }
}
