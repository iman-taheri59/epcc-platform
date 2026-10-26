<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** ADR-046 receiving QC evidence on immutable custody; not QA/GRN or ADR-041 PO observation. */
final class PipingReceivingReviewService
{
    public const RESULTS = ['NO_NONCONFORMITY_OBSERVED', 'NONCONFORMITY_OBSERVED', 'INCOMPLETE'];

    public function __construct(private readonly PipingCustodyService $custody, private readonly ProjectArchiveService $archives) {}

    public function dashboard(Project $project, User $actor, string $custodyId): array
    {
        return $this->custody->scoped($project, $actor, function () use ($project, $actor, $custodyId): array {
            $receipt = $this->custody->detail($project, $actor, $custodyId);
            $basis = $this->custody->basis($project, $actor, $custodyId);
            $amendmentId = DB::table('piping_custody_amendments')->where('custody_id', $custodyId)->orderByDesc('sequence')->value('id');
            $policyId = $this->custody->policyId($project, $actor, 'receiving_qc');
            $writable = ! DB::table('piping_grn_posted_index')->where('project_id', $project->id)->where('custody_id', $custodyId)->exists() && $basis !== null && $policyId !== null && $receipt->created_by !== $actor->id && $this->archives->isWritable($project);
            $reviews = DB::table('piping_receiving_reviews')->where('project_id', $project->id)->where('custody_id', $custodyId)->orderByDesc('sequence')->paginate(20)->withQueryString();
            $latest = DB::table('piping_receiving_reviews')->where('project_id', $project->id)->where('custody_id', $custodyId)->orderByDesc('sequence')->first();

            return compact('basis', 'amendmentId', 'receipt', 'policyId', 'writable', 'reviews', 'latest');
        });
    }

    public function create(Project $project, User $actor, string $custodyId, array $input): string
    {
        try {
            return $this->custody->scoped($project, $actor, function () use ($project, $actor, $custodyId, $input): string {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$project->id]);
                $project = Project::whereKey($project->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                $policy = $this->custody->policyId($project, $actor, 'receiving_qc');
                abort_unless($policy !== null, 403);
                $this->archives->assertWritable($project);
                $receipt = $this->custody->detail($project, $actor, $custodyId);
                app(PipingGrnService::class)->assertUnposted($project, $custodyId);
                if ($receipt->created_by === $actor->id) {
                    $this->fail('self_review');
                }
                $data = Validator::make(['review' => $input], [
                    'review' => ['required', 'array:amendment_id,expected_policy_id,previous_review_id,request_id,reviewed_at,observed_result,findings'],
                    'review.expected_policy_id' => ['required', 'uuid'], 'review.previous_review_id' => ['present', 'nullable', 'uuid'],
                    'review.amendment_id' => ['nullable', 'uuid'], 'review.request_id' => ['required', 'uuid'],
                    'review.reviewed_at' => ['required', 'string', 'regex:/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-][0-9]{2}:[0-9]{2}\z/'],
                    'review.observed_result' => ['required', 'in:'.implode(',', self::RESULTS)],
                    'review.findings' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
                ], array_fill_keys(['required', 'present', 'array', 'uuid', 'string', 'regex', 'in', 'max'], __('piping_receiving_qc.invalid')))->validate()['review'];
                $data['amendment_id'] = $data['amendment_id'] ?? null;
                if (str_contains($data['findings'], "\0")) {
                    $this->fail();
                }
                $data['findings'] = trim($data['findings']);
                if ($data['expected_policy_id'] !== $policy) {
                    $this->fail('policy_stale');
                }
                try {
                    $reviewed = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $data['reviewed_at']);
                } catch (\Throwable) {
                    $this->fail();
                }
                if (! $reviewed || $reviewed->format('Y-m-d\TH:i:sP') !== $data['reviewed_at']) {
                    $this->fail();
                }
                $data['reviewed_at'] = $reviewed->utc()->format('Y-m-d H:i:sP');
                $existing = DB::table('piping_receiving_reviews')->where('project_id', $project->id)->where('request_id', $data['request_id'])->first();
                if ($existing) {
                    if ($existing->custody_id !== $custodyId || $existing->company_id !== $actor->company_id || $existing->reviewed_by !== $actor->id || $existing->policy_id !== $policy
                        || ($existing->amendment_id ?? null) !== $data['amendment_id'] || $existing->previous_review_id !== $data['previous_review_id'] || $existing->observed_result !== $data['observed_result'] || $existing->findings !== $data['findings']
                        || CarbonImmutable::parse($existing->reviewed_at)->utc()->format('Y-m-d H:i:sP') !== $data['reviewed_at']) {
                        $this->fail('retry_conflict');
                    }

                    return $existing->id;
                }
                $basis = $this->custody->basis($project, $actor, $custodyId);
                abort_unless($basis !== null, 403);
                if ($data['amendment_id'] !== $basis['amendment_id']) {
                    $this->fail('amendment_stale');
                }
                $latest = DB::table('piping_receiving_reviews')->where('project_id', $project->id)->where('custody_id', $custodyId)->orderByDesc('sequence')->first();
                if ($data['previous_review_id'] !== ($latest->id ?? null)) {
                    $this->fail('stale');
                }
                unset($data['expected_policy_id']);
                $id = (string) Str::uuid();
                DB::table('piping_receiving_reviews')->insert($data + ['id' => $id, 'project_id' => $project->id, 'custody_id' => $custodyId, 'company_id' => $actor->company_id, 'reviewed_by' => $actor->id, 'policy_id' => $policy]);

                return $id;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'PIPING_AMENDMENT_SOURCE')) {
                abort(403);
            }
            if (str_contains($e->getMessage(), 'PIPING_AMENDMENT_STALE')) {
                $this->fail('amendment_stale');
            }
            if ((string) $e->getCode() === '23505') {
                $this->fail('retry_conflict');
            }
            if (str_contains($e->getMessage(), 'PIPING_RECEIVING_QC_SELF_REVIEW')) {
                $this->fail('self_review');
            }
            if (str_contains($e->getMessage(), 'PIPING_RECEIVING_QC_STALE')) {
                $this->fail('stale');
            }
            if (str_contains($e->getMessage(), 'PIPING_RECEIVING_QC_RECEIPT')) {
                abort(404);
            }
            if (str_contains($e->getMessage(), 'PIPING_RECEIVING_QC_NOMINATION') || str_contains($e->getMessage(), 'PIPING_RECEIVING_QC_SCOPE')) {
                abort(403);
            }
            throw $e;
        }
    }

    private function fail(string $key = 'invalid'): never
    {
        throw ValidationException::withMessages(['review' => __('piping_receiving_qc.'.$key)]);
    }
}
