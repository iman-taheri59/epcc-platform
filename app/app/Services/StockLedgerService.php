<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * دفتر موجودی یکپارچه — the unified stock ledger (ADR-047, slice 1: service-only).
 *
 * One append-only journal answers "what does the controlled warehouse hold".
 * RECEIPT entries are NOT posted here: they are born only from the controlled
 * GRN credit trigger. This service posts the human side — opening physical
 * counts, issues, and in/out adjustments — each with dual person authority
 * (recorder ≠ approver, approver an active colleague of the holder company),
 * a mandatory reason, and a client request id for idempotency.
 *
 * WHAT IS ENFORCED, AND WHAT DELIBERATELY IS NOT (slice boundaries)
 *
 *   enforced      append-only journal, ordinal clock, database-level guards
 *   enforced      you cannot issue more than the ledger holds for the key
 *   enforced      person separation and holder-company binding for every entry
 *   NOT yet       role-scoped authority policies (who may approve what) — the
 *                 declared next slice; until then any two active colleagues of
 *                 the holder company can record/approve, and that is stated
 *                 loudly rather than dressed up as role separation
 *   NOT yet       HTTP surface/UI — service-only, like the MTO acceptance slice
 *   NOT here      the legacy warehouse core: it is gated by config flag
 *                 epcc.warehouse.legacy_writes_refused (see WarehouseService)
 */
final class StockLedgerService
{
    /** Manual entry kinds. RECEIPT is derived-only (GRN credit trigger). */
    public const MANUAL_KINDS = ['OPENING_COUNT', 'ISSUE', 'ADJUSTMENT_IN', 'ADJUSTMENT_OUT'];

    public function __construct(private readonly TenantContext $tenant) {}

    /** Runs $fn in the ledger session contract: holder company, project, stock actor. */
    public function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.stock_actor_id',true) AS actor")->actor;
            DB::select('SELECT set_config(\'epcc.stock_actor_id\',?,true)', [$actor->id]);
            try {
                return DB::transaction($fn);
            } finally {
                DB::select('SELECT set_config(\'epcc.stock_actor_id\',?,true)', [$prior ?? '']);
            }
        }, $project->id);
    }

    /**
     * Appends one manual entry. Returns the entry id.
     *
     * @param  array{kind:string,item_code:string,unit:string,quantity:string,approver_user_id:string,reason:string,request_id:string,heat_ref?:string|null,material_item_id?:string|null}  $input
     *
     * @throws ValidationException
     */
    public function post(Project $project, User $actor, array $input, string $lang = 'fa'): string
    {
        $kind = (string) ($input['kind'] ?? '');
        if (! in_array($kind, self::MANUAL_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => $this->msg($lang,
                'این نوع ورودی معتبر نیست؛ RECEIPT فقط از مسیر اعتبار GRN کنترل‌شده ثبت می‌شود.',
                'That entry kind is not valid; a RECEIPT is only produced by the controlled GRN credit path.')]);
        }

        $itemCode = trim((string) ($input['item_code'] ?? ''));
        $unit = mb_strtoupper(trim((string) ($input['unit'] ?? '')));
        $heatRaw = trim((string) ($input['heat_ref'] ?? ''));
        $heatRef = $heatRaw === '' ? null : $heatRaw;
        $quantity = (string) ($input['quantity'] ?? '');
        $reason = trim((string) ($input['reason'] ?? ''));
        $requestId = (string) ($input['request_id'] ?? '');
        $approverId = (string) ($input['approver_user_id'] ?? '');
        $materialItemId = isset($input['material_item_id']) && $input['material_item_id'] !== '' ? (string) $input['material_item_id'] : null;

        $errors = [];
        if ($itemCode === '' || mb_strlen($itemCode) > 96) {
            $errors['item_code'] = $this->msg($lang, 'شناسهٔ قلم ماده الزامی است (حداکثر ۹۶ نویسه).', 'A non-blank item code is required (at most 96 characters).');
        }
        if ($unit === '' || mb_strlen($unit) > 16) {
            $errors['unit'] = $this->msg($lang, 'واحد الزامی است (حداکثر ۱۶ نویسه).', 'A unit is required (at most 16 characters).');
        }
        if (! preg_match('/^\d{1,15}(\.\d{1,3})?$/', $quantity) || bccomp($quantity, '0', 3) <= 0) {
            $errors['quantity'] = $this->msg($lang, 'مقدار باید عددی مثبت با حداکثر سه رقم اعشار باشد.', 'Quantity must be a positive decimal with at most three fractional digits.');
        }
        if ($heatRef !== null && mb_strlen($heatRef) > 200) {
            $errors['heat_ref'] = $this->msg($lang, 'شناسهٔ heat خیلی بلند است.', 'The heat reference is too long.');
        }
        if ($reason === '' || mb_strlen($reason) > 4000) {
            $errors['reason'] = $this->msg($lang, 'دلیل ثبت الزامی است (حداکثر ۴۰۰۰ نویسه).', 'A posting reason is required (at most 4000 characters).');
        }
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');
        }
        if (! Str::isUuid($approverId)) {
            $errors['approver_user_id'] = $this->msg($lang, 'شناسهٔ تأییدکننده باید UUID باشد.', 'The approver id must be a UUID.');
        }
        if ($approverId === (string) $actor->id) {
            $errors['approver_user_id'] = $this->msg($lang, 'ثبت‌کننده نمی‌تواند تأییدکنندهٔ همان ورودی باشد.', 'The recorder cannot approve their own entry.');
        }
        if ($materialItemId !== null && ! Str::isUuid($materialItemId)) {
            $errors['material_item_id'] = $this->msg($lang, 'شناسهٔ قلم کاتالوگ باید UUID باشد.', 'The catalogue item id must be a UUID.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $kind, $itemCode, $unit, $heatRef, $quantity, $reason, $requestId, $approverId, $materialItemId, $lang): string {
            // Freshness at post time, not at form time: the approver must be an
            // active colleague of the holder company right now (the database
            // re-checks the same fact inside the guard trigger).
            $approver = User::whereKey($approverId)
                ->where('company_id', $actor->company_id)
                ->where('is_active', true)
                ->first();
            if ($approver === null) {
                throw ValidationException::withMessages(['approver_user_id' => $this->msg($lang,
                    'تأییدکننده باید کاربر فعالی از شرکت ثبت‌کننده باشد.',
                    'The approver must be an active user of the recorder\'s company.')]);
            }

            try {
                return (string) DB::table('stock_ledger_entries')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'kind' => $kind,
                    'item_code' => $itemCode,
                    'heat_ref' => $heatRef,
                    'unit' => $unit,
                    'quantity' => $quantity,
                    'material_item_id' => $materialItemId,
                    'purchase_order_item_id' => null,
                    'grn_id' => null,
                    'request_id' => $requestId,
                    'recorded_by' => $actor->id,
                    'approved_by' => $approverId,
                    'reason' => $reason,
                    'source_snapshot' => null,
                    'recorded_at' => now(),
                ], 'id');
            } catch (QueryException $e) {
                throw $this->mapFailure($e, $lang);
            }
        });
    }

    /**
     * Recorded balance for one honest key: (item code, heat or unknown, unit)
     * inside the actor's holder company. A computed answer over the journal —
     * never a stored mutable number.
     */
    public function balance(Project $project, User $actor, string $itemCode, ?string $heatRef, string $unit): string
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $itemCode, $heatRef, $unit): string {
            $q = (string) DB::selectOne('SELECT epcc_stock_balance(?,?,?,?,?) AS balance', [
                $project->id, $actor->company_id, $itemCode, $heatRef, mb_strtoupper(trim($unit)),
            ])->balance;

            // Exact decimal string, never a float round-trip: SUM over numeric(18,3)
            // keeps its scale; the empty-ledger COALESCE returns a bare integer 0.
            return str_contains($q, '.') ? $q : $q.'.000';
        });
    }

    /** The actor's company journal for the project, in ordinal order. */
    public function journal(Project $project, User $actor): \Illuminate\Support\Collection
    {
        return $this->scoped($project, $actor, fn () => DB::table('stock_ledger_entries')
            ->where('project_id', $project->id)
            ->orderBy('ordinal')
            ->get());
    }

    private function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();
        $field = 'stock_ledger';
        $text = match (true) {
            str_contains($m, 'STOCK_LEDGER_INSUFFICIENT_BALANCE') => $this->msg($lang,
                'موجودی ثبت‌شده برای این کلید (قلم/heat/واحد) کافی نیست؛ دفتر موجودی تنها حقیقت موجودی است.',
                'The recorded balance for this key (item/heat/unit) is insufficient; the ledger is the only stock truth.'),
            str_contains($m, 'STOCK_LEDGER_APPROVER_NOT_COLLEAGUE') => $this->msg($lang,
                'تأییدکننده باید کاربر فعالی از شرکت ثبت‌کننده باشد.',
                'The approver must be an active user of the recorder\'s company.'),
            str_contains($m, 'STOCK_LEDGER_PROJECT_NOT_WRITABLE') => $this->msg($lang,
                'پروژه برای ثبت موجودی writable نیست (آرشیو/بایگانی‌شده).',
                'The project is not writable for stock postings (archived).'),
            str_contains($m, 'STOCK_LEDGER_AUTHORITY') => $this->msg($lang,
                'جلسه/actor با ورودی مطابقت ندارد؛ فقط ثبت مستقیم توسط actor جاری مجاز است.',
                'The session actor does not match the entry; only direct posting by the current actor is allowed.'),
            str_contains($m, 'STOCK_LEDGER_RECEIPT_DERIVED_ONLY') => $this->msg($lang,
                'ورودی RECEIPT فقط از مسیر اعتبار GRN کنترل‌شده ساخته می‌شود.',
                'A RECEIPT entry is only produced by the controlled GRN credit path.'),
            str_contains($m, 'STOCK_LEDGER_MANUAL_DIRECT_ONLY') => $this->msg($lang,
                'ورودی دستی فقط مستقیم ثبت می‌شود، نه از مسیر ماشه‌های تو در تو.',
                'A manual entry is posted directly only, never through nested trigger paths.'),
            str_contains($m, 'append-only') => $this->msg($lang,
                'دفتر موجودی append-only است؛ اصلاح با ورودی جدید انجام می‌شود، نه ویرایش.',
                'The stock ledger is append-only; corrections are new entries, never edits.'),
            str_contains($m, 'stock_ledger_entries_project_id_request_id_unique') => $this->msg($lang,
                'این شناسهٔ درخواست قبلاً ثبت شده است (ورودی تکراری).',
                'This request id was already posted (duplicate entry).'),
            str_contains($m, 'stock clock') => $this->msg($lang,
                'ساعت ترتیبی دفتر موجودی نقض شد؛ ثبت هم‌رویداد نامعتبر.',
                'The stock ledger ordinal clock was violated; concurrent posting is invalid.'),
            default => $this->msg($lang,
                'ثبت در دفتر موجودی رد شد: اختیار، کلید یا موجودی را بازبینی کنید. هیچ ورودی‌ای ثبت نشد.',
                'The stock ledger refused the posting: recheck authority, key and balance. No entry was recorded.'),
        };
        if (str_contains($m, 'STOCK_LEDGER_APPROVER_NOT_COLLEAGUE')) {
            $field = 'approver_user_id';
        }
        if (str_contains($m, 'STOCK_LEDGER_INSUFFICIENT_BALANCE')) {
            $field = 'quantity';
        }

        return ValidationException::withMessages([$field => $text]);
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'fa' ? $fa : $en;
    }
}
