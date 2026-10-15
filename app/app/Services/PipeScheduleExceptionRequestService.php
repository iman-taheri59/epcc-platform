<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Component;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\Reference\PipeReference;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Capture a schedule difference, never approve it. */
final class PipeScheduleExceptionRequestService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuthorizationPipeline $authz,
        private readonly ProjectArchiveService $archives,
        private readonly PipeReference $reference,
        private readonly PmsPinnedClassSpecification $issuedPms,
    ) {}

    public function submit(Project $project, User $actor, string $componentId, string $reason): object
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $componentId, $reason): object {
            $this->archives->assertWritable($project);
            abort_unless($this->authz->check($actor, 'engineering.report.capture', $project, ['discipline' => 'PIPING']) === null, 403);
            $data = Validator::make(['component_id' => $componentId, 'reason' => trim($reason)], [
                'component_id' => ['required', 'uuid'],
                'reason' => ['required', 'string', 'min:10', 'max:4000'],
            ])->validate();

            return DB::transaction(function () use ($project, $actor, $data): object {
                $component = Component::query()->whereKey($data['component_id'])
                    ->where('project_id', $project->id)
                    ->where('company_id', $actor->company_id)
                    ->lockForUpdate()->firstOrFail();
                $line = PipingLine::query()->whereKey($component->piping_line_id)
                    ->where('project_id', $project->id)
                    ->where('company_id', $actor->company_id)
                    ->lockForUpdate()->firstOrFail();
                if ($component->template_code !== 'PIPE' || $component->schedule === null
                    || $line->pms_class_snapshot_id === null || $component->nominal_size === null) {
                    throw ValidationException::withMessages([
                        'component_id' => 'A sized PIPE with a stored schedule and issued PMS pin is required.',
                    ]);
                }
                $size = $this->reference->resolveLabel($component->nominal_size);
                if ($size === null) {
                    throw ValidationException::withMessages(['component_id' => 'The pipe size cannot be resolved.']);
                }
                $expected = $this->issuedPms->pipeSchedule($line, $size->dn);
                if ($component->schedule === $expected) {
                    throw ValidationException::withMessages([
                        'component_id' => 'The pipe schedule matches the issued PMS; there is no discrepancy to request.',
                    ]);
                }
                $pending = DB::table('pipe_schedule_exception_requests as r')
                    ->leftJoin('pipe_schedule_exception_decisions as d', 'd.request_id', '=', 'r.id')
                    ->where('r.component_id', $component->id)
                    ->where('r.project_id', $project->id)
                    ->where('r.pms_class_snapshot_id', $line->pms_class_snapshot_id)
                    ->where('r.actual_schedule', $component->schedule)
                    ->whereNull('d.id')->exists();
                if ($pending) {
                    throw ValidationException::withMessages([
                        'component_id' => 'An unresolved request already exists for this pipe schedule.',
                    ]);
                }

                $id = (string) Str::uuid();
                DB::table('pipe_schedule_exception_requests')->insert([
                    'id' => $id, 'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'component_id' => $component->id,
                    'pms_class_snapshot_id' => $line->pms_class_snapshot_id,
                    'expected_schedule' => $expected,
                    'actual_schedule' => $component->schedule,
                    'reason' => $data['reason'], 'requested_by' => $actor->id,
                    'created_at' => now(),
                ]);
                return DB::table('pipe_schedule_exception_requests')->where('id', $id)->first();
            });
        }, $project->id);
    }
}
