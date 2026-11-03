<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InspectionReleaseNote;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Role;
use App\Models\User;
use App\Support\AuthorizationPipeline;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Inspection Release Notes (ADR-060) — the release, the gate it drives, and the
 * nomination that authorises it.
 *
 * What this service is for: an inspector attends, and either releases the
 * material or refuses to. Until now the platform recorded the attendance and
 * had nowhere to put the verdict, so a FAIL changed nothing downstream — the
 * material shipped and was received exactly as PASS material is. This records
 * the verdict and makes the receipt path ask about it.
 *
 * What it deliberately is not: it is not a nonconformance system. A release
 * WITH HOLDS states its holds; tracking them to clearance is the TQ/NCR slice
 * (ADR-031), which is the honest home for a defect that has to travel.
 *
 * The gate is deliberately one-sided. Absence of inspection does not block:
 * material nobody inspected is a planning gap, not a quality hold, and
 * blocking it would stop every project that has not switched inspection on.
 * A FAILED inspection blocks, and only a release clears it.
 */
final class InspectionReleaseNoteService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $access,
        private readonly ProjectArchiveService $archives,
        private readonly AuthorizationPipeline $authz,
    ) {}

    /**
     * May material on this order be received?
     *
     * @return 'BLOCKED'|'RELEASED'|'NOT_REQUIRED'
     */
    public function statusFor(Project $project, User $actor, string $orderId): string
    {
        return $this->actingAs($actor, $project, fn (): string => (string) DB::selectOne(
            'SELECT epcc_po_release_status(?) AS status', [$orderId]
        )->status);
    }

    /**
     * The gate, in the one place every receipt path can call it.
     *
     * @throws ValidationException when the order is held
     */
    public function assertReceivable(Project $project, User $actor, string $orderId, string $lang = 'fa'): void
    {
        if ($this->statusFor($project, $actor, $orderId) !== 'BLOCKED') {
            return;
        }

        throw ValidationException::withMessages(['purchase_order' => $lang === 'fa'
            ? 'این سفارش به دلیلِ نتیجهٔ مردودِ بازرسی و نبودِ تأییدیهٔ آزادسازی، قابلِ دریافت نیست.'
            : 'This order cannot be received: an inspection has failed and no release note covers it.']);
    }

    /**
     * Everything the configuration screen needs. Candidate list is read inside
     * the tenant scope: outside it, RLS returns nobody and every selector looks
     * invalid.
     *
     * @return array<string, mixed>
     */
    public function dashboard(Project $project, User $actor): array
    {
        return $this->actingAs($actor, $project, function () use ($project, $actor): array {
            $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->first();
            $canConfigure = $fresh !== null && $this->canConfigure($project, $fresh);
            $policies = DB::table('irn_issuer_policies')
                ->where('project_id', $project->id)->orderByDesc('version')->limit(20)->get();
            $current = $policies->first();

            return [
                'current' => $current,
                'selectors' => $current ? json_decode($current->selectors, true, 512, JSON_THROW_ON_ERROR) : [],
                'history' => $policies,
                'canConfigure' => $canConfigure,
                'options' => $canConfigure ? $this->eligible($project) : [],
            ];
        });
    }

    /** Is this actor nominated to release material? Read fresh, never cached. */
    public function allows(Project $project, User $actor): bool
    {
        return $this->actingAs($actor, $project, fn (): bool => DB::selectOne(
            'SELECT epcc_irn_nomination(?, ?, ?) AS policy_id',
            [$project->id, $actor->company_id, $actor->id]
        )->policy_id !== null);
    }

    /**
     * Is this order held? Every receipt path asks the same question, so the
     * answer is defined once — in epcc_po_release_status() — and read here.
     */
    public function isBlocked(Project $project, User $actor, string $orderId): bool
    {
        return $this->statusFor($project, $actor, $orderId) === 'BLOCKED';
    }

    /** Configuration authority mirrors existing owner project configuration. */
    public function canConfigure(Project $project, User $actor): bool
    {
        if ($project->owner_company_id !== $actor->company_id || ! $this->archives->isWritable($project)) {
            return false;
        }

        return $this->authz->allows($actor, 'project.configure', $project);
    }

    /**
     * Only active members of inspection-body companies on this project. Never a
     * global user directory — the candidates are the project's own participants.
     *
     * @return array<string, string> selector => label
     */
    public function eligible(Project $project): array
    {
        $members = ProjectMembership::where('project_id', $project->id)->get()->keyBy('user_id');
        $companies = DB::table('companies')
            ->whereIn('tenant_type', WarehouseService::INSPECTOR_TYPES)
            ->whereIn('id', DB::table('project_company')->where('project_id', $project->id)->select('company_id'))
            ->pluck('code', 'id');
        $people = $this->tenant->forIdentityLookup(fn () => User::whereIn('id', $members->keys())
            ->whereIn('company_id', $companies->keys())->where('is_active', true)
            ->get(['id', 'name', 'company_id']));
        $roles = Role::all()->keyBy('code');

        $options = [];
        foreach ($people as $person) {
            $membership = $members[$person->id];
            $options['user:'.$person->id] = $person->name.' · '.$companies[$person->company_id];
            foreach ($membership->role_codes ?? [] as $code) {
                if (isset($roles[$code])) {
                    $options['role:'.$person->company_id.':'.$code] = $roles[$code]->name(app()->getLocale()).' · '.$companies[$person->company_id];
                }
            }
        }

        return $options;
    }

    /**
     * Nominate who may release material. A replacement is a new version, never
     * an edit: earlier releases point at the version that authorised them, and
     * rewriting it would falsify them.
     *
     * @param  array<string, mixed>  $input
     */
    public function configure(Project $project, User $actor, array $input): string
    {
        return $this->actingAs($actor, $project, function () use ($project, $actor, $input): string {
            return DB::transaction(function () use ($project, $actor, $input): string {
                $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                // Fresh, with the plan loaded: authority is read from the
                // database at this instant, never from a stale relation the
                // caller handed us (ADR-041 does the same, for the same reason).
                $fresh = User::with('company.plan')->whereKey($actor->id)->where('is_active', true)->firstOrFail();
                abort_unless($this->canConfigure($project, $fresh), 403);
                $this->archives->assertWritable($project);

                // Candidate list is read INSIDE the tenant scope on purpose:
                // outside it, RLS returns nobody and every selector looks
                // invalid.
                $data = Validator::make(['issuers' => $input], [
                    'issuers' => ['array:selectors,reason'],
                    'issuers.selectors' => ['present', 'array', 'max:100'],
                    'issuers.selectors.*' => ['string', Rule::in(array_keys($this->eligible($project)))],
                    'issuers.reason' => ['required', 'string', 'min:3', 'max:2000'],
                ])->validate()['issuers'];

                $version = ((int) DB::table('irn_issuer_policies')->where('project_id', $project->id)->max('version')) + 1;
                $id = (string) Str::uuid();
                DB::table('irn_issuer_policies')->insert([
                    'id' => $id,
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'created_by' => $actor->id,
                    'actor_name' => $actor->name,
                    'version' => $version,
                    'selectors' => json_encode(array_values($data['selectors']), JSON_THROW_ON_ERROR),
                    'reason' => $data['reason'],
                    'created_at' => now(),
                ]);

                return $id;
            });
        });
    }

    /**
     * Record a release (or a refusal) against one purchase order line.
     *
     * @param  array<string, mixed>  $input
     */
    public function issue(Project $project, User $actor, string $orderId, string $lineId, array $input): InspectionReleaseNote
    {
        $this->access->assertAccess($project, $actor);

        try {
            return $this->actingAs($actor, $project, function () use ($project, $actor, $orderId, $lineId, $input): InspectionReleaseNote {
                return DB::transaction(function () use ($project, $actor, $orderId, $lineId, $input): InspectionReleaseNote {
                    // Numbering is project-scoped and derived inside the guard,
                    // which takes the same lock. Taking it here too keeps the
                    // read-then-write sequence honest.
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['inspection-release:'.$project->id]);

                    $project = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
                    $this->archives->assertWritable($project);

                    // Original caller RLS only: nomination must never widen what
                    // the caller could already read.
                    $order = PurchaseOrder::where('project_id', $project->id)->whereKey($orderId)->sharedLock()->firstOrFail();
                    $line = PurchaseOrderItem::where('project_id', $project->id)
                        ->where('purchase_order_id', $order->id)->whereKey($lineId)->firstOrFail();

                    $data = Validator::make(['release' => $input], $this->rules($project, $order, $line))->validate()['release'];

                    // Carried over from the retired observation (ADR-041): a
                    // verdict that is not a clean release must say why, or the
                    // vendor has nothing to act on.
                    if ($data['outcome'] === 'NOT_RELEASED'
                        && trim((string) ($data['findings_en'] ?? '')) === ''
                        && trim((string) ($data['findings_fa'] ?? '')) === '') {
                        throw ValidationException::withMessages(['release' => __('inspection_release_notes.findings_required')]);
                    }

                    $note = new InspectionReleaseNote([
                        'project_id' => $project->id,
                        'company_id' => $actor->company_id,
                        'purchase_order_item_id' => $line->id,
                        'stage' => $data['stage'],
                        'outcome' => $data['outcome'],
                        'inspector_company_id' => $data['inspector_company_id'] ?? null,
                        'inspector_user_id' => $data['inspector_user_id'] ?? null,
                        'inspector_name' => $data['inspector_name'] ?? null,
                        'findings_en' => $data['findings_en'] ?? null,
                        'findings_fa' => $data['findings_fa'] ?? null,
                        'holds_note_en' => $data['holds_note_en'] ?? null,
                        'holds_note_fa' => $data['holds_note_fa'] ?? null,
                        'released_at' => $data['released_at'] ?? null,
                        'issued_by' => $actor->id,
                        'previous_release_note_id' => $data['previous_release_note_id'] ?? null,
                    ]);
                    $note->id = (string) Str::uuid();
                    $note->save();

                    return $note->refresh();
                });
            });
        } catch (QueryException $e) {
            throw $this->mapFailure($e);
        }
    }

    /** @return array<string, array<int, mixed>> */
    private function rules(Project $project, PurchaseOrder $order, PurchaseOrderItem $line): array
    {
        return [
            'release' => ['array:stage,outcome,inspector_user_id,inspector_company_id,inspector_name,findings_en,findings_fa,holds_note_en,holds_note_fa,released_at,previous_release_note_id'],
            'release.stage' => ['required', 'string', Rule::in(InspectionReleaseNote::STAGES)],
            'release.outcome' => ['required', 'string', Rule::in(InspectionReleaseNote::OUTCOMES)],
            'release.findings_en' => ['nullable', 'string', 'max:8000'],
            'release.findings_fa' => ['nullable', 'string', 'max:8000'],
            'release.inspector_user_id' => ['nullable', 'uuid', Rule::exists('users', 'id')],
            'release.inspector_company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')],
            'release.inspector_name' => ['nullable', 'string', 'max:255'],
            'release.holds_note_en' => ['nullable', 'string', 'max:8000'],
            'release.holds_note_fa' => ['nullable', 'string', 'max:8000'],
            'release.released_at' => ['nullable', 'string', 'date_format:Y-m-d'],
            'release.previous_release_note_id' => ['nullable', 'uuid',
                Rule::exists('inspection_release_notes', 'id')->where(fn ($q) => $q
                    ->where('project_id', $project->id)->where('purchase_order_item_id', $line->id)),
            ],
        ];
    }

    /**
     * Guard refusals arrive as SQLSTATE 42501/23514 carrying a code the caller
     * can act on. Anything else is a bug and must stay loud.
     */
    private function mapFailure(QueryException $e): ValidationException
    {
        if (preg_match('/IRN_[A-Z_]+/', $e->getMessage(), $match) === 1) {
            return ValidationException::withMessages(['release' => $match[0]]);
        }
        if ($e->getCode() === '23505') {
            return ValidationException::withMessages(['release' => 'IRN_NUMBER_CONFLICT']);
        }

        throw $e;
    }

    /**
     * Bind the acting user for the results register. The guard checks its
     * authority against this GUC, not against a value the caller passed.
     */
    private function actingAs(User $actor, Project $project, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;

            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$actor->id]);
            try {
                return $fn();
            } finally {
                DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$prior ?? '']);
            }
        }, $project->id);
    }
}
