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
 * Legacy warehouse reconciliation — ADR-048 A.4f slice 1.
 *
 * ADR-047 closed the legacy `material_lots` warehouse behind a cutoff and made
 * the GRN guard refuse any purchase order that still has legacy lots
 * (`PIPING_GRN_LEGACY_RECONCILIATION_REQUIRED`). The refusal was right — old
 * rows must not be silently mapped onto the controlled chain — but it had no
 * way through: such a project could never post a GRN against those orders at
 * all.
 *
 * A reconciliation is the way through, and it is a decision, not a migration:
 * one legacy lot is either TAKEN OVER (this custody record re-receives it) or
 * RETIRED (it is not coming across, and here is why). Item and heat are derived
 * from the lot, a TAKE_OVER must point at custody attesting the same heat, and
 * every decision is nominated, reasoned, chained and append-only.
 *
 * Declared boundaries: this reconciles the ledger side of the cutover. It does
 * not move quantities (the stock ledger does that, ADR-047) and it does not
 * touch HTTP/UI. Authority is granted through the quality register
 * (`result_recorder_policies`, action `reconcile_legacy`) rather than the receipt
 * register, because the receipt register demands a nominee for every action and a
 * project with no legacy material must never have to nominate a reconciler —
 * here, an un-nominated actor is simply refused.
 */
final class LegacyMaterialReconciliationService
{
    public const DECISIONS = ['TAKE_OVER', 'RETIRE'];

    public function __construct(private readonly TenantContext $tenant) {}

    /** Runs $fn in the receipt session contract: project, company, receipt actor. */
    private function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            // Two session contracts meet here, as in the MTR binding service: the
            // decision is gated by the results register (epcc.result_actor_id), while
            // the custody record a TAKE_OVER names is readable only by a receipt
            // actor (epcc.receipt_actor_id).
            $priorResult = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;
            $priorReceipt = DB::selectOne("SELECT current_setting('epcc.receipt_actor_id', true) AS v")->v;
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$actor->id]);
            DB::select('SELECT set_config(\'epcc.receipt_actor_id\', ?, true)', [$actor->id]);
            try {
                return DB::transaction($fn);
            } finally {
                DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$priorResult ?? '']);
                DB::select('SELECT set_config(\'epcc.receipt_actor_id\', ?, true)', [$priorReceipt ?? '']);
            }
        }, $project->id);
    }

    /**
     * Records one decision about one legacy lot.
     *
     * @param  array<string,mixed>  $input  decision, custody_id? (TAKE_OVER), reason, previous_reconciliation_id?, request_id
     *
     * @return array{id: string, decision: string, lot_id: string, custody_id: ?string, item_code: ?string, heat_ref: ?string, sequence: int}
     */
    public function reconcile(Project $project, User $actor, string $lotId, array $input, string $lang = 'fa'): array
    {
        $errors = [];
        $lot = $this->uuidArg($lotId, 'lot_id', $errors, $lang);

        $decision = strtoupper(trim((string) ($input['decision'] ?? '')));
        if (! in_array($decision, self::DECISIONS, true)) {
            $errors['decision'] = $this->msg($lang,
                'تصمیم باید یکی از این دو باشد: TAKE_OVER یا RETIRE.',
                'The decision must be one of: TAKE_OVER, RETIRE.');
            $decision = '';
        }

        $custody = null;
        if (($input['custody_id'] ?? null) !== null && trim((string) $input['custody_id']) !== '') {
            $custody = $this->uuidArg((string) $input['custody_id'], 'custody_id', $errors, $lang);
        }
        if ($decision === 'TAKE_OVER' && $custody === null) {
            $errors['custody_id'] = $this->msg($lang,
                'تحویل‌گرفتنِ یک lot قدیمی باید نامِ رکورد custody ای باشد که دوباره آن را دریافت می‌کند.',
                'Taking a legacy lot over must name the custody record that re-receives it.');
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 4000) {
            $errors['reason'] = $this->msg($lang,
                'هر تصمیمی دربارهٔ متریال قدیمی باید مستدل باشد؛ دلیل الزامی است (حداکثر ۴۰۰۰ کاراکتر).',
                'Every decision about legacy material must be reasoned; the reason is required (at most 4000 characters).');
        }

        $previous = null;
        if (($input['previous_reconciliation_id'] ?? null) !== null && trim((string) $input['previous_reconciliation_id']) !== '') {
            $previous = $this->uuidArg((string) $input['previous_reconciliation_id'], 'previous_reconciliation_id', $errors, $lang);
        }

        $requestId = trim((string) ($input['request_id'] ?? ''));
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $lot, $decision, $custody, $reason, $previous, $requestId, $lang): array {
            try {
                $id = (string) DB::table('material_legacy_reconciliations')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'lot_id' => $lot,
                    'custody_id' => $custody,
                    'decision' => $decision,
                    // Derived by the guard from the lot itself.
                    'item_code' => null,
                    'heat_ref' => null,
                    'reason' => $reason,
                    'policy_id' => $lot,
                    'decided_by' => $actor->id,
                    'sequence' => 0,
                    'previous_reconciliation_id' => $previous,
                    'request_id' => $requestId,
                ], 'id');
            } catch (QueryException $e) {
                throw $this->mapFailure($e, $lang);
            }

            $row = DB::table('material_legacy_reconciliations')->where('id', $id)->first();

            return [
                'id' => $id,
                'decision' => (string) $row->decision,
                'lot_id' => (string) $row->lot_id,
                'custody_id' => $row->custody_id === null ? null : (string) $row->custody_id,
                'item_code' => $row->item_code === null ? null : (string) $row->item_code,
                'heat_ref' => $row->heat_ref === null ? null : (string) $row->heat_ref,
                'sequence' => (int) $row->sequence,
            ];
        });
    }

    /** The decision chain for one lot, oldest first. */
    public function reconciliationsForLot(Project $project, User $actor, string $lotId): Collection
    {
        if (! Str::isUuid($lotId)) {
            return collect();
        }

        return $this->scoped($project, $actor, fn () => DB::table('material_legacy_reconciliations')
            ->where('project_id', $project->id)
            ->where('lot_id', $lotId)
            ->orderBy('sequence')
            ->get());
    }

    /**
     * The cutover worklist: legacy lots that still block a controlled GRN
     * because nobody has decided anything about them.
     */
    public function pending(Project $project, User $actor): Collection
    {
        return $this->scoped($project, $actor, fn () => DB::table('material_lots as l')
            ->where('l.project_id', $project->id)
            ->whereNotExists(function ($q) use ($project): void {
                $q->select(DB::raw(1))
                    ->from('material_legacy_reconciliations as r')
                    ->whereColumn('r.lot_id', 'l.id')
                    ->where('r.project_id', $project->id);
            })
            ->orderBy('l.item_code')->orderBy('l.lot_code')
            ->get(['l.id', 'l.lot_code', 'l.item_code', 'l.heat_number', 'l.unit', 'l.purchase_order_id']));
    }

    /** Whether a legacy lot still blocks the controlled chain. */
    public function isReconciled(Project $project, User $actor, string $lotId): bool
    {
        return $this->reconciliationsForLot($project, $actor, $lotId)->isNotEmpty();
    }

    public function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();

        $text = match (true) {
            str_contains($m, 'LEGACY_RECONCILE_NOT_NOMINATED') => $this->msg($lang,
                'تصمیم دربارهٔ متریال قدیمی نیازمند نامزدی است؛ شما برای این کار نامزد نشده‌اید.',
                'Deciding about legacy material requires a nomination; you are not nominated for it.'),
            str_contains($m, 'LEGACY_LOT_NOT_FOUND') => $this->msg($lang,
                'این lot قدیمی در این پروژه یافت نشد یا برای این نشست قابل رؤیت نیست.',
                'The legacy lot was not found in this project or is not visible to this session.'),
            str_contains($m, 'LEGACY_CUSTODY_NOT_FOUND') => $this->msg($lang,
                'رکورد custody ای که نام بردید در این پروژه نیست.',
                'The custody record you named is not in this project.'),
            str_contains($m, 'LEGACY_HEAT_MISMATCH') => $this->msg($lang,
                'heat ی این lot قدیمی با heat ی که آن custody تأیید می‌کند یکی نیست؛ متریال دیگری است.',
                'This lot\'s heat differs from the heat that custody attests; it is not the same material.'),
            str_contains($m, 'LEGACY_RECONCILE_STALE') => $this->msg($lang,
                'این lot پیش‌تر درباره‌اش تصمیم گرفته شده؛ تصمیمِ جدید باید به آخرین تصمیم زنجیره شود.',
                'A decision already exists for this lot; a new one must chain to the latest.'),
            str_contains($m, 'legacy_reconcile_request_unique') => $this->msg($lang,
                'این درخواست قبلاً ثبت شده است؛ تصمیم تکراری ثبت نمی‌شود.',
                'That request id was already recorded; the decision was not duplicated.'),
            str_contains($m, 'LEGACY_RECONCILE_AUTHORITY') => $this->msg($lang,
                'تصمیم باید به نامِ شرکت و شخصِ جاری باشد.',
                'A reconciliation must be made by the current company and actor.'),
            default => $this->msg($lang,
                'ثبتِ تصمیم رد شد؛ هیچ رکوردی ذخیره نشد.',
                'The reconciliation was refused; nothing was recorded.'),
        };

        return ValidationException::withMessages(['reconciliation' => $text]);
    }

    private function uuidArg(string $value, string $field, array &$errors, string $lang): ?string
    {
        $v = trim($value);
        if (! Str::isUuid($v)) {
            $errors[$field] = $this->msg($lang, 'شناسه معتبر نیست.', 'The identifier is not valid.');

            return null;
        }

        return $v;
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'en' ? $en : $fa;
    }
}
