<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Capture provenance ONLY. An internal draft is not issued; external metadata
 * is not a verified signature, answered TQ, NCR disposition or approval.
 */
final class PipeScheduleReportService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly ProjectArchiveService $archives,
        private readonly PmsPinnedClassSpecification $issuedPms,
        private readonly \App\Support\Reference\PipeReference $reference,
    ) {}

    /** @return object The newly recorded, unissued TQ/NCR draft. */
    public function draft(Project $project, User $actor, string $requestId, string $kind): object
    {
        return $this->record($project, $actor, $requestId, $kind, 'IN_SYSTEM', []);
    }

    /**
     * Metadata-only registration of a report issued and signed elsewhere.
     * No file upload or document revision is required.
     *
     * @param array<string, mixed> $metadata
     * @return object
     */
    public function registerExternal(Project $project, User $actor, string $requestId, string $kind, array $metadata): object
    {
        return $this->record($project, $actor, $requestId, $kind, 'EXTERNAL_REGISTER', $metadata);
    }

    private function record(Project $project, User $actor, string $requestId, string $kind, string $origin, array $input): object
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $requestId, $kind, $origin, $input): object {
            $this->archives->assertWritable($project);
            abort_unless($this->authz->check($actor, 'engineering.report.capture', $project, ['discipline' => 'PIPING']) === null, 403);
            $base = Validator::make(['request_id' => $requestId, 'kind' => $kind], [
                'request_id' => ['required', 'uuid'],
                'kind' => ['required', 'in:TQ,NCR'],
            ])->validate();
            $data = $origin === 'EXTERNAL_REGISTER' ? Validator::make($input, [
                'report_number' => ['required', 'string', 'min:2', 'max:160'],
                'report_revision' => ['nullable', 'string', 'max:64'],
                'reported_issuer' => ['required', 'string', 'min:2', 'max:200'],
                'reported_signer' => ['required', 'string', 'min:2', 'max:200'],
                'reported_signer_capacity' => ['required', 'string', 'min:2', 'max:200'],
                'reported_issued_at' => ['required', 'date'],
                'reported_signed_at' => ['required', 'date'],
                'external_register_ref' => ['required', 'string', 'min:2', 'max:512'],
            ])->validate() : [];
            foreach (['report_number', 'reported_issuer', 'reported_signer', 'reported_signer_capacity', 'external_register_ref'] as $key) {
                if (isset($data[$key])) {
                    $data[$key] = trim($data[$key]);
                }
            }
            if ($origin === 'EXTERNAL_REGISTER') {
                Validator::make($data, [
                    'report_number' => ['min:2'], 'reported_issuer' => ['min:2'],
                    'reported_signer' => ['min:2'], 'reported_signer_capacity' => ['min:2'],
                    'external_register_ref' => ['min:2'],
                ])->validate();
            }
            return DB::transaction(function () use ($project, $actor, $base, $origin, $data): object {
                // Company check is deliberate: a participant cannot create a
                // report pretending to be the originating tenant of another
                // company's discrepancy. This is not a sign-off policy.
                $request = DB::table('pipe_schedule_exception_requests')
                    ->where('id', $base['request_id'])->where('project_id', $project->id)
                    ->where('company_id', $actor->company_id)->lockForUpdate()->first();
                abort_if($request === null, 404);
                if ($origin === 'IN_SYSTEM') {
                    // A newly authored internal draft must describe the
                    // component as it is NOW, not an old mismatch or a forged
                    // request. Old externally issued reports may be registered
                    // retrospectively, but remain unverified metadata.
                    $component = Component::query()->whereKey($request->component_id)
                        ->where('project_id', $project->id)->where('company_id', $actor->company_id)
                        ->lockForUpdate()->firstOrFail();
                    $line = PipingLine::query()->whereKey($component->piping_line_id)
                        ->where('project_id', $project->id)->where('company_id', $actor->company_id)
                        ->lockForUpdate()->firstOrFail();
                    $size = $component->nominal_size !== null
                        ? $this->reference->resolveLabel($component->nominal_size) : null;
                    if ($component->template_code !== 'PIPE' || $size === null
                        || $line->pms_class_snapshot_id !== $request->pms_class_snapshot_id
                        || $component->schedule !== $request->actual_schedule
                        || $request->actual_schedule === $request->expected_schedule
                        || $this->issuedPms->pipeSchedule($line, $size->dn) !== $request->expected_schedule) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'request_id' => 'This schedule request no longer matches the issued PMS and current pipe. Capture a fresh discrepancy before drafting a report.',
                        ]);
                    }
                    if (DB::table('pipe_schedule_discrepancy_reports')
                        ->where('request_id', $request->id)->where('kind', $base['kind'])
                        ->where('origin', 'IN_SYSTEM')->exists()) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'kind' => 'An internal draft of this report kind already exists for the request.',
                        ]);
                    }
                }
                $id = (string) Str::uuid();
                DB::table('pipe_schedule_discrepancy_reports')->insert([
                    'id' => $id, 'request_id' => $request->id,
                    'project_id' => $project->id, 'company_id' => $actor->company_id,
                    'kind' => $base['kind'], 'origin' => $origin,
                    'state' => $origin === 'IN_SYSTEM' ? 'DRAFT' : 'EXTERNAL_RECORDED',
                    'recorded_by' => $actor->id, 'created_at' => now(),
                ] + $data);
                return DB::table('pipe_schedule_discrepancy_reports')->where('id', $id)->first();
            });
        }, $project->id);
    }
}
