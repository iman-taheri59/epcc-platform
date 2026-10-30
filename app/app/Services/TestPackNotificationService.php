<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * ADR-048 A.5b slice 1 — the notification<->test-pack gate.
 *
 * A sign-off register that gates nothing is a diary. Since A.4d a stage can be
 * signed off and since A.4h slice 2 it can be signed from a screen, but a pack
 * could still be marked TESTED and then ACCEPTED while the client's HOLD point
 * on that very test sat unsigned — or sat REJECTED.
 *
 * This service owns the two halves that make the sign-off register load
 * bearing: the link ("this notice was raised about this pack") and the reading
 * of what a notice now means for the work (`epcc_pack_notice_state`, the one
 * function the gate, the dossier and this screen all ask, so they cannot
 * disagree).
 *
 * The gate itself lives in the database, like every other rule here: this
 * service writes the link and reports the state, and the trigger on
 * `piping_test_packs` refuses the claim.
 */
final class TestPackNotificationService
{
    /** A screen is not a report: the lists are capped rather than paginated. */
    public const NOTICE_LIMIT = 100;

    public function __construct(private readonly TenantContext $tenant) {}

    /** Runs $fn in the results session contract: project, company, actor. */
    private function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            // The link row carries `linked_by`, which the guard matches against
            // the results actor — the same contract every other quality table
            // writes under.
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$actor->id]);

            try {
                // Nested on purpose: this is a savepoint, so a guard refusal
                // rolls back to here instead of aborting the session
                // transaction — which the restore below still needs.
                return DB::transaction($fn);
            } finally {
                DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$prior ?? '']);
            }
        }, $project->id);
    }

    /**
     * Links one notice to one pack. Append-only, and there is no unlink: a
     * withdrawn request is already a cancellation, and the gate honours it.
     *
     * @return array{id: string, hold_state: string, number: string}
     *
     * @throws ValidationException
     */
    public function link(
        Project $project,
        User $actor,
        string $packId,
        string $notificationId,
        string $requestId,
        string $lang = 'fa',
    ): array {
        $errors = [];

        if (! Str::isUuid($packId)) {
            $errors['test_pack_id'] = $this->msg($lang,
                'شناسهٔ بستهٔ آزمون باید UUID باشد.',
                'The test pack id must be a UUID.');
        }
        if (! Str::isUuid($notificationId)) {
            $errors['notification_id'] = $this->msg($lang,
                'شناسهٔ اعلان باید UUID باشد.',
                'The notification id must be a UUID.');
        }
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang,
                'شناسهٔ درخواست باید UUID باشد.',
                'The request id must be a UUID.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $packId, $notificationId, $requestId, $lang): array {
            try {
                $id = (string) DB::table('piping_test_pack_notifications')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'test_pack_id' => $packId,
                    'notification_id' => $notificationId,
                    'linked_by' => $actor->id,
                    'linked_at' => now(),
                    'request_id' => $requestId,
                ], 'id');
            } catch (QueryException $e) {
                throw ValidationException::withMessages(['notification_id' => $this->mapFailure($e, $lang)]);
            }

            return [
                'id' => $id,
                'hold_state' => $this->holdState($project, $notificationId),
                'number' => (string) DB::table('inspection_notifications')
                    ->where('project_id', $project->id)->where('id', $notificationId)
                    ->value('number'),
            ];
        });
    }

    /**
     * The notices linked to this pack, with what each one now means for the
     * work. The state is read from the same SQL function the gate uses, so the
     * screen cannot claim a hold is cleared where the pack transition would be
     * refused.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forPack(Project $project, User $actor, string $packId, string $lang = 'fa'): Collection
    {
        if (! Str::isUuid($packId)) {
            return collect();
        }

        return $this->scoped($project, $actor, function () use ($project, $packId, $lang): Collection {
            $rows = DB::table('piping_test_pack_notifications as ln')
                ->join('inspection_notifications as n', fn ($j) => $j
                    ->on('n.id', '=', 'ln.notification_id')
                    ->whereColumn('n.project_id', 'ln.project_id'))
                ->join('inspection_itp_stages as s', 's.id', '=', 'n.stage_id')
                ->where('ln.project_id', $project->id)
                ->where('ln.test_pack_id', $packId)
                ->orderBy('s.sequence')->orderBy('n.number')
                ->limit(self::NOTICE_LIMIT)
                ->get([
                    'ln.id', 'ln.notification_id', 'ln.linked_at', 'ln.linked_by',
                    'n.number', 'n.state', 'n.work_reference', 'n.scheduled_at',
                    's.point', 's.sequence as stage_sequence',
                    's.description_en as stage_description_en',
                    's.description_fa as stage_description_fa',
                ]);

            if ($rows->isEmpty()) {
                return collect();
            }

            $ids = $rows->pluck('notification_id')->all();

            // Cancellations: a withdrawn request is not a question anybody is
            // answering, and the gate treats it that way too.
            $cancelled = DB::table('inspection_notification_cancellations')
                ->where('project_id', $project->id)
                ->whereIn('notification_id', $ids)
                ->pluck('notification_id')
                ->flip();

            $signoffs = DB::table('inspection_stage_signoffs')
                ->where('project_id', $project->id)
                ->whereIn('notification_id', $ids)
                ->orderBy('recorded_at')->orderBy('id')
                ->get(['id', 'notification_id', 'company_id', 'outcome', 'point', 'reason', 'signed_at', 'recorded_at'])
                ->groupBy('notification_id');

            $ownerCompanyId = (string) $project->owner_company_id;

            return $rows->map(function (object $row) use ($project, $cancelled, $signoffs, $ownerCompanyId, $lang): array {
                $state = $this->holdState($project, (string) $row->notification_id);

                return [
                    'id' => (string) $row->id,
                    'notification_id' => (string) $row->notification_id,
                    'number' => (string) $row->number,
                    'state' => (string) $row->state,
                    'point' => trim((string) $row->point),
                    'stage_sequence' => (int) $row->stage_sequence,
                    'stage_description' => $lang === 'fa'
                        ? (string) ($row->stage_description_fa ?: $row->stage_description_en)
                        : (string) ($row->stage_description_en ?: $row->stage_description_fa),
                    'work_reference' => (string) $row->work_reference,
                    'scheduled_at' => (string) $row->scheduled_at,
                    'linked_at' => (string) $row->linked_at,
                    'cancelled' => $cancelled->has($row->notification_id),
                    'hold_state' => $state,
                    // A hold is the employer's control: whether the employer
                    // itself signed is the part a reader is looking for.
                    'signoffs' => ($signoffs->get($row->notification_id) ?? collect())
                        ->map(fn (object $s): array => [
                            'id' => (string) $s->id,
                            'outcome' => (string) $s->outcome,
                            'point' => trim((string) $s->point),
                            'reason' => $s->reason !== null ? (string) $s->reason : null,
                            'signed_at' => (string) $s->signed_at,
                            'is_employer' => (string) $s->company_id === $ownerCompanyId,
                        ])
                        ->values()
                        ->all(),
                ];
            });
        });
    }

    /**
     * The notices this pack could still be linked to: issued, not cancelled,
     * visible to this session, and not already linked. Offering the rest and
     * letting the guard refuse them is the discovery-on-submit pattern these
     * screens keep refusing to repeat.
     *
     * @return Collection<int, object>
     */
    public function eligibleForPack(Project $project, User $actor, string $packId): Collection
    {
        if (! Str::isUuid($packId)) {
            return collect();
        }

        return $this->scoped($project, $actor, fn () => DB::table('inspection_notifications as n')
            ->join('inspection_itp_stages as s', 's.id', '=', 'n.stage_id')
            ->where('n.project_id', $project->id)
            ->where('n.state', 'ISSUED')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('inspection_notification_cancellations as c')
                ->whereColumn('c.notification_id', 'n.id')
                ->whereColumn('c.project_id', 'n.project_id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('piping_test_pack_notifications as ln')
                ->whereColumn('ln.notification_id', 'n.id')
                ->where('ln.project_id', $project->id)
                ->where('ln.test_pack_id', $packId))
            ->orderBy('s.sequence')->orderBy('n.number')
            ->limit(self::NOTICE_LIMIT)
            ->get([
                'n.id', 'n.number', 'n.work_reference', 'n.scheduled_at',
                's.point', 's.sequence as stage_sequence',
                's.description_en as stage_description_en',
                's.description_fa as stage_description_fa',
            ]));
    }

    /**
     * What one notice means for the work: CLEARED, NOT_SIGNED, REJECTED,
     * CANCELLED, NOT_ISSUED or NOT_VISIBLE. One function, three callers, so
     * the screen, the dossier and the gate cannot disagree.
     */
    public function holdState(Project $project, string $notificationId): string
    {
        if (! Str::isUuid($notificationId)) {
            return 'NOT_VISIBLE';
        }

        return (string) DB::selectOne(
            'SELECT epcc_pack_notice_state(?, ?) AS s',
            [$project->id, $notificationId],
        )->s;
    }

    private function mapFailure(QueryException $e, string $lang): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'PACK_NOTICE_NOT_ISSUED') => $this->msg($lang,
                'فقط اعلانِ صادرشده قابل پیوند است؛ پیش‌نویس هنوز درخواستی نیست.',
                'Only an ISSUED notice can be linked; a draft is not yet a request.'),
            str_contains($m, 'PACK_NOTICE_CANCELLED') => $this->msg($lang,
                'این اعلان لغو شده است؛ درخواستی که پس گرفته شده به بسته پیوند نمی‌خورد.',
                'That notice has been cancelled; a withdrawn request is not linked to a pack.'),
            str_contains($m, 'PACK_NOTICE_NOT_VISIBLE') => $this->msg($lang,
                'این اعلان برای این شرکت قابل مشاهده نیست.',
                'That notice is not visible to this company.'),
            str_contains($m, 'PACK_NOTICE_NOT_YOURS') => $this->msg($lang,
                'فقط شرکتِ صاحبِ بسته یا کارفرما می‌تواند اعلانی را به این بسته پیوند بزند.',
                'Only the company the pack belongs to, or the project employer, may link a notice to it.'),
            str_contains($m, 'PACK_NOTICE_PACK_NOT_FOUND') => $this->msg($lang,
                'این بستهٔ آزمون در این پروژه پیدا نشد.',
                'That test pack was not found in this project.'),
            str_contains($m, 'pack_notice_unique') => $this->msg($lang,
                'این اعلان از پیش به این بسته پیوند خورده است.',
                'That notice is already linked to this pack.'),
            str_contains($m, 'pack_notice_request_unique') => $this->msg($lang,
                'این درخواست قبلاً ثبت شده است.',
                'That request has already been recorded.'),
            str_contains($m, 'PACK_NOTICE_PROJECT_NOT_WRITABLE') => $this->msg($lang,
                'پروژه برای این تغییر writable نیست (آرشیو).',
                'The project is not writable for this change (archived).'),
            default => $this->msg($lang,
                'پیوندِ اعلان به بسته رد شد.',
                'The notice could not be linked to this pack.'),
        };
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'fa' ? $fa : $en;
    }
}
