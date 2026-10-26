<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\InspectionNotificationInput;
use App\Support\InspectionNotificationPrerequisiteStage;
use App\Support\InspectionNotificationSchedule;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** ADR-042. In-system notice, never an inspection result, release or delivery receipt. */
final class InspectionNotificationService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $access,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
    ) {}

    public function read(Project $project, User $actor, callable $callback): mixed
    {
        $this->access->assertAccess($project, $actor);

        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $callback) {
            $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->firstOrFail();
            abort_unless($fresh->company_id === $actor->company_id && $fresh->company->plan?->hasModule('qaqc')
                && $fresh->company->plan->allowsPack($project->standards_pack), 403);
            $membership = ProjectMembership::where('project_id', $project->id)->where('user_id', $fresh->id)->firstOrFail();
            abort_unless(DB::table('project_company')->where('project_id', $project->id)->where('company_id', $fresh->company_id)->exists(), 404);

            return $callback($fresh, $membership);
        }, $project->id);
    }

    private function write(Project $project, User $actor, callable $callback): mixed
    {
        try {
            return $this->read($project, $actor, fn (User $fresh, ProjectMembership $membership) => DB::transaction(function () use ($project, $fresh, $membership, $callback) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-notification:'.$project->id]);
                // Serialize archive/header changes without blocking child FK KEY SHARE locks.
                // FOR UPDATE here can deadlock concurrent document-revision insertion.
                $locked = Project::whereKey($project->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                $this->archives->assertWritable($locked);

                return $callback($fresh, $membership, $locked);
            }));
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'NOTIFICATION_') || $e->getCode() === '23505') {
                throw ValidationException::withMessages(['notification' => __('inspection_notifications.refused')]);
            }
            throw $e;
        }
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['notification' => __('inspection_notifications.'.$key)]);
    }

    public function canConfigure(Project $project, User $actor): bool
    {
        return $this->archives->isWritable($project) && $this->authz->allows($actor, 'project.configure', $project);
    }

    public function latest(Project $project): ?object
    {
        return DB::table('inspection_notification_settings')->where('project_id', $project->id)->orderByDesc('version')->first();
    }

    /** Atomic notification permissions, not global role edits or inspection-result nomination. */
    public function configure(Project $project, User $actor, array $input): void
    {
        $this->write($project, $actor, function (User $fresh, $member, Project $locked) use ($input): void {
            abort_unless($this->canConfigure($locked, $fresh), 403);
            $d = Validator::make(['settings' => $input], [
                'settings' => ['required', 'array:version,main_company_id,draft_users,issue_users,reason'],
                'settings.version' => ['required', 'integer', 'min:0'], 'settings.main_company_id' => ['required', 'uuid'],
                'settings.draft_users' => ['sometimes', 'array', 'max:100'], 'settings.issue_users' => ['sometimes', 'array', 'max:100'],
                'settings.draft_users.*' => ['required', 'uuid', 'distinct'], 'settings.issue_users.*' => ['required', 'uuid', 'distinct'],
                'settings.reason' => ['required', 'string', 'max:4000'],
            ])->validate()['settings'];
            if ((int) $d['version'] !== (int) ($this->latest($locked)->version ?? 0)) {
                $this->fail('stale');
            }
            abort_unless(DB::table('project_company')->join('companies', 'companies.id', '=', 'project_company.company_id')
                ->where('project_id', $locked->id)->where('companies.id', $d['main_company_id'])->where('tenant_type', 'EPC')->sharedLock()->exists(), 422);
            $ids = $this->tenant->forIdentityLookup(fn () => User::where('company_id', $d['main_company_id'])->where('is_active', true)
                ->whereIn('id', ProjectMembership::where('project_id', $locked->id)->pluck('user_id'))->pluck('id')->all());
            foreach (array_merge($d['draft_users'] ?? [], $d['issue_users'] ?? []) as $id) {
                if (! in_array($id, $ids, true)) {
                    $this->fail('recipient_invalid');
                }
            }
            DB::table('inspection_notification_settings')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $locked->id, 'company_id' => $fresh->company_id,
                'main_company_id' => $d['main_company_id'], 'version' => (int) $d['version'] + 1,
                'draft_users' => json_encode($d['draft_users'] ?? [], JSON_THROW_ON_ERROR), 'issue_users' => json_encode($d['issue_users'] ?? [], JSON_THROW_ON_ERROR),
                'reason' => trim($d['reason']), 'created_by' => $fresh->id, 'created_at' => now(),
            ]);
        });
    }

    private function scoped($query, ProjectMembership $member, string $column = 'discipline')
    {
        return is_array($member->discipline_scope) ? $query->whereIn($column, $member->discipline_scope) : $query;
    }

    private function revision(Project $project, User $actor, string $id, bool $lock = true): array
    {
        $q = DocumentRevision::where('project_id', $project->id)->whereKey($id);
        $rev = ($lock ? $q->sharedLock() : $q)->firstOrFail();
        $q = Document::where('project_id', $project->id)->where('doc_type', 'ITP')->whereKey($rev->document_id);
        $doc = ($lock ? $q->sharedLock() : $q)->firstOrFail();
        abort_unless($this->authz->allows($actor, 'doc.view', $project, ['discipline' => $doc->discipline]), 403);
        if ($rev->cde_state === 'ARCHIVED') {
            abort_if(! $lock, 404);
            $this->fail('archived_revision');
        }

        return [$rev, $doc];
    }

    public function addStage(Project $project, User $actor, array $input): string
    {
        return $this->write($project, $actor, function (User $fresh, $member, Project $locked) use ($input): string {
            $d = Validator::make(['stage' => $input], [
                'stage' => ['required', 'array:revision_id,sequence,point,requires_notification,description_en,description_fa'],
                'stage.revision_id' => ['required', 'uuid'], 'stage.sequence' => ['required', 'integer', 'min:1', 'max:2147483647'],
                'stage.point' => ['required', Rule::in(InspectionNotificationInput::POINTS)], 'stage.requires_notification' => ['required', 'boolean'],
                'stage.description_en' => ['nullable', 'string', 'max:8000'], 'stage.description_fa' => ['nullable', 'string', 'max:8000'],
            ])->validate()['stage'];
            [$rev, $doc] = $this->revision($locked, $fresh, $d['revision_id']);
            abort_unless($doc->company_id === $fresh->company_id && $this->authz->allows($fresh, 'doc.issue', $locked, ['discipline' => $doc->discipline]), 403);
            if (trim(($d['description_en'] ?? '').($d['description_fa'] ?? '')) === '') {
                $this->fail('description_required');
            }
            $id = (string) Str::uuid();
            DB::table('inspection_itp_stages')->insert([
                'id' => $id, 'project_id' => $locked->id, 'company_id' => $fresh->company_id,
                'revision_id' => $rev->id, 'discipline' => $doc->discipline, 'sequence' => $d['sequence'], 'point' => $d['point'],
                'requires_notification' => (bool) $d['requires_notification'], 'description_en' => $d['description_en'] ?? null,
                'description_fa' => $d['description_fa'] ?? null, 'created_by' => $fresh->id, 'created_at' => now(),
            ]);

            return $id;
        });
    }

    public function permission(Project $project, User $actor, string $purpose, string $discipline): ?string
    {
        return DB::selectOne('SELECT epcc_notification_setting(?,?,?,?,?) AS id', [$project->id, $actor->id, $actor->company_id, $purpose, $discipline])->id;
    }

    public function stage(Project $project, User $actor, ProjectMembership $member, string $id): array
    {
        $s = $this->scoped(DB::table('inspection_itp_stages')->where('project_id', $project->id)->where('id', $id), $member)->firstOrFail();
        [$rev, $doc] = $this->revision($project, $actor, $s->revision_id, false);

        return [$s, $rev, $doc];
    }

    private function references(Project $project, User $actor, ProjectMembership $member, string $stageId, array $recipients, ?string $poId): array
    {
        [$s, $rev, $doc] = $this->stage($project, $actor, $member, $stageId);
        // Hold source rows through commit, including source-state archival changes.
        [$rev, $doc] = $this->revision($project, $actor, $rev->id);
        if ($doc->discipline !== $s->discipline) {
            $this->fail('stale');
        }
        if (! $s->requires_notification) {
            $this->fail('not_required');
        }
        $parties = DB::table('project_company')->join('companies', 'companies.id', '=', 'project_company.company_id')
            ->join('plans', 'plans.id', '=', 'companies.plan_id')
            ->where('project_id', $project->id)->whereIn('companies.id', $recipients)
            ->whereRaw("(jsonb_exists(plans.modules::jsonb,'qaqc') OR jsonb_exists(plans.modules::jsonb,'*'))")
            ->whereRaw("(jsonb_exists(plans.standards_packs::jsonb,?) OR jsonb_exists(plans.standards_packs::jsonb,'*'))", [$project->standards_pack])
            ->select('companies.id', 'companies.code', 'companies.name_en', 'companies.name_fa')->sharedLock()->get();
        if ($parties->count() !== count($recipients)) {
            $this->fail('recipient_invalid');
        }
        if ($poId !== null) {
            PurchaseOrder::where('project_id', $project->id)->where('discipline', $s->discipline)->whereKey($poId)->sharedLock()->firstOrFail();
        }

        return [$s, $rev, $doc, $parties];
    }

    public function createDraft(Project $project, User $actor, array $input): string
    {
        return $this->write($project, $actor, function (User $fresh, ProjectMembership $member, Project $locked) use ($input): string {
            $d = Validator::make(['notification' => $input], InspectionNotificationInput::draftRules())->validate()['notification'];
            [$s] = $this->references($locked, $fresh, $member, $d['itp_stage_id'], $d['recipient_company_ids'], $d['purchase_order_id'] ?? null);
            if ($s->revision_id !== $d['itp_revision_id']) {
                $this->fail('stale');
            }
            $setting = $this->permission($locked, $fresh, 'draft', $s->discipline);
            abort_unless($setting !== null, 403);
            $id = (string) Str::uuid();
            DB::table('inspection_notifications')->insert([
                'id' => $id, 'project_id' => $locked->id, 'company_id' => $fresh->company_id,
                'created_by' => $fresh->id, 'stage_id' => $s->id, 'discipline' => $s->discipline,
                'entry_setting_id' => $setting, 'state' => 'DRAFT', 'version' => 1,
                'work_reference' => $d['work_reference'], 'location' => $d['location'], 'scheduled_input' => null,
                'scheduled_at' => null,
                'activity_start_input' => $d['activity_start_at'], 'activity_end_input' => $d['activity_end_at'],
                'activity_start_at' => CarbonImmutable::parse($d['activity_start_at'])->utc(), 'activity_end_at' => CarbonImmutable::parse($d['activity_end_at'])->utc(), 'purchase_order_id' => $d['purchase_order_id'] ?? null,
                'recipient_company_ids' => json_encode($d['recipient_company_ids'], JSON_THROW_ON_ERROR),
                'notes_en' => $d['notes_en'] ?? null, 'notes_fa' => $d['notes_fa'] ?? null, 'created_at' => now(),
            ]);

            return $id;
        });
    }

    public function editContext(Project $project, User $actor, ProjectMembership $member, string $id): array
    {
        $notice = $this->scoped(DB::table('inspection_notifications')->where('project_id', $project->id)->where('company_id', $actor->company_id)->where('id', $id), $member)->firstOrFail();
        if ($notice->state !== 'DRAFT') {
            $this->fail('stale');
        }
        abort_unless($this->archives->isWritable($project) && $this->permission($project, $actor, 'draft', $notice->discipline) !== null, 403);
        [$stage, $revision] = $this->stage($project, $actor, $member, $notice->stage_id);
        $companies = DB::table('project_company')->join('companies', 'companies.id', '=', 'project_company.company_id')->join('plans', 'plans.id', '=', 'companies.plan_id')
            ->where('project_id', $project->id)->whereRaw("(jsonb_exists(plans.modules::jsonb,'qaqc') OR jsonb_exists(plans.modules::jsonb,'*'))")
            ->whereRaw("(jsonb_exists(plans.standards_packs::jsonb,?) OR jsonb_exists(plans.standards_packs::jsonb,'*'))", [$project->standards_pack])
            ->select('companies.id', 'companies.code', 'companies.name_en', 'companies.name_fa')->orderBy('companies.code')->get();

        return compact('notice', 'stage', 'revision', 'companies');
    }

    public function updateDraft(Project $project, User $actor, string $id, array $input): void
    {
        $this->write($project, $actor, function (User $fresh, ProjectMembership $member, Project $locked) use ($id, $input): void {
            $d = Validator::make(['notification' => $input], InspectionNotificationInput::editRules())->validate()['notification'];
            $row = $this->scoped(DB::table('inspection_notifications')->where('project_id', $locked->id)->where('company_id', $fresh->company_id)->where('id', $id), $member)->lockForUpdate()->firstOrFail();
            if ($row->state !== 'DRAFT' || $row->version !== (int) $d['version']) {
                $this->fail('stale');
            }
            $setting = $this->permission($locked, $fresh, 'draft', $row->discipline);
            abort_unless($setting !== null, 403);
            $this->references($locked, $fresh, $member, $row->stage_id, $d['recipient_company_ids'], $d['purchase_order_id'] ?? null);
            DB::table('inspection_notifications')->where('id', $row->id)->update([
                'version' => $row->version + 1, 'work_reference' => $d['work_reference'], 'location' => $d['location'],
                'scheduled_input' => null, 'scheduled_at' => null,
                'activity_start_input' => $d['activity_start_at'], 'activity_end_input' => $d['activity_end_at'],
                'activity_start_at' => CarbonImmutable::parse($d['activity_start_at'])->utc(), 'activity_end_at' => CarbonImmutable::parse($d['activity_end_at'])->utc(),
                'recipient_company_ids' => json_encode($d['recipient_company_ids'], JSON_THROW_ON_ERROR),
                'purchase_order_id' => $d['purchase_order_id'] ?? null, 'notes_en' => $d['notes_en'] ?? null, 'notes_fa' => $d['notes_fa'] ?? null,
                'last_edited_by' => $fresh->id, 'last_edit_setting_id' => $setting, 'last_edited_at' => now(),
            ]);
        });
    }

    public function issue(Project $project, User $actor, string $id, array $input): void
    {
        $this->write($project, $actor, function (User $fresh, ProjectMembership $member, Project $locked) use ($id, $input): void {
            $d = Validator::make(['issuance' => $input], InspectionNotificationInput::issueRules())->validate()['issuance'];
            $row = $this->scoped(DB::table('inspection_notifications')->where('project_id', $locked->id)->where('company_id', $fresh->company_id)->where('id', $id), $member)->lockForUpdate()->firstOrFail();
            if ($row->state !== 'DRAFT' || $row->version !== (int) $d['version']) {
                $this->fail('stale');
            }
            if (! InspectionNotificationSchedule::hasWindow($row)) {
                $this->fail('activity_window_required');
            }
            [$s, $rev, $doc, $recipients] = $this->references($locked, $fresh, $member, $row->stage_id, json_decode($row->recipient_company_ids, true, 512, JSON_THROW_ON_ERROR), $row->purchase_order_id);
            $setting = $this->permission($locked, $fresh, 'issue', $s->discipline);
            abort_unless($setting !== null, 403);
            $sequence = (int) DB::table('inspection_notifications')->where('project_id', $locked->id)->where('company_id', $fresh->company_id)->max('sequence') + 1;
            if ($sequence < 1 || $sequence > 2147483647) {
                $this->fail('refused');
            }
            $snapshot = [
                'project_id' => $locked->id, 'project_code' => $locked->code, 'project_name_en' => $locked->name_en, 'project_name_fa' => $locked->name_fa,
                'document_id' => $doc->id, 'document_number' => $doc->number, 'document_title_en' => $doc->title_en, 'document_title_fa' => $doc->title_fa,
                'revision_id' => $rev->id, 'revision' => $rev->revision, 'cde_state' => $rev->cde_state, 'suitability' => $rev->suitability,
                'stage_id' => $s->id, 'stage_sequence' => $s->sequence, 'point' => $s->point, 'description_en' => $s->description_en, 'description_fa' => $s->description_fa,
                'issuer_id' => $fresh->id, 'issuer_name' => $fresh->name, 'sender_code' => $fresh->company->code,
                'sender_name_en' => $fresh->company->name_en, 'sender_name_fa' => $fresh->company->name_fa,
                'recipients' => $recipients->all(),
            ];
            DB::table('inspection_notifications')->where('id', $row->id)->update([
                'state' => 'ISSUED', 'version' => $row->version + 1, 'sequence' => $sequence, 'number' => sprintf('%s-IN-%s-%06d', $locked->code, $fresh->company->code, $sequence),
                'issued_by' => $fresh->id, 'issued_at' => now(), 'issue_setting_id' => $setting,
                'issued_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        });
    }

    /** Withdraw an issued notice; never mutate its issued body or reopen a draft. */
    public function cancel(Project $project, User $actor, string $id, array $input): void
    {
        $this->write($project, $actor, function (User $fresh, ProjectMembership $member, Project $locked) use ($id, $input): void {
            $d = Validator::make(['cancellation' => $input], [
                'cancellation' => ['required', 'array:version,reason'],
                'cancellation.version' => ['required', 'integer', 'min:2'],
                'cancellation.reason' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
            ])->validate()['cancellation'];
            $row = $this->scoped(DB::table('inspection_notifications')->where('project_id', $locked->id)->where('company_id', $fresh->company_id)->where('id', $id), $member)->lockForUpdate()->firstOrFail();
            if ($row->state !== 'ISSUED' || $row->version !== (int) $d['version'] || $this->cancellation($locked, $member, $row) !== null) {
                $this->fail('stale');
            }
            $setting = $this->permission($locked, $fresh, 'issue', $row->discipline);
            abort_unless($setting !== null, 403);
            // Existing issued evidence suffices to withdraw it. Do not require a
            // still-live source/PO/recipient or disclose private draft revisions.
            DB::table('inspection_notification_cancellations')->insert([
                'project_id' => $locked->id, 'company_id' => $fresh->company_id, 'notification_id' => $row->id,
                'discipline' => $row->discipline, 'issued_version' => $row->version, 'actor_id' => $fresh->id,
                'actor_name' => '', 'cancel_setting_id' => $setting, 'reason' => trim($d['reason']), 'recorded_at' => now(),
            ]);
        });
    }

    public function cancellation(Project $project, ProjectMembership $member, object $notice): ?object
    {
        abort_unless($notice->project_id === $project->id, 404);

        return $this->scoped(DB::table('inspection_notification_cancellations')->where('project_id', $project->id)->where('notification_id', $notice->id), $member)->first();
    }

    /** Explicit company receipt by any currently authorized selected-recipient reader. */
    public function acknowledge(Project $project, User $actor, string $id, array $input): void
    {
        $this->write($project, $actor, function (User $fresh, ProjectMembership $member, Project $locked) use ($id, $input): void {
            $d = Validator::make(['acknowledgment' => $input], [
                'acknowledgment' => ['required', 'array:version,event_kind,cancellation_id'],
                'acknowledgment.version' => ['required', 'integer', 'min:2'],
                'acknowledgment.event_kind' => ['required', Rule::in(['ISSUE', 'CANCELLATION'])],
                'acknowledgment.cancellation_id' => ['nullable', 'uuid', 'required_if:acknowledgment.event_kind,CANCELLATION', 'prohibited_if:acknowledgment.event_kind,ISSUE'],
            ])->validate()['acknowledgment'];
            $notice = $this->notification($locked, $member, $id);
            if ($notice->state !== 'ISSUED' || $notice->version !== (int) $d['version']) {
                $this->fail('stale');
            }
            abort_unless(in_array($fresh->company_id, json_decode($notice->recipient_company_ids, true, 512, JSON_THROW_ON_ERROR), true), 403);
            $cancellation = $this->cancellation($locked, $member, $notice);
            if (($d['event_kind'] === 'ISSUE' && $cancellation !== null)
                || ($d['event_kind'] === 'CANCELLATION' && ($cancellation === null || $cancellation->id !== ($d['cancellation_id'] ?? null)))
                || $this->acknowledgments($locked, $member, $notice)->contains(fn ($e) => $e->company_id === $fresh->company_id && $e->event_kind === $d['event_kind'])) {
                $this->fail('stale');
            }
            // Only the new recipient-owned event is written. SQL captures the
            // actor/company names and clock; no sender UPDATE/source read grant.
            DB::table('inspection_notification_acknowledgments')->insert([
                'project_id' => $locked->id, 'company_id' => $fresh->company_id, 'notification_id' => $notice->id,
                'issued_version' => $notice->version, 'event_kind' => $d['event_kind'], 'cancellation_id' => $d['cancellation_id'] ?? null,
                'actor_id' => $fresh->id,
            ]);
        });
    }

    public function acknowledgments(Project $project, ProjectMembership $member, object $notice): Collection
    {
        abort_unless($notice->project_id === $project->id, 404);
        if ($notice->state !== 'ISSUED') {
            return collect();
        }

        return $this->scoped(DB::table('inspection_notification_acknowledgments')->where('project_id', $project->id)->where('notification_id', $notice->id), $member)
            ->orderBy('recorded_at')->orderBy('id')->get();
    }

    public function acknowledgmentContext(Project $project, User $actor, ProjectMembership $member, object $notice, ?object $cancellation): array
    {
        $acknowledgments = $this->acknowledgments($project, $member, $notice);
        $ackKind = $cancellation === null ? 'ISSUE' : 'CANCELLATION';
        $canAcknowledge = $notice->state === 'ISSUED' && $this->archives->isWritable($project)
            && in_array($actor->company_id, json_decode($notice->recipient_company_ids, true, 512, JSON_THROW_ON_ERROR), true)
            && ! $acknowledgments->contains(fn ($e) => $e->company_id === $actor->company_id && $e->event_kind === $ackKind);

        return compact('acknowledgments', 'ackKind', 'canAcknowledge');
    }

    private function activePrerequisiteNotice(Project $project, ProjectMembership $member, string $id): object
    {
        $notice = $this->notification($project, $member, $id);
        if ($notice->state !== 'ISSUED' || $this->cancellation($project, $member, $notice) !== null) {
            $this->fail('stale');
        }

        return $notice;
    }

    /** Read-only project register: same original parent RLS and current reader discipline. */
    public function prerequisiteRegister(Project $project, ProjectMembership $member, array $filters, string $lang): array
    {
        $q = $this->scoped(DB::table('inspection_notification_prerequisites as p')
            ->join('inspection_notifications as n', function ($join): void {
                $join->on('n.id', '=', 'p.notification_id')->on('n.project_id', '=', 'p.project_id')->on('n.version', '=', 'p.issued_version')->on('n.discipline', '=', 'p.discipline');
            })->where('p.project_id', $project->id)->where('n.project_id', $project->id)->where('n.state', 'ISSUED'), $member, 'p.discipline');
        $prerequisiteVisibleTotal = (clone $q)->count();
        // One latest-created visible declaration snapshot per stable company identity.
        // Never join a live company/people directory to populate this picker.
        $representatives = (clone $q)->select('p.company_id', 'p.company_code', 'p.company_name_en', 'p.company_name_fa')
            ->distinct('p.company_id')->orderBy('p.company_id')->orderByDesc('p.created_at')->orderBy('p.id');
        $companyQuery = DB::query()->fromSub($representatives, 'recorded_companies');
        $selectedPrerequisiteCompany = $filters['prerequisite_company'] !== ''
            ? (clone $companyQuery)->where('company_id', $filters['prerequisite_company'])->first() : null;
        if ($filters['prerequisite_company_q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['prerequisite_company_q']).'%';
            $companyQuery->where(fn ($c) => $c->whereRaw("company_code ILIKE ? ESCAPE '!'", [$term])
                ->orWhereRaw("company_name_en ILIKE ? ESCAPE '!'", [$term])->orWhereRaw("company_name_fa ILIKE ? ESCAPE '!'", [$term]));
        }

        // Lifecycle filter is read-only: absence of a cancellation does not imply readiness
        // or a writable project. Keep the visible total and company picker scope unchanged.
        $cancelledNotice = DB::table('inspection_notification_cancellations as c')->selectRaw('1')
            ->whereColumn('c.notification_id', 'n.id')->whereColumn('c.project_id', 'n.project_id');
        if ($filters['prerequisite_notice_state'] === 'ACTIVE') {
            $q->whereNotExists($cancelledNotice);
        } elseif ($filters['prerequisite_notice_state'] === 'CANCELLED') {
            $q->whereExists($cancelledNotice);
        }

        if ($filters['prerequisite_state'] !== '') {
            $q->where('p.state', $filters['prerequisite_state']);
        }
        if ($filters['prerequisite_company'] !== '') {
            $q->where('p.company_id', $filters['prerequisite_company']);
        }
        if ($filters['prerequisite_notice'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['prerequisite_notice']).'%';
            $q->whereRaw("n.number ILIKE ? ESCAPE '!'", [$term]);
        }
        $prerequisites = $q->select('p.*', 'n.number as notice_number')->selectSub(
            DB::table('inspection_notification_cancellations as c')->select('c.id')->whereColumn('c.notification_id', 'n.id')->whereColumn('c.project_id', 'n.project_id'), 'cancellation_id')
            ->orderByDesc('p.latest_at')->orderBy('p.id')->paginate(25, ['*'], 'prerequisite_register_page')
            ->appends(array_filter($filters, static fn ($v) => $v !== ''))->appends(['lang' => $lang]);

        $prerequisiteCompanies = $companyQuery->orderBy('company_code')->orderBy('company_id')
            ->paginate(25, ['*'], 'prerequisite_company_page')
            ->appends(array_filter($filters, static fn ($v) => $v !== ''))->appends(['lang' => $lang]);
        if ($prerequisites->currentPage() > 1) {
            $prerequisiteCompanies->appends(['prerequisite_register_page' => $prerequisites->currentPage()]);
        }
        if ($prerequisiteCompanies->currentPage() > 1) {
            $prerequisites->appends(['prerequisite_company_page' => $prerequisiteCompanies->currentPage()]);
        }

        return compact('prerequisites', 'prerequisiteVisibleTotal', 'filters', 'prerequisiteCompanies', 'selectedPrerequisiteCompany');
    }

    /** Full current appendix, independent of web filters/pagination. Original-reader RLS only. */
    public function prerequisitePrintContext(Project $project, ProjectMembership $member, object $notice): array
    {
        $records = $this->scoped(DB::table('inspection_notification_prerequisites')
            ->where('project_id', $project->id)->where('notification_id', $notice->id), $member)
            ->orderBy('created_at')->orderBy('id')->get();
        $events = DB::table('inspection_notification_prerequisite_events')
            ->where('project_id', $project->id)->whereIn('prerequisite_id', $records->pluck('id'))
            ->orderBy('prerequisite_id')->orderBy('version')->get();

        return [
            'records' => $records,
            'history' => $events->groupBy('prerequisite_id'),
            // Both projections and immutable history: detect create and every revision,
            // including change/revert, without a long-held lock during Chromium rendering.
            'fingerprint' => hash('sha256', json_encode([$records, $events], JSON_THROW_ON_ERROR)),
        ];
    }

    public function prerequisiteList(Project $project, User $actor, ProjectMembership $member, object $notice, ?object $cancellation): array
    {
        $prerequisites = $this->scoped(DB::table('inspection_notification_prerequisites')->where('project_id', $project->id)->where('notification_id', $notice->id), $member)
            ->orderBy('created_at')->orderBy('id')->paginate(25, ['*'], 'prerequisite_page')->withQueryString();
        $canCreatePrerequisite = $notice->state === 'ISSUED' && $cancellation === null && $this->archives->isWritable($project)
            && in_array($actor->company_id, json_decode($notice->recipient_company_ids, true, 512, JSON_THROW_ON_ERROR), true);

        $prerequisiteStage = InspectionNotificationPrerequisiteStage::fromNotice($notice);

        return compact('prerequisites', 'canCreatePrerequisite', 'prerequisiteStage');
    }

    public function createPrerequisite(Project $project, User $actor, string $noticeId, array $input): string
    {
        return $this->write($project, $actor, function (User $fresh, ProjectMembership $member, Project $locked) use ($noticeId, $input): string {
            $d = Validator::make(['prerequisite' => $input], [
                'prerequisite' => ['required', 'array:item_reference,requirement'],
                'prerequisite.item_reference' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
                'prerequisite.requirement' => ['required', 'string', 'max:4000', 'regex:/\S/u'],
            ])->validate()['prerequisite'];
            $notice = $this->activePrerequisiteNotice($locked, $member, $noticeId);
            abort_unless(in_array($fresh->company_id, json_decode($notice->recipient_company_ids, true, 512, JSON_THROW_ON_ERROR), true), 403);
            $id = (string) Str::uuid();
            DB::table('inspection_notification_prerequisites')->insert([
                'id' => $id, 'project_id' => $locked->id, 'company_id' => $fresh->company_id, 'notification_id' => $notice->id,
                'issued_version' => $notice->version, 'item_reference' => trim($d['item_reference']), 'requirement' => trim($d['requirement']),
                'latest_actor_id' => $fresh->id, 'latest_actor_company_id' => $fresh->company_id,
            ]);

            return $id;
        });
    }

    public function prerequisiteContext(Project $project, User $actor, ProjectMembership $member, object $notice, string $id): array
    {
        $prerequisite = $this->scoped(DB::table('inspection_notification_prerequisites')->where('project_id', $project->id)->where('notification_id', $notice->id)->where('id', $id), $member)->firstOrFail();
        $active = $notice->state === 'ISSUED' && $this->cancellation($project, $member, $notice) === null && $this->archives->isWritable($project);
        $own = $prerequisite->company_id === $actor->company_id && in_array($actor->company_id, json_decode($notice->recipient_company_ids, true, 512, JSON_THROW_ON_ERROR), true);
        $actions = [];
        if ($active && $own && $prerequisite->state === 'OPEN') {
            $actions[] = 'EDIT';
        }
        if ($active && $own && in_array($prerequisite->state, ['OPEN', 'REPORTED'], true)) {
            $actions[] = 'WITHDRAW';
        }
        if ($active && $notice->company_id === $actor->company_id && in_array($prerequisite->state, ['OPEN', 'REPORTED'], true)
            && $this->permission($project, $actor, 'issue', $notice->discipline) !== null) {
            $actions[] = 'REPORT';
        }
        if ($active && $own && $prerequisite->state === 'REPORTED') {
            $actions = array_merge($actions, ['CONFIRM', 'REJECT']);
        }
        $prerequisiteHistory = DB::table('inspection_notification_prerequisite_events')->where('project_id', $project->id)->where('prerequisite_id', $prerequisite->id)
            ->orderByDesc('version')->paginate(25, ['*'], 'prerequisite_history_page')->withQueryString();

        $prerequisiteStage = InspectionNotificationPrerequisiteStage::fromNotice($notice);

        return compact('prerequisite', 'actions', 'prerequisiteHistory', 'prerequisiteStage');
    }

    public function updatePrerequisite(Project $project, User $actor, string $noticeId, string $id, array $input): void
    {
        $this->write($project, $actor, function (User $fresh, ProjectMembership $member, Project $locked) use ($noticeId, $id, $input): void {
            $d = Validator::make(['prerequisite' => $input], [
                'prerequisite' => ['required', 'array:version,action,item_reference,requirement,note'],
                'prerequisite.version' => ['required', 'integer', 'min:1', 'max:2147483646'],
                'prerequisite.action' => ['required', Rule::in(['EDIT', 'WITHDRAW', 'REPORT', 'CONFIRM', 'REJECT'])],
                'prerequisite.item_reference' => ['prohibited_unless:prerequisite.action,EDIT', 'required_if:prerequisite.action,EDIT', 'string', 'max:1000', 'regex:/\S/u'],
                'prerequisite.requirement' => ['prohibited_unless:prerequisite.action,EDIT', 'required_if:prerequisite.action,EDIT', 'string', 'max:4000', 'regex:/\S/u'],
                'prerequisite.note' => ['nullable', 'required_unless:prerequisite.action,CONFIRM', 'string', 'max:4000', 'regex:/\A[\s\S]*\z/u'],
            ])->after(function ($v) use ($input): void {
                if (($input['action'] ?? null) !== 'CONFIRM' && is_string($input['note'] ?? null) && preg_match('/\S/u', $input['note']) !== 1) {
                    $v->errors()->add('prerequisite.note', __('inspection_notifications.prerequisite_note_required'));
                }
            })->validate()['prerequisite'];
            $notice = $this->activePrerequisiteNotice($locked, $member, $noticeId);
            $row = $this->scoped(DB::table('inspection_notification_prerequisites')->where('project_id', $locked->id)->where('notification_id', $notice->id)->where('id', $id), $member)->lockForUpdate()->firstOrFail();
            if ($row->version !== (int) $d['version']) {
                $this->fail('stale');
            }
            $own = $row->company_id === $fresh->company_id;
            if ($d['action'] === 'REPORT') {
                abort_unless($notice->company_id === $fresh->company_id && $this->permission($locked, $fresh, 'issue', $notice->discipline) !== null, 403);
            } else {
                abort_unless($own, 403);
            }
            $note = trim($d['note'] ?? '');
            $patch = ['version' => $row->version + 1, 'latest_action' => $d['action'], 'latest_note' => $note === '' ? null : $note,
                'latest_actor_id' => $fresh->id, 'latest_actor_company_id' => $fresh->company_id];
            if ($d['action'] === 'EDIT') {
                $patch += ['item_reference' => trim($d['item_reference']), 'requirement' => trim($d['requirement'])];
            }
            DB::table('inspection_notification_prerequisites')->where('id', $row->id)->update($patch);
        });
    }

    public function notification(Project $project, ProjectMembership $member, string $id): object
    {
        return $this->scoped(DB::table('inspection_notifications')->where('project_id', $project->id)->where('id', $id), $member)->firstOrFail();
    }

    /** Own-company historical draft readers only, never recipient snapshot delegation. */
    public function draftHistory(Project $project, User $actor, ProjectMembership $member, object $notice): array
    {
        if ($notice->company_id !== $actor->company_id) {
            return ['draftHistory' => null, 'historyFromVersion' => null];
        }
        abort_unless($notice->project_id === $project->id, 404);
        $q = $this->scoped(DB::table('inspection_notification_draft_history')->where('project_id', $project->id)->where('company_id', $actor->company_id)->where('notification_id', $notice->id), $member);
        $historyFromVersion = (clone $q)->min('before_version');
        $draftHistory = $q->orderByDesc('after_version')->orderBy('id')->paginate(25, ['*'], 'history_page')->withQueryString();

        return compact('draftHistory', 'historyFromVersion');
    }

    public function dashboard(Project $project, User $actor, ProjectMembership $member, array $filters = []): array
    {
        $canManage = $this->canConfigure($project, $actor);
        $canViewDoc = $this->authz->allows($actor, 'doc.view', $project);
        $revisions = $this->scoped(DB::table('document_revisions as r')->join('documents as d', 'd.id', '=', 'r.document_id')
            ->where('r.project_id', $project->id)->where('d.project_id', $project->id)->where('d.doc_type', 'ITP')->where('r.cde_state', '<>', 'ARCHIVED'), $member, 'd.discipline')
            ->when(! $canViewDoc, fn ($q) => $q->whereRaw('false'))->select('r.id', 'r.revision', 'r.cde_state', 'd.number', 'd.discipline')->orderBy('d.number')->orderByDesc('r.ordinal')->orderBy('r.id')->paginate(25, ['*'], 'revision_page')->withQueryString();
        $stages = $this->scoped(DB::table('inspection_itp_stages'), $member)->where('project_id', $project->id)
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('document_revisions as nr')->whereColumn('nr.id', 'inspection_itp_stages.revision_id')->where('nr.cde_state', '<>', 'ARCHIVED'))
            ->when(! $canViewDoc, fn ($q) => $q->whereRaw('false'))->orderByDesc('created_at')->orderBy('id')->paginate(25, ['*'], 'stage_page')->withQueryString();
        $filters = array_replace(['notice_q' => '', 'notice_state' => '', 'activity_from' => '', 'activity_to' => '', 'activity_from_utc' => null, 'activity_to_utc' => null], $filters);
        $noticeQuery = $this->scoped(DB::table('inspection_notifications'), $member)->where('project_id', $project->id);
        $noticeVisibleTotal = (clone $noticeQuery)->count();
        if ($filters['notice_q'] !== '') {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['notice_q']).'%';
            $noticeQuery->where(fn ($q) => $q->whereRaw("number ILIKE ? ESCAPE '!'", [$term])->orWhereRaw("work_reference ILIKE ? ESCAPE '!'", [$term]));
        }
        if ($filters['notice_state'] === 'CANCELLED') {
            $noticeQuery->whereExists(fn ($q) => $q->selectRaw('1')->from('inspection_notification_cancellations as nc')->whereColumn('nc.notification_id', 'inspection_notifications.id'));
        } elseif ($filters['notice_state'] !== '') {
            $noticeQuery->where('state', $filters['notice_state']);
            if ($filters['notice_state'] === 'ISSUED') {
                $noticeQuery->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inspection_notification_cancellations as nc')->whereColumn('nc.notification_id', 'inspection_notifications.id'));
            }
        }
        // Inclusive overlap. Legacy single times are unknown intervals, not points.
        if ($filters['activity_from_utc'] !== null || $filters['activity_to_utc'] !== null) {
            $noticeQuery->whereNotNull('activity_start_at')->whereNotNull('activity_end_at');
            if ($filters['activity_from_utc'] !== null) {
                $noticeQuery->where('activity_end_at', '>=', $filters['activity_from_utc']);
            }
            if ($filters['activity_to_utc'] !== null) {
                $noticeQuery->where('activity_start_at', '<=', $filters['activity_to_utc']);
            }
        }
        $notifications = $noticeQuery->orderByDesc('created_at')->orderBy('id')->paginate(25, ['*'], 'notice_page')->withQueryString();
        $cancellations = $this->scoped(DB::table('inspection_notification_cancellations')->where('project_id', $project->id)->whereIn('notification_id', $notifications->pluck('id')), $member)->get()->keyBy('notification_id');
        foreach ($notifications as $notice) {
            $notice->cancellation = $cancellations->get($notice->id);
        }
        $companies = DB::table('project_company')->join('companies', 'companies.id', '=', 'project_company.company_id')->where('project_id', $project->id)->select('companies.*')->orderBy('code')->get();
        $plans = Plan::whereIn('id', $companies->pluck('plan_id'))->get()->keyBy('id');
        $recipientCompanies = $companies->filter(fn ($c) => isset($plans[$c->plan_id]) && $plans[$c->plan_id]->hasModule('qaqc') && $plans[$c->plan_id]->allowsPack($project->standards_pack));
        $epcs = $companies->where('tenant_type', 'EPC');
        $people = $canManage ? $this->tenant->forIdentityLookup(fn () => User::where('is_active', true)->whereIn('company_id', $epcs->pluck('id'))
            ->whereIn('id', ProjectMembership::where('project_id', $project->id)->pluck('user_id'))->orderBy('name')->get(['id', 'name', 'company_id'])) : collect();

        return compact('filters', 'noticeVisibleTotal', 'revisions', 'stages', 'notifications', 'companies', 'recipientCompanies', 'epcs', 'people', 'canManage') + [
            'canAddStages' => $this->archives->isWritable($project) && $this->authz->allows($actor, 'doc.issue', $project),
            'settings' => $this->latest($project), 'history' => $canManage ? DB::table('inspection_notification_settings')->where('project_id', $project->id)->orderByDesc('version')->limit(20)->get() : collect(),
        ];
    }
}
