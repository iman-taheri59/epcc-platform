<?php

declare(strict_types=1);

namespace App\Services;

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

    /** A screen is not a report: the worklist is capped rather than paginated. */
    public const LOT_LIMIT = 200;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ResultRecorderPolicyService $nominations,
    ) {}

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

    /**
     * The quantity reconciliation: what the legacy warehouse said we held,
     * against what the controlled receipt we named actually attests.
     *
     * The legacy balance is the ledger's opening count for the lot's own
     * (item, heat, unit) — the amount brought across at cutover. The
     * controlled side is the quantity on the custody record named by the
     * lot's current TAKE_OVER decision.
     *
     * Three refusals are built into the report rather than left for the
     * reader to trip over: units that differ are NOT compared (converting
     * them would be the platform inventing a factor), a lot with no opening
     * count is reported as one rather than treated as zero, and a retired
     * lot is shown with the balance it released instead of vanishing. A
     * difference is not an error — it is a fact somebody must explain, and
     * guessing which side is right is exactly what this layer exists not to
     * do.
     *
     * @return list<array<string, mixed>>
     */
    public function quantityReport(Project $project, User $actor, string $lang = 'fa'): array
    {
        return $this->scoped($project, $actor, function () use ($project, $lang): array {
            $rows = DB::select(<<<'SQL'
                SELECT l.id                                   AS lot_id,
                       l.lot_code                             AS lot_code,
                       l.item_code                            AS item_code,
                       l.heat_number                          AS heat_number,
                       l.unit                                 AS lot_unit,
                       h.decision                             AS decision,
                       h.custody_id                           AS custody_id,
                       c.quantity                             AS receipt_quantity,
                       c.unit                                 AS receipt_unit,
                       COALESCE(o.quantity, 0)                AS legacy_quantity,
                       COALESCE(o.entries, 0)                 AS legacy_entries
                  FROM material_lots l
                  JOIN LATERAL (
                        SELECT r.decision, r.custody_id, r.sequence
                          FROM material_legacy_reconciliations r
                         WHERE r.lot_id = l.id AND r.project_id = l.project_id
                         ORDER BY r.sequence DESC
                         LIMIT 1
                       ) h ON true
                  LEFT JOIN piping_custody_records c
                         ON c.id = h.custody_id AND c.project_id = l.project_id
                  LEFT JOIN LATERAL (
                        SELECT SUM(e.quantity) AS quantity, count(*) AS entries
                          FROM stock_ledger_entries e
                         WHERE e.project_id = l.project_id
                           AND e.kind = 'OPENING_COUNT'
                           AND e.item_code = l.item_code
                           AND e.unit = l.unit
                           AND COALESCE(e.heat_ref, '') = COALESCE(l.heat_number, '')
                       ) o ON true
                 WHERE l.project_id = ?
                 ORDER BY l.item_code, l.lot_code
            SQL, [$project->id]);

            $labels = $this->reportLabels($lang);

            return array_map(function (object $row) use ($labels): array {
                $legacy = (string) $row->legacy_quantity;
                $receipt = $row->receipt_quantity === null ? null : (string) $row->receipt_quantity;
                $entries = (int) $row->legacy_entries;

                if ($row->decision === 'RETIRE') {
                    $verdict = 'NOT_BROUGHT_ACROSS';
                } elseif ($entries === 0) {
                    $verdict = 'NO_OPENING_COUNT';
                } elseif ($receipt !== null && (string) $row->receipt_unit !== (string) $row->lot_unit) {
                    $verdict = 'UNIT_MISMATCH';
                } else {
                    $delta = $receipt === null ? null : bcsub($receipt, $legacy, 3);
                    $verdict = $delta === null ? 'NO_OPENING_COUNT'
                        : (bccomp($delta, '0', 3) === 0 ? 'MATCH' : (bccomp($delta, '0', 3) < 0 ? 'SHORT' : 'OVER'));
                }

                return [
                    'lot_id' => (string) $row->lot_id,
                    'lot_code' => (string) $row->lot_code,
                    'item_code' => (string) $row->item_code,
                    'heat_ref' => $row->heat_number === null ? null : (string) $row->heat_number,
                    'unit' => (string) $row->lot_unit,
                    'decision' => (string) $row->decision,
                    'custody_id' => $row->custody_id === null ? null : (string) $row->custody_id,
                    'legacy_quantity' => $legacy,
                    'legacy_entries' => $entries,
                    'receipt_quantity' => $receipt,
                    'receipt_unit' => $row->receipt_unit === null ? null : (string) $row->receipt_unit,
                    'delta' => $receipt === null ? null : bcsub($receipt, $legacy, 3),
                    'verdict' => $verdict,
                    'verdict_label' => $labels[$verdict] ?? $verdict,
                ];
            }, $rows);
        });
    }

    /** @return array<string, string> */
    private function reportLabels(string $lang): array
    {
        return [
            'MATCH' => $lang === 'fa' ? 'تطبیق دارد' : 'Matches',
            'SHORT' => $lang === 'fa' ? 'کمتر از ماندهٔ قدیمی' : 'Short of the legacy balance',
            'OVER' => $lang === 'fa' ? 'بیش از ماندهٔ قدیمی' : 'More than the legacy balance',
            'UNIT_MISMATCH' => $lang === 'fa' ? 'واحدها یکی نیستند — مقایسه نشد' : 'Units differ — not compared',
            'NO_OPENING_COUNT' => $lang === 'fa' ? 'ماندهٔ قدیمی‌ای ثبت نشده' : 'No legacy opening count',
            'NOT_BROUGHT_ACROSS' => $lang === 'fa' ? 'نیامده (ماندهٔ قدیمی رها شد)' : 'Not brought across (balance released)',
        ];
    }

    /**
     * Everything the reconciliation screen needs: the cutover worklist, the
     * lots already decided, the chain for the selected one, and — the part
     * that keeps the form honest — the custody records that MAY take this lot
     * over.
     *
     * A TAKE_OVER must point at custody attesting the lot's own heat, because
     * a different heat is different steel. Offering every custody record and
     * letting the guard refuse the wrong ones would be asking someone to make
     * a decision in order to be told it was never theirs to make.
     */
    public function dashboard(Project $project, User $actor, ?string $lotId = null, string $lang = 'fa'): array
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $lotId, $lang): array {
            $pending = $this->pending($project, $actor);

            $decidedLots = DB::table('material_lots')->where('project_id', $project->id)
                ->whereIn('id', DB::table('material_legacy_reconciliations')
                    ->where('project_id', $project->id)->distinct()->pluck('lot_id'))
                ->orderBy('item_code')->orderBy('lot_code')->limit(self::LOT_LIMIT)->get();

            $chains = DB::table('material_legacy_reconciliations')->where('project_id', $project->id)
                ->whereIn('lot_id', $decidedLots->pluck('id'))->orderBy('sequence')->get()->groupBy('lot_id');

            foreach ($decidedLots as $lot) {
                $lot->decisions = $chains->get($lot->id, collect());
                // The head is the current decision; the chain behind it is the
                // record of how we got here and is never rewritten.
                $lot->decision = $lot->decisions->last();
            }

            $lot = $lotId !== null && $lotId !== ''
                ? ($pending->firstWhere('id', $lotId) ?? $decidedLots->firstWhere('id', $lotId))
                : null;

            $chain = $lot !== null && isset($lot->decisions) ? $lot->decisions : collect();

            return [
                'project' => $project,
                'pending' => $pending,
                'decided' => $decidedLots,
                'lot' => $lot,
                'chain' => $chain,
                // A later decision supersedes the head rather than editing it,
                // so the form must quote the head it was looking at.
                'head' => $chain->last()?->id,
                'custody' => $lot !== null ? $this->takeOverCandidates($project, (string) $lot->heat_number) : collect(),
                'nominated' => $this->nominations->nominationFor($project, $actor, 'reconcile_legacy'),
                'decisions' => self::DECISIONS,
                'decisionLabels' => $this->decisionLabels($lang),
                'quantityReport' => $this->quantityReport($project, $actor, $lang),
            ];
        });
    }

    /**
     * Custody that may take this lot over: the records whose effective heat
     * is the lot's own. A lot with no heat of its own may be taken over by
     * any controlled receipt — there is nothing to mismatch.
     */
    private function takeOverCandidates(Project $project, string $heat): \Illuminate\Support\Collection
    {
        $query = DB::table('piping_custody_records as r')->where('r.project_id', $project->id)
            ->select('r.id', 'r.shipment_ref', 'r.received_at')
            ->selectRaw("btrim(COALESCE(epcc_piping_effective_receipt(r.id)->>'heat_ref', '')) AS heat_ref")
            ->selectRaw("epcc_piping_effective_receipt(r.id)->>'lot_ref' AS lot_ref")
            ->orderBy('r.created_at')->orderBy('r.id')->limit(self::LOT_LIMIT);

        if ($heat !== '') {
            $query->whereRaw("btrim(COALESCE(epcc_piping_effective_receipt(r.id)->>'heat_ref', '')) = ?", [$heat]);
        }

        return $query->get();
    }

    private function decisionLabels(string $lang): array
    {
        return [
            'TAKE_OVER' => $lang === 'fa' ? 'تحویل‌گرفتن (این custody دوباره دریافتش می‌کند)' : 'Take over (this custody re-receives it)',
            'RETIRE' => $lang === 'fa' ? 'بازنشسته‌کردن (نمی‌آید، و این چرایش است)' : 'Retire (it is not coming across, and here is why)',
        ];
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
