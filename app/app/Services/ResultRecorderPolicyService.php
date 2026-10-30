<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The quality nomination register — ADR-048 A.4g slice 1.
 *
 * Since A.4b, recording inspection evidence is default-deny: a result may only
 * be written when `epcc_result_nomination()` finds a current, un-suspended
 * policy naming the actor for that action. That is the right rule, and until
 * now it had no way to be satisfied outside a test — the only code that ever
 * wrote to `result_recorder_policies` was `ResultRecorderPolicyFixture`, which
 * inserts rows directly. A register nobody can write to is not a register; it
 * is a lock with the key inside the room.
 *
 * This service is the key. It is deliberately small and boring:
 *
 *   - A nomination is (project, target company, action, version). It is never
 *     edited — a new version supersedes the old one, and the old row stays.
 *   - Suspension is a new version with `suspended = true`. There is no UPDATE
 *     policy on the table, by design, so this is the only way to suspend.
 *   - Who may nominate: a company nominates its own people, or the project
 *     employer nominates anyone. Nobody else may, and that is enforced twice —
 *     here for a clean 403, and again in `epcc_guard_result_policy_author()`
 *     for anyone who reaches the table another way.
 *   - Selectors are offered, not accepted: `user:<uuid>` or
 *     `role:<company uuid>:<role code>`, each checked against the project's
 *     actual memberships, so a nomination can never name someone the guard
 *     would not recognise.
 *   - "Am I nominated?" is answered by `epcc_result_nomination()` — the same
 *     function the guards call. One definition of authority, not two.
 *
 * Declared boundaries: this is the register and its surface. It does not record
 * results, sign stages, bind certificates or reconcile lots — those have their
 * own services, and all of them obey this one.
 */
final class ResultRecorderPolicyService
{
    /**
     * The quality actions this register governs. The CHECK constraint on the
     * table is the authority; this list is its mirror.
     */
    public const ACTIONS = [
        'record_weld',
        'record_ndt',
        'record_pack_result',
        'register_certificate',
        'sign_off_stage',
        'bind_certificate',
        'reconcile_legacy',
        'sign_itr_snapshot',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ProjectArchiveService $archives,
    ) {}

    // ------------------------------------------------------------------ read

    /**
     * Everything the nominations screen needs.
     *
     * @return array{project: Project, writable: bool, actions: list<string>, companies: Collection, options: array<string,string>, current: Collection, history: Collection, nominations: array<string,?string>, mayNominateFor: array<string,bool>, versions: array<string,int>}
     */
    public function dashboard(Project $project, User $actor): array
    {
        return $this->tenant->runAs($actor->company_id, function () use ($project, $actor): array {
            $project = Project::whereKey($project->id)->firstOrFail();
            $fresh = User::whereKey($actor->id)->where('is_active', true)->firstOrFail();
            abort_unless($fresh->company_id === $actor->company_id, 403);
            $this->assertMember($project, $fresh);

            $writable = $this->archives->isWritable($project);

            // The effective register: the highest version per (company, action).
            $current = collect(DB::select(
                'SELECT DISTINCT ON (target_company_id, action) *
                   FROM result_recorder_policies
                  WHERE project_id = ?
                  ORDER BY target_company_id, action, version DESC',
                [$project->id]
            ));

            $history = DB::table('result_recorder_policies')->where('project_id', $project->id)
                ->orderByDesc('created_at')->orderByDesc('version')->limit(40)->get();

            $companies = Company::whereIn('id', DB::table('project_company')
                ->where('project_id', $project->id)->select('company_id'))
                ->orderBy('code')->get()->keyBy('id');

            $options = $writable ? $this->directory($project) : [];

            // Am I nominated, per action? Asked of the same function the guards ask.
            $nominations = [];
            foreach (self::ACTIONS as $action) {
                $nominations[$action] = $this->nominationFor($project, $fresh, $action);
            }

            // The version each form must quote, so a stale submission is refused
            // rather than silently overwriting a newer nomination.
            $versions = [];
            foreach ($current as $row) {
                $versions[$row->target_company_id.'|'.$row->action] = (int) $row->version;
            }

            $mayNominateFor = [];
            foreach ($companies as $id => $_company) {
                $mayNominateFor[$id] = $this->mayNominateFor($project, $fresh, (string) $id);
            }

            return [
                'project' => $project,
                'writable' => $writable,
                'actions' => self::ACTIONS,
                'companies' => $companies,
                'options' => $options,
                'current' => $current,
                'history' => $history,
                'nominations' => $nominations,
                'mayNominateFor' => $mayNominateFor,
                'versions' => $versions,
            ];
        }, $project->id);
    }

    /**
     * The policy that currently nominates this actor for this action, or null.
     * Delegates to epcc_result_nomination() so the screen and the guard can
     * never disagree about what authority exists.
     */
    public function nominationFor(Project $project, User $actor, string $action): ?string
    {
        $row = DB::selectOne(
            'SELECT epcc_result_nomination(?, ?, ?, ?) AS policy_id',
            [$project->id, $actor->company_id, $actor->id, $action]
        );

        return $row?->policy_id;
    }

    // ----------------------------------------------------------------- write

    /**
     * Nominates, re-nominates or suspends. A new version is always written;
     * nothing is ever edited in place.
     *
     * @param  array<string,mixed>  $input  version, target_company_id, action, selectors?, suspend, reason
     *
     * @return string the new policy id
     */
    public function configure(Project $project, User $actor, array $input, string $lang = 'fa'): string
    {
        try {
            return $this->scoped($project, $actor, function () use ($project, $actor, $input, $lang): string {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['result-nomination:'.$project->id]);
                $project = Project::whereKey($project->id)->lock('FOR NO KEY UPDATE')->firstOrFail();
                $fresh = User::whereKey($actor->id)->where('is_active', true)->firstOrFail();
                abort_unless($fresh->company_id === $actor->company_id, 403);
                $this->assertMember($project, $fresh);
                $this->archives->assertWritable($project);

                $data = $this->validate($input, $lang);
                $suspended = (bool) $data['suspend'];

                $this->assertMayNominateFor($project, $fresh, $data['target_company_id'], $lang);

                $version = (int) (DB::table('result_recorder_policies')
                    ->where('project_id', $project->id)
                    ->where('target_company_id', $data['target_company_id'])
                    ->where('action', $data['action'])
                    ->max('version') ?? 0);

                if ((int) $data['version'] !== $version) {
                    $this->fail($lang, 'stale');
                }

                $selectors = [];
                if (! $suspended) {
                    if (($data['selectors'] ?? []) === []) {
                        $this->fail($lang, 'empty');
                    }
                    foreach ($data['selectors'] as $selector) {
                        if (! isset($this->directory($project)[$selector])) {
                            $this->fail($lang, 'selector');
                        }
                        $selectors[] = $selector;
                    }
                }

                $id = (string) Str::uuid();
                DB::table('result_recorder_policies')->insert([
                    'id' => $id,
                    'project_id' => $project->id,
                    'company_id' => $fresh->company_id,
                    'target_company_id' => $data['target_company_id'],
                    'created_by' => $fresh->id,
                    'action' => $data['action'],
                    'version' => $version + 1,
                    'suspended' => $suspended,
                    'selectors' => json_encode(array_values(array_unique($selectors)), JSON_THROW_ON_ERROR),
                    'reason' => trim($data['reason']),
                    'created_at' => now(),
                ]);

                return $id;
            });
        } catch (QueryException $e) {
            throw $this->mapFailure($e, $lang);
        }
    }

    // ------------------------------------------------------------- internals

    /** Runs $fn inside the results session contract: project, company, result actor. */
    private function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$actor->id]);

            try {
                return DB::transaction($fn);
            } finally {
                DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$prior ?? '']);
            }
        }, $project->id);
    }

    /** @return array<string,mixed> */
    private function validate(array $input, string $lang): array
    {
        $invalid = $this->msg($lang, 'درخواست نامعتبر است.', 'The request is not valid.');

        $data = Validator::make(['policy' => $input], [
            'policy' => ['required', 'array:version,target_company_id,action,selectors,suspend,reason'],
            'policy.version' => ['required', 'integer', 'min:0', 'max:2147483646'],
            'policy.target_company_id' => ['required', 'uuid'],
            'policy.action' => ['required', Rule::in(self::ACTIONS)],
            'policy.suspend' => ['required', 'boolean'],
            'policy.selectors' => ['array', 'max:200'],
            'policy.selectors.*' => ['required', 'string', 'max:160'],
            'policy.reason' => ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ], array_fill_keys(
            ['required', 'array', 'integer', 'min', 'max', 'uuid', 'string', 'boolean', 'in', 'regex'],
            $invalid
        ))->validate()['policy'];

        if (str_contains((string) $data['reason'], "\0")) {
            $this->fail($lang, 'invalid');
        }

        return $data;
    }

    /**
     * The people and roles a nomination may name: `user:<uuid>` for one named
     * person, `role:<company uuid>:<role code>` for every member holding that
     * project role. Only members whose discipline scope is unrestricted or
     * covers PIPING appear, because that is exactly whom
     * epcc_result_nomination() would recognise — offering a selector the guard
     * ignores would be a nomination that says one thing and does another.
     *
     * @return array<string,string> selector => human label
     */
    private function directory(Project $project): array
    {
        $members = ProjectMembership::where('project_id', $project->id)->get()
            ->filter(fn ($m) => $m->discipline_scope === null
                || in_array('PIPING', (array) $m->discipline_scope, true));

        $people = $this->tenant->forIdentityLookup(fn () => User::with('company')
            ->whereIn('id', $members->pluck('user_id'))->where('is_active', true)
            ->orderBy('name')->get())->keyBy('id');

        $roles = Role::all()->keyBy('code');
        $options = [];

        foreach ($members as $membership) {
            $user = $people->get($membership->user_id);

            if ($user === null) {
                continue;
            }

            $options['user:'.$user->id] = trim($user->name.' <'.$user->email.'>');

            foreach ((array) $membership->role_codes as $code) {
                $role = $roles->get($code);

                if ($role === null) {
                    continue;
                }

                $key = 'role:'.$user->company_id.':'.$code;
                $label = ($user->company?->code ?? '—').' · '.($role->name_en ?? $code);
                $options[$key] = $label;
            }
        }

        ksort($options);

        return $options;
    }

    private function mayNominateFor(Project $project, User $actor, string $targetCompanyId): bool
    {
        return (string) $project->owner_company_id === (string) $actor->company_id
            || (string) $targetCompanyId === (string) $actor->company_id;
    }

    private function assertMayNominateFor(Project $project, User $actor, string $targetCompanyId, string $lang): void
    {
        if (! $this->mayNominateFor($project, $actor, $targetCompanyId)) {
            $this->fail($lang, 'author');
        }
    }

    private function assertMember(Project $project, User $actor): void
    {
        abort_unless(
            DB::table('project_memberships')->where('project_id', $project->id)
                ->where('user_id', $actor->id)->exists(),
            403
        );
    }

    public function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();

        $text = match (true) {
            str_contains($m, 'RESULT_POLICY_AUTHOR_NOT_PERMITTED') => $this->msg($lang,
                'فقط کارفرمای پروژه می‌تواند شرکتِ دیگری را نامزد کند؛ هر شرکت فقط آدم‌های خودش را نامزد می‌کند.',
                'Only the project employer may nominate another company; a company nominates its own people.'),
            str_contains($m, 'RESULT_POLICY_AUTHOR_NOT_MEMBER') => $this->msg($lang,
                'نامزدکننده باید عضو پروژه باشد.',
                'Whoever nominates must be a member of the project.'),
            str_contains($m, 'RESULT_POLICY_AUTHORITY') => $this->msg($lang,
                'نامزدی باید به نامِ شرکت و شخصِ جاری ثبت شود.',
                'A nomination must be made by the current company and actor.'),
            str_contains($m, 'RESULT_POLICY_PROJECT_NOT_WRITABLE') => $this->msg($lang,
                'پروژه آرشیوی است؛ نامزدی جدید ثبت نمی‌شود.',
                'The project is archived; no new nomination is recorded.'),
            str_contains($m, 'result_recorder_version_key') => $this->msg($lang,
                'این نسخه پیش‌تر ثبت شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.',
                'That version was already recorded; refresh and try again.'),
            default => $this->msg($lang,
                'ثبتِ نامزدی رد شد؛ هیچ رکوردی ذخیره نشد.',
                'The nomination was refused; nothing was recorded.'),
        };

        return ValidationException::withMessages(['policy' => $text]);
    }

    /** @return never */
    private function fail(string $lang, string $kind): void
    {
        $text = match ($kind) {
            'stale' => $this->msg($lang,
                'سابقهٔ نامزدی از زمانی که صفحه را دیدید تغییر کرده است؛ صفحه را تازه کنید.',
                'The nomination register changed since you loaded the page; refresh and try again.'),
            'author' => $this->msg($lang,
                'شما نمی‌توانید این شرکت را نامزد کنید: فقط کارفرمای پروژه یا خودِ آن شرکت.',
                'You may not nominate that company: only the project employer, or the company itself.'),
            'selector' => $this->msg($lang,
                'یکی از انتخاب‌ها عضوِ فعّال این پروژه نیست یا خارج از محدودهٔ رشته است.',
                'One of the chosen people or roles is not an active member of this project, or is out of discipline scope.'),
            'empty' => $this->msg($lang,
                'نامزدی باید دست‌کم یک نفر یا نقش را نام ببرد؛ نامزدیِ تهی پذیرفته نیست.',
                'A nomination must name at least one person or role; an empty nomination is refused.'),
            default => $this->msg($lang,
                'درخواست نامعتبر است.',
                'The request is not valid.'),
        };

        throw ValidationException::withMessages(['policy' => $text]);
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'en' ? $en : $fa;
    }
}
