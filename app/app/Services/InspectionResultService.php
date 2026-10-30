<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Joint;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Support\TenantContext;
use App\Support\Units\UnitRegistry;
use App\Support\Units\Exceptions\UnknownUnitException;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * نتایج بازرسی و آزمون — inspection/test RESULT records (ADR-048, slice 1).
 *
 * Four append-only record families close the "announcement is not a result"
 * gap: weld records per fitted joint (stamp-code identity, repair lineage),
 * per-method NDT results (RT/UT/MT/PT/VT) with re-examination lineage,
 * hydro/pneumatic results per ready test pack with retest lineage, and MTR /
 * EN 10204 certificates bound to heat (+lot) with a named issuer.
 *
 * Provenance honesty (ADR-031 two-mode precedent): mode IN_SYSTEM or
 * EXTERNAL_REGISTER; an external record must name the performing organisation,
 * the person and the report reference, and is stored as registered evidence —
 * never presented as platform-collected.
 *
 * SLICE BOUNDARIES (declared, not hidden):
 *   - ITP hold/witness sign-offs on notification stages: slice 2 (A.4b).
 *   - joint.state stays FITTED; weld/NDT status is derived, never stamped.
 *   - Test-pack state transitions are not yet gated on results.
 *   - No role-scoped authority policies yet: any project-member user of the
 *     recording company may post; person identity is frozen per record.
 */
final class InspectionResultService
{
    public const WELD_KINDS = ['WELD', 'REPAIR_WELD'];

    public const NDT_METHODS = ['RT', 'UT', 'MT', 'PT', 'VT'];

    public const TEST_TYPES = ['HYDROSTATIC', 'PNEUMATIC'];

    public const CERT_TYPES = ['EN10204_3_1', 'EN10204_3_2', 'MILL_MTR', 'OTHER'];

    public const MODES = ['IN_SYSTEM', 'EXTERNAL_REGISTER'];

    /** The three actions the results screen can take, each nomination-gated. */
    public const ACTIONS = ['record_weld', 'record_ndt', 'record_pack_result'];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly UnitRegistry $units,
        private readonly ResultRecorderPolicyService $nominations,
    ) {}

    /** Runs $fn in the result session contract: project, recording company, result actor. */
    public function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id',true) AS actor")->actor;
            DB::select('SELECT set_config(\'epcc.result_actor_id\',?,true)', [$actor->id]);
            try {
                return DB::transaction($fn);
            } finally {
                DB::select('SELECT set_config(\'epcc.result_actor_id\',?,true)', [$prior ?? '']);
            }
        }, $project->id);
    }

    /**
     * Records that a fitted joint was welded (or repair-welded).
     *
     * @throws ValidationException
     */
    public function recordWeld(Project $project, User $actor, string $jointId, array $input, string $lang = 'fa'): string
    {
        $kind = strtoupper(trim((string) ($input['kind'] ?? 'WELD')));
        $errors = [];
        if (! in_array($kind, self::WELD_KINDS, true)) {
            $errors['kind'] = $this->enumMsg($lang, 'WELD/REPAIR_WELD');
        }
        $code = $this->stampCode($input, 'welded_by_code', $errors, $lang);
        $at = $this->moment($input, 'welded_at', $errors, $lang);
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($kind === 'REPAIR_WELD' && ($reason === '' || mb_strlen($reason) > 4000)) {
            $errors['reason'] = $this->msg($lang, 'جوش ترمیمی بدون دلیل ثبت نمی‌شود.', 'A repair weld is never recorded without a reason.');
        }
        $mode = $this->mode($input, $errors, $lang);
        $this->externalFields($input, $mode, $errors, $lang);
        $requestId = $this->requestId($input, $errors, $lang);
        $joint = $this->uuidArg($jointId, 'joint_id', $errors, $lang);
        $this->guard($errors);

        return $this->insert($project, $actor, 'joint_weld_records', $lang, [
            'joint_id' => $joint,
            'kind' => $kind,
            'welded_by_code' => $code,
            'welded_by_user' => $this->optionalUuid($input, 'welded_by_user_id'),
            'weld_process' => $this->optionalString($input, 'weld_process', 32),
            'consumable_lot_ref' => $this->optionalString($input, 'consumable_lot_ref', 128),
            'welded_at' => $at,
            'reason' => $reason === '' ? null : $reason,
            'mode' => $mode,
            'performer_org' => $this->externalValue($input, 'performer_org'),
            'performer_name' => $this->externalValue($input, 'performer_name'),
            'report_ref' => $this->externalValue($input, 'report_ref'),
            'request_id' => $requestId,
        ]);
    }

    /**
     * Records one NDT examination result for a welded joint.
     *
     * @throws ValidationException
     */
    public function recordNdt(Project $project, User $actor, string $jointId, array $input, string $lang = 'fa'): string
    {
        $method = strtoupper(trim((string) ($input['method'] ?? '')));
        $result = strtoupper(trim((string) ($input['result'] ?? '')));
        $errors = [];
        if (! in_array($method, self::NDT_METHODS, true)) {
            $errors['method'] = $this->enumMsg($lang, implode('/', self::NDT_METHODS));
        }
        if (! in_array($result, ['ACCEPTED', 'REJECTED'], true)) {
            $errors['result'] = $this->enumMsg($lang, 'ACCEPTED/REJECTED');
        }
        $rejectReason = trim((string) ($input['reject_reason'] ?? ''));
        if ($result === 'REJECTED' && ($rejectReason === '' || mb_strlen($rejectReason) > 4000)) {
            $errors['reject_reason'] = $this->msg($lang, 'نتیجهٔ مردود بدون شرح نقص ثبت نمی‌شود.', 'A rejected result is never recorded without a defect description.');
        }
        if ($result === 'ACCEPTED' && $rejectReason !== '') {
            $errors['reject_reason'] = $this->msg($lang, 'نتیجهٔ پذیرفته شرح نقص نمی‌گیرد.', 'An accepted result carries no defect description.');
        }
        $code = $this->stampCode($input, 'performed_by_code', $errors, $lang);
        $at = $this->moment($input, 'performed_at', $errors, $lang);
        $mode = $this->mode($input, $errors, $lang);
        $this->externalFields($input, $mode, $errors, $lang);
        $requestId = $this->requestId($input, $errors, $lang);
        $previous = $this->optionalUuid($input, 'previous_result_id');
        if (($input['previous_result_id'] ?? null) !== null && ! Str::isUuid((string) $input['previous_result_id'])) {
            $errors['previous_result_id'] = $this->msg($lang, 'ارجاع بازآزمایی باید UUID باشد.', 'The re-examination reference must be a UUID.');
        }
        $joint = $this->uuidArg($jointId, 'joint_id', $errors, $lang);
        $this->guard($errors);

        return $this->insert($project, $actor, 'joint_ndt_results', $lang, [
            'joint_id' => $joint,
            'method' => $method,
            'result' => $result,
            'reject_reason' => $rejectReason === '' ? null : $rejectReason,
            'previous_result_id' => $previous,
            'performed_by_code' => $code,
            'performed_by_user' => $this->optionalUuid($input, 'performed_by_user_id'),
            'performed_at' => $at,
            'technique_ref' => $this->optionalString($input, 'technique_ref', 128),
            'acceptance_criteria_ref' => $this->optionalString($input, 'acceptance_criteria_ref', 128),
            'mode' => $mode,
            'performer_org' => $this->externalValue($input, 'performer_org'),
            'performer_name' => $this->externalValue($input, 'performer_name'),
            'report_ref' => $this->externalValue($input, 'report_ref'),
            'report_document_id' => $this->optionalUuid($input, 'report_document_id'),
            'request_id' => $requestId,
        ]);
    }

    /**
     * Records one pressure/leak test result for a ready test pack.
     *
     * @throws ValidationException
     */
    public function recordTestResult(Project $project, User $actor, string $testPackId, array $input, string $lang = 'fa'): string
    {
        $type = strtoupper(trim((string) ($input['test_type'] ?? '')));
        $result = strtoupper(trim((string) ($input['result'] ?? '')));
        $errors = [];
        if (! in_array($type, self::TEST_TYPES, true)) {
            $errors['test_type'] = $this->enumMsg($lang, implode('/', self::TEST_TYPES));
        }
        if (! in_array($result, ['PASS', 'FAIL'], true)) {
            $errors['result'] = $this->enumMsg($lang, 'PASS/FAIL');
        }
        $medium = trim((string) ($input['medium'] ?? ''));
        if ($medium === '' || mb_strlen($medium) > 32) {
            $errors['medium'] = $this->msg($lang, 'سیال آزمون الزامی است.', 'The test medium is required.');
        }
        $pressure = (string) ($input['test_pressure'] ?? '');
        if (! preg_match('/^\d{1,9}(\.\d{1,3})?$/', $pressure) || bccomp($pressure, '0', 3) <= 0) {
            $errors['test_pressure'] = $this->msg($lang, 'فشار آزمون باید عددی مثبت باشد.', 'The test pressure must be a positive decimal.');
        }
        $unit = strtoupper(trim((string) ($input['pressure_unit'] ?? '')));
        if ($unit === '' || mb_strlen($unit) > 12) {
            $errors['pressure_unit'] = $this->msg($lang, 'واحد فشار الزامی است.', 'The pressure unit is required.');
        } else {
            // A unit this platform does not know is rejected outright; one it
            // knows but that is NOT a pressure (a temperature, a length) is
            // rejected too - "21.75 C" is not a hydrostatic test. The database
            // holds the same rule, so a direct INSERT cannot slip past it.
            try {
                $resolved = $this->units->resolve($unit);
            } catch (UnknownUnitException) {
                $resolved = null;
            }
            if ($resolved === null) {
                $errors['pressure_unit'] = $this->msg($lang,
                    'واحد فشار ناشناخته است؛ فقط واحدهای تعریف‌شدهٔ registry مجازند.',
                    'Unknown pressure unit; only units defined in the registry are allowed.');
            } elseif (strtoupper((string) $resolved['quantity']) !== 'PRESSURE') {
                $errors['pressure_unit'] = $this->msg($lang,
                    'این واحد متعلق به کمیت فشار نیست؛ یک دما یا طول را نمی‌توان به‌عنوان فشار ثبت کرد.',
                    'That unit is not a pressure quantity; a temperature or length cannot be recorded as a pressure.');
            }
        }
        $hold = $input['hold_duration_minutes'] ?? null;
        if (! is_int($hold) && ! (is_string($hold) && ctype_digit($hold))) {
            $errors['hold_duration_minutes'] = $this->msg($lang, 'مدت نگهداری (دقیقه) باید عدد صحیح نامنفی باشد.', 'The hold duration (minutes) must be a non-negative integer.');
        }
        $started = $this->moment($input, 'started_at', $errors, $lang);
        $completed = $this->moment($input, 'completed_at', $errors, $lang);
        if ($started !== null && $completed !== null && $completed < $started) {
            $errors['completed_at'] = $this->msg($lang, 'پایان آزمون نمی‌تواند پیش از شروع آن باشد.', 'The test cannot complete before it starts.');
        }
        $failureReason = trim((string) ($input['failure_reason'] ?? ''));
        if ($result === 'FAIL' && ($failureReason === '' || mb_strlen($failureReason) > 4000)) {
            $errors['failure_reason'] = $this->msg($lang, 'نتیجهٔ ناموفق بدون شرح علت ثبت نمی‌شود.', 'A failed result is never recorded without a cause description.');
        }
        if ($result === 'PASS' && $failureReason !== '') {
            $errors['failure_reason'] = $this->msg($lang, 'نتیجهٔ موفق شرح علت نمی‌گیرد.', 'A passed result carries no cause description.');
        }
        $mode = $this->mode($input, $errors, $lang);
        $this->externalFields($input, $mode, $errors, $lang);
        $requestId = $this->requestId($input, $errors, $lang);
        $previous = $this->optionalUuid($input, 'previous_result_id');
        if (($input['previous_result_id'] ?? null) !== null && ! Str::isUuid((string) $input['previous_result_id'])) {
            $errors['previous_result_id'] = $this->msg($lang, 'ارجاع آزمون مجدد باید UUID باشد.', 'The retest reference must be a UUID.');
        }
        $pack = $this->uuidArg($testPackId, 'test_pack_id', $errors, $lang);
        $this->guard($errors);

        return $this->insert($project, $actor, 'test_pack_results', $lang, [
            'test_pack_id' => $pack,
            'test_type' => $type,
            'medium' => $medium,
            'test_pressure' => $pressure,
            'pressure_unit' => $unit,
            'hold_duration_minutes' => (int) $hold,
            'started_at' => $started,
            'completed_at' => $completed,
            'result' => $result,
            'failure_reason' => $failureReason === '' ? null : $failureReason,
            'previous_result_id' => $previous,
            'mode' => $mode,
            'performer_org' => $this->externalValue($input, 'performer_org'),
            'performer_name' => $this->externalValue($input, 'performer_name'),
            'report_ref' => $this->externalValue($input, 'report_ref'),
            'request_id' => $requestId,
        ]);
    }

    /**
     * Registers one material test certificate (MTR / EN 10204) against a heat.
     *
     * @throws ValidationException
     */
    public function registerCertificate(Project $project, User $actor, array $input, string $lang = 'fa'): string
    {
        $type = strtoupper(trim((string) ($input['certificate_type'] ?? '')));
        $errors = [];
        if (! in_array($type, self::CERT_TYPES, true)) {
            $errors['certificate_type'] = $this->enumMsg($lang, implode('/', self::CERT_TYPES));
        }
        $heat = trim((string) ($input['heat_ref'] ?? ''));
        if ($heat === '' || mb_strlen($heat) > 200) {
            $errors['heat_ref'] = $this->msg($lang, 'شناسهٔ heat برای گواهی ماده الزامی است.', 'The heat reference is required for a material certificate.');
        }
        $ref = trim((string) ($input['certificate_ref'] ?? ''));
        if ($ref === '' || mb_strlen($ref) > 200) {
            $errors['certificate_ref'] = $this->msg($lang, 'شمارهٔ گواهی الزامی است.', 'The certificate number is required.');
        }
        $issuer = trim((string) ($input['issuer_name'] ?? ''));
        if ($issuer === '' || mb_strlen($issuer) > 4000) {
            $errors['issuer_name'] = $this->msg($lang, 'صادرکنندهٔ گواهی باید نام‌برده شود؛ گواهی بی‌نام fiction است.', 'The certificate issuer must be named; an anonymous certificate is fiction.');
        }
        $lot = trim((string) ($input['lot_ref'] ?? ''));
        if (mb_strlen($lot) > 200) {
            $errors['lot_ref'] = $this->msg($lang, 'شناسهٔ lot خیلی بلند است.', 'The lot reference is too long.');
        }
        $requestId = $this->requestId($input, $errors, $lang);
        $this->guard($errors);

        return $this->insert($project, $actor, 'material_certificates', $lang, [
            'heat_ref' => $heat,
            'lot_ref' => $lot === '' ? null : $lot,
            'certificate_type' => $type,
            'certificate_ref' => $ref,
            'issuer_name' => $issuer,
            'vendor_company_id' => $this->optionalUuid($input, 'vendor_company_id'),
            'material_item_id' => $this->optionalUuid($input, 'material_item_id'),
            'document_id' => $this->optionalUuid($input, 'document_id'),
            'request_id' => $requestId,
        ], 'registered_by');
    }

    // ---------------------------------------------------------------- readers

    /** Weld + NDT history of one joint, oldest first. */
    /**
     * Everything the results screen needs, and nothing it must not have.
     *
     * The screen is built around the nomination register: each of the three
     * forms is offered only when `epcc_result_nomination()` says this actor is
     * nominated for that action, and otherwise says so out loud instead of
     * offering a form that would be refused. The answer comes from the same
     * function the guards call, so the page and the database cannot disagree
     * about what may be recorded.
     *
     * @return array{project: Project, joints: \Illuminate\Support\Collection, packs: \Illuminate\Support\Collection, joint: ?Joint, history: ?array, pack: ?PipingTestPack, packResults: ?\Illuminate\Support\Collection, nominations: array<string,?string>, pressureUnits: array<int,array<string,mixed>>}
     */
    public function dashboard(Project $project, User $actor, ?string $jointId = null, ?string $packId = null, string $lang = 'fa'): array
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $jointId, $packId, $lang): array {
            $joints = Joint::with('line')->where('project_id', $project->id)
                ->orderBy('joint_seq')->orderBy('id')->limit(500)->get();

            // A pack that is still DRAFT cannot be tested and one that is
            // ACCEPTED is finished; offering either would invite a refusal.
            $packs = PipingTestPack::where('project_id', $project->id)
                ->whereIn('state', [PipingTestPack::READY, PipingTestPack::TESTED])
                ->orderBy('code')->limit(500)->get();

            $joint = $jointId !== null && $jointId !== ''
                ? $joints->firstWhere('id', $jointId)
                : null;
            $pack = $packId !== null && $packId !== ''
                ? $packs->firstWhere('id', $packId)
                : null;

            $nominations = [];
            foreach (self::ACTIONS as $action) {
                $nominations[$action] = $this->nominations->nominationFor($project, $actor, $action);
            }

            return [
                'project' => $project,
                'joints' => $joints,
                'packs' => $packs,
                'joint' => $joint,
                'history' => $joint !== null ? $this->jointHistory($project, $actor, (string) $joint->id) : null,
                'pack' => $pack,
                'packResults' => $pack !== null ? $this->packResults($project, $actor, (string) $pack->id) : null,
                'nominations' => $nominations,
                'pressureUnits' => $this->units->selectableUnitsFor('PRESSURE'),
                'pressureUnitLabels' => collect($this->units->selectableUnitsFor('PRESSURE'))
                    ->mapWithKeys(fn (array $unit): array => [(string) $unit['code'] => $this->units->label((string) $unit['code'], $lang)])
                    ->all(),
            ];
        });
    }

    public function jointHistory(Project $project, User $actor, string $jointId): array
    {
        return $this->scoped($project, $actor, fn () => [
            'welds' => DB::table('joint_weld_records')->where('project_id', $project->id)
                ->where('joint_id', $jointId)->orderBy('recorded_at')->orderBy('id')->get(),
            'ndt' => DB::table('joint_ndt_results')->where('project_id', $project->id)
                ->where('joint_id', $jointId)->orderBy('recorded_at')->orderBy('id')->get(),
        ]);
    }

    /** All results recorded against one test pack, oldest first. */
    public function packResults(Project $project, User $actor, string $testPackId): \Illuminate\Support\Collection
    {
        return $this->scoped($project, $actor, fn () => DB::table('test_pack_results')
            ->where('project_id', $project->id)->where('test_pack_id', $testPackId)
            ->orderBy('recorded_at')->orderBy('id')->get());
    }

    /** Certificates registered against one EXACT heat string (no fuzzy merging). */
    public function certificatesForHeat(Project $project, User $actor, string $heatRef): \Illuminate\Support\Collection
    {
        return $this->scoped($project, $actor, fn () => DB::table('material_certificates')
            ->where('project_id', $project->id)->where('heat_ref', $heatRef)
            ->orderBy('registered_at')->orderBy('id')->get());
    }

    // ------------------------------------------------------------- plumbing

    private function insert(Project $project, User $actor, string $table, string $lang, array $row, string $actorColumn = 'recorded_by'): string
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $table, $lang, $row, $actorColumn): string {
            try {
                return (string) DB::table($table)->insertGetId(array_merge($row, [
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    $actorColumn => $actor->id,
                ]), 'id');
            } catch (QueryException $e) {
                throw $this->mapFailure($e, $lang);
            }
        });
    }

    private function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();
        $field = 'result';
        $text = match (true) {
            // Must precede RESULT_AUTHORITY: the longer name contains the shorter.
            str_contains($m, 'test_pack_results_pressure_unit_is_pressure') => $this->msg($lang,
                'واحد فشار در registry یک واحدِ فشار نیست؛ مقدار ثبت نشد.',
                'The pressure unit is not a pressure unit in the registry; nothing was recorded.'),
            str_contains($m, 'RESULT_AUTHORITY_NOT_NOMINATED') => $this->msg($lang,
                'این شخص برای ثبت این نوع نتیجه نامزد نشده است (یا نامزدی تعلیق/خاتمه یافته)؛ ثبت رد شد.',
                'This person is not nominated to record this kind of result (or the nomination is suspended/revoked); nothing was recorded.'),
            str_contains($m, 'RESULT_JOINT_NOT_FITTED') => $this->msg($lang,
                'نتیجهٔ جوش/آزمون فقط روی مفصل FITTED ثبت می‌شود؛ اول fit-up را ثبت کنید.',
                'Weld/examination results bind only to a FITTED joint; record the fit-up first.'),
            str_contains($m, 'RESULT_NDT_NEEDS_WELD') => $this->msg($lang,
                'آزمون غیرمخرب بدون رکورد جوش معنا ندارد؛ اول جوش را ثبت کنید.',
                'An examination without a weld record is meaningless; record the weld first.'),
            str_contains($m, 'RESULT_PREVIOUS_NOT_REJECTED') => $this->msg($lang,
                'ارجاع بازآزمایی باید به نتیجهٔ مردودِ همان مفصل و همان روش اشاره کند.',
                'A re-examination reference must point at a REJECTED result of the same joint and method.'),
            str_contains($m, 'RESULT_PREVIOUS_NOT_FAILED') => $this->msg($lang,
                'ارجاع آزمون مجدد باید به نتیجهٔ ناموفقِ همان بسته آزمون اشاره کند.',
                'A retest reference must point at a FAILED result of the same test pack.'),
            str_contains($m, 'RESULT_PACK_NOT_READY') => $this->msg($lang,
                'بسته آزمون هنوز READY نشده؛ نتیجهٔ آزمون برای بسته DRAFT ثبت نمی‌شود.',
                'The test pack is not READY; no result is recorded for a DRAFT pack.'),
            str_contains($m, 'RESULT_JOINT_NOT_FOUND') || str_contains($m, 'RESULT_PACK_NOT_FOUND') => $this->msg($lang,
                'شیء هدف در این پروژه پیدا نشد یا برای این جلسه قابل مشاهده نیست.',
                'The target object was not found in this project or is not visible to this session.'),
            str_contains($m, 'RESULT_PROJECT_NOT_WRITABLE') => $this->msg($lang,
                'پروژه برای ثبت نتایج writable نیست (آرشیو).',
                'The project is not writable for result postings (archived).'),
            str_contains($m, 'RESULT_AUTHORITY') => $this->msg($lang,
                'جلسه/actor با رکورد مطابقت ندارد؛ فقط ثبت مستقیم توسط actor جاری مجاز است.',
                'The session actor does not match the record; only direct posting by the current actor is allowed.'),
            str_contains($m, 'RESULT_REPORT_DOCUMENT_NOT_VISIBLE') || str_contains($m, 'MTR_DOCUMENT_NOT_VISIBLE') => $this->msg($lang,
                'مدرک استنادشده در این پروژه وجود ندارد یا برای ثبت‌کننده قابل مشاهده نیست.',
                'The cited document does not exist in this project or is not visible to the registrar.'),
            str_contains($m, 'RESULT_MANUAL_DIRECT_ONLY') => $this->msg($lang,
                'رکوردهای نتیجه فقط مستقیم ثبت می‌شوند، نه از مسیر ماشه‌های تو در تو.',
                'Result records are posted directly only, never through nested trigger paths.'),
            str_contains($m, 'append-only') => $this->msg($lang,
                'نتایج بازرسی/آزمون append-only هستند؛ اصلاح با رکورد جدید است، نه ویرایش.',
                'Inspection/test results are append-only; corrections are new records, never edits.'),
            str_contains($m, 'request_id_unique') => $this->msg($lang,
                'این شناسهٔ درخواست قبلاً ثبت شده است (رکورد تکراری).',
                'This request id was already posted (duplicate record).'),
            default => $this->msg($lang,
                'ثبت نتیجه رد شد: الزامات رکورد را بازبینی کنید. هیچ رکوردی ثبت نشد.',
                'The result posting was refused: recheck the record requirements. Nothing was recorded.'),
        };
        if (str_contains($m, 'RESULT_PREVIOUS_NOT_REJECTED') || str_contains($m, 'RESULT_PREVIOUS_NOT_FAILED')) {
            $field = 'previous_result_id';
        }

        return ValidationException::withMessages([$field => $text]);
    }

    // ------------------------------------------------------- input fragments

    private function guard(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function mode(array $input, array &$errors, string $lang): string
    {
        $mode = strtoupper(trim((string) ($input['mode'] ?? 'IN_SYSTEM')));
        if (! in_array($mode, self::MODES, true)) {
            $errors['mode'] = $this->enumMsg($lang, implode('/', self::MODES));

            return 'IN_SYSTEM';
        }

        return $mode;
    }

    private function externalFields(array $input, string $mode, array &$errors, string $lang): void
    {
        $org = trim((string) ($input['performer_org'] ?? ''));
        $name = trim((string) ($input['performer_name'] ?? ''));
        $ref = trim((string) ($input['report_ref'] ?? ''));
        if ($mode === 'EXTERNAL_REGISTER') {
            // An externally signed report without its identity is indistinguishable
            // from a platform-collected result — exactly the confusion ADR-031 forbids.
            if ($org === '' || mb_strlen($org) > 4000) {
                $errors['performer_org'] = $this->msg($lang, 'ثبت گزارش بیرونی بدون نام سازمان مجری ممکن نیست.', 'An external report cannot be registered without the performing organisation.');
            }
            if ($name === '' || mb_strlen($name) > 4000) {
                $errors['performer_name'] = $this->msg($lang, 'ثبت گزارش بیرونی بدون نام شخص مجری ممکن نیست.', 'An external report cannot be registered without the performing person.');
            }
            if ($ref === '' || mb_strlen($ref) > 200) {
                $errors['report_ref'] = $this->msg($lang, 'ثبت گزارش بیرونی بدون ارجاع گزارش ممکن نیست.', 'An external report cannot be registered without its report reference.');
            }
        } elseif ($org !== '' || $name !== '' || $ref !== '') {
            $errors['mode'] = $this->msg($lang, 'فیلدهای مجری بیرونی فقط با حالت EXTERNAL_REGISTER ثبت می‌شوند.', 'External performer fields are recorded only in EXTERNAL_REGISTER mode.');
        }
    }

    private function externalValue(array $input, string $key): ?string
    {
        $v = trim((string) ($input[$key] ?? ''));

        return $v === '' ? null : $v;
    }

    private function stampCode(array $input, string $key, array &$errors, string $lang): ?string
    {
        $code = trim((string) ($input[$key] ?? ''));
        if ($code === '' || mb_strlen($code) > 64) {
            $errors[$key] = $this->msg($lang, 'کد پرسنلی/مهر مجری الزامی است (حداکثر ۶۴ نویسه).', 'The performer stamp code is required (at most 64 characters).');

            return null;
        }

        return $code;
    }

    private function moment(array $input, string $key, array &$errors, string $lang): ?string
    {
        $raw = (string) ($input[$key] ?? '');
        if ($raw === '') {
            $errors[$key] = $this->msg($lang, 'زمان رویداد الزامی است.', 'The event time is required.');

            return null;
        }
        try {
            return Carbon::parse($raw)->toIso8601String();
        } catch (\Throwable) {
            $errors[$key] = $this->msg($lang, 'زمان رویداد قابل تجزیه نیست (ISO 8601 بدهید).', 'The event time is not parseable (provide ISO 8601).');

            return null;
        }
    }

    private function requestId(array $input, array &$errors, string $lang): ?string
    {
        $id = (string) ($input['request_id'] ?? '');
        if (! Str::isUuid($id)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');

            return null;
        }

        return $id;
    }

    private function uuidArg(string $value, string $field, array &$errors, string $lang): ?string
    {
        if (! Str::isUuid($value)) {
            $errors[$field] = $this->msg($lang, 'شناسه باید UUID باشد.', 'The id must be a UUID.');

            return null;
        }

        return $value;
    }

    private function optionalUuid(array $input, string $key): ?string
    {
        $v = $input[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }

        return (string) $v;
    }

    private function optionalString(array $input, string $key, int $max): ?string
    {
        $v = trim((string) ($input[$key] ?? ''));
        if ($v === '' || mb_strlen($v) > $max) {
            return null;
        }

        return $v;
    }

    private function enumMsg(string $lang, string $allowed): string
    {
        return $this->msg($lang, "مقدار معتبر: {$allowed}", "Expected one of: {$allowed}");
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'fa' ? $fa : $en;
    }
}
