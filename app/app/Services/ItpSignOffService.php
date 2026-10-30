<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ITP stage sign-offs — ADR-048 A.4d slice 1.
 *
 * A notification says "we asked you to attend". A sign-off says what actually
 * happened at the stage: accepted, accepted with comments, rejected, or — and
 * this is the one quality records usually lose — nobody attended and the point
 * was waived.
 *
 * The service is deliberately thin: the database derives the stage and its
 * point, resolves and stamps the authorising nomination, enforces reason
 * pairing and refuses a contractor waiving an employer's hold point. The
 * service only shapes input and turns refusals into bilingual field errors.
 *
 * Declared boundary: sign-offs are RECORDED, not yet GATED. There is no
 * notification<->test-pack link, so nothing yet stops a pack being accepted
 * without a hold sign-off; that gate is tracked as A.5b.
 */
final class ItpSignOffService
{
    public const OUTCOMES = ['ACCEPTED', 'ACCEPTED_WITH_COMMENTS', 'REJECTED', 'WAIVED_NOT_ATTENDED'];

    /** Outcomes that are never recorded without saying why. */
    public const REASONED = ['ACCEPTED_WITH_COMMENTS', 'REJECTED', 'WAIVED_NOT_ATTENDED'];

    /** A screen is not a report: the worklist is capped rather than paginated. */
    public const WORKLIST_LIMIT = 200;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ResultRecorderPolicyService $nominations,
    ) {}

    /** Runs $fn in the sign-off session contract: project, signing company, actor. */
    private function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS actor")->actor;
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$actor->id]);
            try {
                return DB::transaction($fn);
            } finally {
                DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$prior ?? '']);
            }
        }, $project->id);
    }

    /**
     * Records one sign-off on an ISSUED notification's stage.
     *
     * @param  array<string,mixed>  $input  outcome, reason (paired), previous_signoff_id?, request_id
     *
     * @return array{id: string, stage_id: string, point: string}
     */
    public function signOff(Project $project, User $actor, string $notificationId, array $input, string $lang = 'fa'): array
    {
        $errors = [];
        $notification = $this->uuidArg($notificationId, 'notification_id', $errors, $lang);

        $outcome = strtoupper(trim((string) ($input['outcome'] ?? '')));
        if (! in_array($outcome, self::OUTCOMES, true)) {
            $errors['outcome'] = $this->msg($lang,
                'نتیجهٔ sign-off باید یکی از این‌ها باشد: ACCEPTED، ACCEPTED_WITH_COMMENTS، REJECTED، WAIVED_NOT_ATTENDED.',
                'The sign-off outcome must be one of: ACCEPTED, ACCEPTED_WITH_COMMENTS, REJECTED, WAIVED_NOT_ATTENDED.');
            $outcome = '';
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($outcome !== '' && in_array($outcome, ['REJECTED', 'WAIVED_NOT_ATTENDED', 'ACCEPTED_WITH_COMMENTS'], true)
            && ($reason === '' || mb_strlen($reason) > 4000)) {
            $errors['reason'] = $this->msg($lang,
                'این نتیجه بدون شرح ثبت نمی‌شود: مردودی علت می‌خواهد، انصراف دلیل انصراف می‌خواهد، و پذیرشِ مشروط همان نظرها را.',
                'This outcome is never recorded without a reason: a rejection needs a cause, a waiver needs why nobody attended, and a conditional acceptance needs the comments.');
        }

        $previous = null;
        if (($input['previous_signoff_id'] ?? null) !== null && (string) $input['previous_signoff_id'] !== '') {
            $previous = $this->uuidArg((string) $input['previous_signoff_id'], 'previous_signoff_id', $errors, $lang);
        }

        $requestId = trim((string) ($input['request_id'] ?? ''));
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');
        }

        $signedAt = trim((string) ($input['signed_at'] ?? now()->toIso8601String()));

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $notification, $outcome, $reason, $previous, $requestId, $signedAt, $lang): array {
            try {
                $id = (string) DB::table('inspection_stage_signoffs')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'notification_id' => $notification,
                    'stage_id' => $notification,   // replaced by the guard from the notification's stage
                    'point' => 'M',                // replaced by the guard from the stage
                    'outcome' => $outcome,
                    'reason' => $reason === '' ? null : $reason,
                    'signed_by' => $actor->id,
                    'signed_at' => $signedAt,
                    'signer_policy_id' => $notification, // replaced by the resolved nomination
                    'previous_signoff_id' => $previous,
                    'request_id' => $requestId,
                ], 'id');
            } catch (QueryException $e) {
                throw $this->mapFailure($e, $lang);
            }

            $row = DB::table('inspection_stage_signoffs')->where('id', $id)->first();

            return [
                'id' => $id,
                'stage_id' => (string) $row->stage_id,
                'point' => (string) $row->point,
            ];
        });
    }

    /**
     * Everything the sign-off screen needs, read under the same session
     * contract the write uses.
     *
     * The worklist is deliberately "whatever RLS lets this company see"
     * rather than a query of our own. A notice is visible to the company that
     * issued it and, once issued, to the companies it was sent to — which is
     * exactly the rule this screen should enforce: you cannot sign a stage you
     * were never asked to attend. Stating that in PHP as well would be a
     * second copy that can drift from the policy.
     */
    public function dashboard(Project $project, User $actor, ?string $noticeId = null, string $lang = 'fa'): array
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $noticeId, $lang): array {
            $notices = DB::table('inspection_notifications as n')
                ->join('inspection_itp_stages as s', 's.id', '=', 'n.stage_id')
                ->where('n.project_id', $project->id)
                ->where('n.state', 'ISSUED')
                // A cancelled notice is a request that was withdrawn; signing it
                // would be answering a question nobody is asking any more.
                ->whereNotExists(fn ($q): mixed => $q->selectRaw('1')
                    ->from('inspection_notification_cancellations as nc')
                    ->whereColumn('nc.notification_id', 'n.id'))
                ->select('n.*', 's.point as point', 's.sequence as stage_sequence')
                ->orderByDesc('n.created_at')->orderBy('n.id')
                ->limit(self::WORKLIST_LIMIT)->get();

            $notice = $noticeId !== null && $noticeId !== '' ? $notices->firstWhere('id', $noticeId) : null;
            $signOffs = $notice !== null ? $this->signOffsFor($project, $actor, (string) $notice->id) : collect();

            return [
                'project' => $project,
                'notices' => $notices,
                'notice' => $notice,
                'signOffs' => $signOffs,
                'latest' => $signOffs->groupBy('company_id')->map(fn (Collection $rows): mixed => $rows->last())->values(),
                // A correction chains to a rejection of YOUR company — the guard
                // refuses any other target, so offering the rest would be
                // offering a refusal.
                'corrections' => $signOffs->where('company_id', $actor->company_id)
                    ->where('outcome', 'REJECTED')->values(),
                'nominated' => $this->nominations->nominationFor($project, $actor, 'sign_off_stage'),
                // The employer is the only company that may waive a HOLD point,
                // so the screen has to know which one it is talking to.
                'isEmployer' => $actor->company_id === $project->owner_company_id,
                'companies' => Company::whereIn('id', DB::table('project_company')
                    ->where('project_id', $project->id)->select('company_id'))
                    ->orderBy('code')->get()->keyBy('id'),
                'outcomes' => self::OUTCOMES,
                'outcomeLabels' => $this->outcomeLabels($lang),
                'pointLabels' => $this->pointLabels($lang),
            ];
        });
    }

    private function outcomeLabels(string $lang): array
    {
        return [
            'ACCEPTED' => $lang === 'fa' ? 'پذیرفته' : 'Accepted',
            'ACCEPTED_WITH_COMMENTS' => $lang === 'fa' ? 'پذیرفته با نظر' : 'Accepted with comments',
            'REJECTED' => $lang === 'fa' ? 'مردود' : 'Rejected',
            'WAIVED_NOT_ATTENDED' => $lang === 'fa' ? 'انصراف (بدون حضور)' : 'Waived (not attended)',
        ];
    }

    private function pointLabels(string $lang): array
    {
        return [
            'H' => $lang === 'fa' ? 'توقف (H)' : 'Hold (H)',
            'W' => $lang === 'fa' ? 'شاهد (W)' : 'Witness (W)',
            'R' => $lang === 'fa' ? 'بازبینی مدارک (R)' : 'Review (R)',
            'M' => $lang === 'fa' ? 'پایش (M)' : 'Monitor (M)',
        ];
    }

    /** Every sign-off recorded against one notification, oldest first. */
    public function signOffsFor(Project $project, User $actor, string $notificationId): Collection
    {
        if (! Str::isUuid($notificationId)) {
            return collect();
        }

        return $this->scoped($project, $actor, fn () => DB::table('inspection_stage_signoffs')
            ->where('project_id', $project->id)
            ->where('notification_id', $notificationId)
            ->orderBy('recorded_at')->orderBy('id')
            ->get());
    }

    /**
     * The current state of a notification's stage: the latest sign-off per
     * company, which is what an ITR or a walk-down actually needs to show.
     */
    public function latestPerCompany(Project $project, User $actor, string $notificationId): Collection
    {
        return $this->signOffsFor($project, $actor, $notificationId)
            ->groupBy('company_id')
            ->map(fn (Collection $rows) => $rows->last())
            ->values();
    }

    private function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();

        $text = match (true) {
            str_contains($m, 'SIGNOFF_NOTIFICATION_NOT_ISSUED') => $this->msg($lang,
                'فقط اعلانِ صادرشده (ISSUED) قابل sign-off است؛ پیش‌نویس هنوز درخواستی نیست.',
                'Only an ISSUED notification can be signed off; a draft is not yet a request.'),
            str_contains($m, 'SIGNOFF_NOTIFICATION_NOT_FOUND') => $this->msg($lang,
                'این اعلان در این پروژه پیدا نشد یا برای این جلسه قابل مشاهده نیست.',
                'The notification was not found in this project or is not visible to this session.'),
            str_contains($m, 'SIGNOFF_AUTHORITY_NOT_NOMINATED') => $this->msg($lang,
                'این شخص برای امضای مراحل ITP نامزد نشده است (یا نامزدی تعلیق شده)؛ چیزی ثبت نشد.',
                'This person is not nominated to sign ITP stages (or the nomination is suspended); nothing was recorded.'),
            str_contains($m, 'SIGNOFF_HOLD_WAIVER_EMPLOYER_ONLY') => $this->msg($lang,
                'انصراف از نقطهٔ توقف (Hold) فقط با امضای کارفرماست؛ پیمانکار نمی‌تواند کنترلِ کارفرما را حذف کند.',
                'Waiving a HOLD point is the employer’s signature alone; a contractor cannot remove the employer’s control.'),
            str_contains($m, 'SIGNOFF_PREVIOUS_NOT_REJECTED') => $this->msg($lang,
                'sign-off جدید فقط می‌تواند به یک sign-off مردود از همان اعلان و همان شرکت وصل شود.',
                'A new sign-off may only chain to a REJECTED sign-off of the same notification and company.'),
            str_contains($m, 'SIGNOFF_AUTHORITY') => $this->msg($lang,
                'جلسه/امضاکننده با رکورد مطابقت ندارد؛ ثبت مستقیم توسط actor جاری الزامی است.',
                'The session actor does not match the record; sign-offs are posted directly by the current actor.'),
            str_contains($m, 'SIGNOFF_MANUAL_DIRECT_ONLY') => $this->msg($lang,
                'sign-offها فقط مستقیم ثبت می‌شوند، نه از مسیر ماشه‌های تو در تو.',
                'Sign-offs are posted directly only, never through nested trigger paths.'),
            str_contains($m, 'SIGNOFF_PROJECT_NOT_WRITABLE') => $this->msg($lang,
                'پروژه برای ثبت sign-off writable نیست (آرشیو).',
                'The project is not writable for sign-offs (archived).'),
            str_contains($m, 'append-only') => $this->msg($lang,
                'sign-offها append-only هستند؛ اصلاح یک sign-off جدیدِ زنجیره‌شده است.',
                'Sign-offs are append-only; a correction is a new chained sign-off.'),
            str_contains($m, 'stage_signoff_request_unique') => $this->msg($lang,
                'این شناسهٔ درخواست قبلاً ثبت شده است (sign-off تکراری).',
                'This request id was already recorded (duplicate sign-off).'),
            default => $this->msg($lang,
                'ثبت sign-off رد شد؛ هیچ رکوردی ذخیره نشد.',
                'The sign-off was refused; nothing was recorded.'),
        };

        return ValidationException::withMessages(['sign_off' => $text]);
    }

    private function uuidArg(string $value, string $field, array &$errors, string $lang): ?string
    {
        if (! Str::isUuid($value)) {
            $errors[$field] = $this->msg($lang, 'شناسه باید UUID باشد.', 'The id must be a UUID.');

            return null;
        }

        return $value;
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'fa' ? $fa : $en;
    }
}
