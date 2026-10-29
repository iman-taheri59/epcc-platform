<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Approved original-preparer/live-custody grant; no arrival edits, acceptance or stock. */
final class PipingCustodyAmendmentService
{
    public function __construct(private readonly PipingCustodyService $custody, private readonly ProjectArchiveService $archives) {}

    public function dashboard(Project $project, User $actor, string $custodyId): array
    {
        return $this->custody->scoped($project, $actor, function () use ($project, $actor, $custodyId): array {
            $receipt = $this->custody->detail($project, $actor, $custodyId);
            $basis = $this->custody->basis($project, $actor, $custodyId);
            $policyId = $this->custody->policyId($project, $actor, 'custody_mrr');
            $writable = ! DB::table('piping_grn_posted_index')->where('project_id', $project->id)->where('custody_id', $custodyId)->exists() && $basis !== null && $receipt->created_by === $actor->id && $receipt->company_id === $actor->company_id && $policyId !== null && $this->archives->isWritable($project);
            $query = DB::table('piping_custody_amendments')->where('custody_id', $custodyId)->orderByDesc('sequence');
            $latest = (clone $query)->first();
            $amendments = $query->paginate(20)->withQueryString();
            $sources = DB::table('piping_custody_amendment_sources')->whereIn('amendment_id', $amendments->pluck('id'))->get()->keyBy('amendment_id');
            $sourceOptions = $writable ? DB::table('purchase_order_items as l')->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')->where('p.project_id', $project->id)->where('l.project_id', $project->id)->where('p.discipline', 'PIPING')->where('l.unit', $receipt->unit)->orderBy('p.number')->orderBy('l.line_no')->select('l.id', 'l.line_no', 'l.item_code', 'l.unit', 'p.number')->paginate(50, ['*'], 'sources_page')->withQueryString() : null;

            return compact('sourceOptions', 'receipt', 'basis', 'policyId', 'writable', 'latest', 'amendments', 'sources');
        });
    }

    public function create(Project $project, User $actor, string $custodyId, array $input): string
    {
        try {
            return $this->custody->scoped($project, $actor, function () use ($project, $actor, $custodyId, $input): string {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$project->id]);
                $project = Project::whereKey($project->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                $policy = $this->custody->policyId($project, $actor, 'custody_mrr');
                abort_unless($policy !== null, 403);
                $this->archives->assertWritable($project);
                $receipt = $this->custody->detail($project, $actor, $custodyId);
                app(PipingGrnService::class)->assertUnposted($project, $custodyId);
                abort_unless($receipt->created_by === $actor->id && $receipt->company_id === $actor->company_id, 403);
                $data = Validator::make(['amendment' => $input], [
                    'amendment' => ['required', 'array:expected_policy_id,previous_amendment_id,request_id,source_line_id,unlinked_reason,heat_ref,lot_ref,reason'],
                    'amendment.expected_policy_id' => ['required', 'uuid'], 'amendment.previous_amendment_id' => ['present', 'nullable', 'uuid'], 'amendment.request_id' => ['required', 'uuid'],
                    'amendment.source_line_id' => ['present', 'nullable', 'uuid'], 'amendment.unlinked_reason' => ['present', 'nullable', 'string', 'max:2000'],
                    'amendment.heat_ref' => ['present', 'nullable', 'string', 'max:200'], 'amendment.lot_ref' => ['present', 'nullable', 'string', 'max:200'], 'amendment.reason' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
                ], array_fill_keys(['required', 'present', 'array', 'uuid', 'string', 'max', 'regex'], __('piping_amendments.invalid')))->validate()['amendment'];
                foreach (['unlinked_reason', 'heat_ref', 'lot_ref', 'reason'] as $key) {
                    if (str_contains($data[$key] ?? '', "\0")) {
                        $this->fail();
                    }$data[$key] = trim($data[$key] ?? '');
                    if ($data[$key] === '') {
                        $data[$key] = null;
                    }
                }
                if ($data['source_line_id'] === null ? $data['unlinked_reason'] === null : $data['unlinked_reason'] !== null) {
                    $this->fail();
                }
                if ($data['expected_policy_id'] !== $policy) {
                    $this->fail('stale');
                }
                $canonical = [];
                foreach (['expected_policy_id', 'previous_amendment_id', 'request_id', 'source_line_id', 'unlinked_reason', 'heat_ref', 'lot_ref', 'reason'] as $key) {
                    $canonical[$key] = $data[$key];
                }
                $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
                if ($data['source_line_id'] !== null) {
                    $snap = DB::scalar('SELECT epcc_piping_source_snapshot(?,?)', [$project->id, $data['source_line_id']]);
                    if ($snap === null || json_decode($snap, true)['unit'] !== $receipt->unit) {
                        $this->fail('source_error');
                    }
                }
                $old = DB::table('piping_custody_amendments')->where('project_id', $project->id)->where('request_id', $data['request_id'])->first();
                if ($old) {
                    if ($old->custody_id !== $custodyId || $old->created_by !== $actor->id || $old->company_id !== $actor->company_id || $old->policy_id !== $policy || ! hash_equals($old->payload_hash, $hash)) {
                        $this->fail('retry');
                    }

                    return $old->id;
                }
                abort_unless($this->custody->basis($project, $actor, $custodyId) !== null, 403);
                $head = DB::table('piping_custody_amendments')->where('custody_id', $custodyId)->orderByDesc('sequence')->value('id');
                if ($head !== $data['previous_amendment_id']) {
                    $this->fail('stale');
                }
                $id = (string) Str::uuid();
                DB::table('piping_custody_amendments')->insert(['id' => $id, 'project_id' => $project->id, 'custody_id' => $custodyId, 'company_id' => $actor->company_id, 'created_by' => $actor->id, 'policy_id' => $policy, 'previous_amendment_id' => $data['previous_amendment_id'], 'request_id' => $data['request_id'], 'payload_hash' => $hash, 'source_kind' => $data['source_line_id'] !== null ? 'LINKED' : 'UNRESOLVED', 'unlinked_reason' => $data['unlinked_reason'], 'heat_ref' => $data['heat_ref'], 'lot_ref' => $data['lot_ref'], 'reason' => $data['reason']]);
                if ($data['source_line_id'] !== null) {
                    DB::table('piping_custody_amendment_sources')->insert(['amendment_id' => $id, 'project_id' => $project->id, 'source_line_id' => $data['source_line_id']]);
                }
                DB::statement('SET CONSTRAINTS piping_amendment_complete IMMEDIATE');
                DB::statement('SET CONSTRAINTS piping_amendment_complete DEFERRED');

                return $id;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'PIPING_AMENDMENT_AUTHORITY') || str_contains($e->getMessage(), 'PIPING_AMENDMENT_PREPARER') || str_contains($e->getMessage(), 'PIPING_AMENDMENT_SOURCE')) {
                abort(403);
            }
            if (str_contains($e->getMessage(), 'PIPING_AMENDMENT_STALE')) {
                $this->fail('stale');
            }
            if (str_contains($e->getMessage(), 'PIPING_AMENDMENT_UNIT_SOURCE')) {
                $this->fail('source_error');
            }
            if ((string) $e->getCode() === '23505') {
                $this->fail('retry');
            }throw $e;
        }
    }

    private function fail(string $key = 'invalid'): never
    {
        throw ValidationException::withMessages(['amendment' => __('piping_amendments.'.$key)]);
    }
}
