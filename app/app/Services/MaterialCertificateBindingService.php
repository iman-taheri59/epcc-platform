<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * MTR <-> custody bindings — ADR-048 A.4e slice 1.
 *
 * A material certificate says "heat H of item I meets this specification".
 * The custody chain says "we received heat H". Until now nothing connected the
 * two statements, so a certificate could be registered for a heat nobody ever
 * received, and nobody could answer the turnover question: which heats we hold
 * have no certificate?
 *
 * A binding is that connection, and it is DERIVED, never declared: the guard
 * reads the heat and lot off the certificate, looks for custody in the same
 * project that attests them, and stamps the custody record (and the amending
 * amendment) on the binding. When custody attests nothing, the certificate can
 * still be recorded — as UNRESOLVED, with a reason, never silently.
 *
 * The service is thin by design: it shapes input, maps refusals to bilingual
 * field errors, and reads the coverage report. Every rule lives in the
 * database.
 *
 * Declared boundary: this binds to the PIPING custody chain. Reconciliation
 * with the legacy `material_lots` warehouse (ADR-047 cutover) is separate, and
 * HTTP/UI is still open.
 */
final class MaterialCertificateBindingService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ResultRecorderPolicyService $nominations,
    ) {}

    /** A screen is not a report: the registry is capped rather than paginated. */
    public const REGISTRY_LIMIT = 200;

    /** Runs $fn in the binding session contract: project, binding company, actor. */
    private function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $priorResult = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;
            $priorReceipt = DB::selectOne("SELECT current_setting('epcc.receipt_actor_id', true) AS v")->v;
            // Two session contracts meet here. The binding itself is gated by the
            // results register (epcc.result_actor_id), while the custody chain it
            // binds to is only readable by a receipt actor — so a binder that
            // cannot see the custody record could never bind anything to it.
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
     * Binds one certificate to the custody that attests its (lot, heat).
     *
     * @param  array<string,mixed>  $input  unlinked_reason?, request_id
     *
     * @return array{id: string, binding: string, custody_id: ?string, custody_amendment_id: ?string, item_code: ?string, heat_ref: string, lot_ref: ?string}
     */
    public function bindCertificate(Project $project, User $actor, string $certificateId, array $input, string $lang = 'fa'): array
    {
        $errors = [];

        $certificate = $this->uuidArg($certificateId, 'certificate_id', $errors, $lang);

        $reason = trim((string) ($input['unlinked_reason'] ?? ''));
        if ($reason !== '' && mb_strlen($reason) > 4000) {
            $errors['unlinked_reason'] = $this->msg($lang, 'دلیلِ اتصال‌نداشتن باید حداکثر ۴۰۰۰ کاراکتر باشد.', 'The unlinked reason must be at most 4000 characters.');
        }

        $requestId = trim((string) ($input['request_id'] ?? ''));
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $certificate, $reason, $requestId, $lang): array {
            try {
                $id = (string) DB::table('material_certificate_bindings')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'certificate_id' => $certificate,
                    // All three are replaced by the guard from the certificate
                    // and from custody; the caller states none of them.
                    'custody_id' => null,
                    'custody_amendment_id' => null,
                    'binding' => 'UNRESOLVED',
                    'heat_ref' => '',
                    'lot_ref' => null,
                    'item_code' => null,
                    'bound_policy_id' => $certificate,
                    'unlinked_reason' => $reason === '' ? null : $reason,
                    'bound_by' => $actor->id,
                    'request_id' => $requestId,
                ], 'id');
            } catch (QueryException $e) {
                throw $this->mapFailure($e, $lang);
            }

            $row = DB::table('material_certificate_bindings')->where('id', $id)->first();

            return [
                'id' => $id,
                'binding' => (string) $row->binding,
                'custody_id' => $row->custody_id === null ? null : (string) $row->custody_id,
                'custody_amendment_id' => $row->custody_amendment_id === null ? null : (string) $row->custody_amendment_id,
                'item_code' => $row->item_code === null ? null : (string) $row->item_code,
                'heat_ref' => (string) $row->heat_ref,
                'lot_ref' => $row->lot_ref === null ? null : (string) $row->lot_ref,
            ];
        });
    }

    /** Every binding recorded for one certificate, oldest first. */
    public function bindingsForCertificate(Project $project, User $actor, string $certificateId): Collection
    {
        if (! Str::isUuid($certificateId)) {
            return collect();
        }

        return $this->scoped($project, $actor, fn () => DB::table('material_certificate_bindings')
            ->where('project_id', $project->id)
            ->where('certificate_id', $certificateId)
            ->orderBy('bound_at')->orderBy('id')
            ->get());
    }

    /**
     * Everything the MTR screen needs: the certificate registry with its
     * binding state, the selected certificate with its history, and the
     * coverage report.
     *
     * The certificate list is whatever RLS lets this session read, for the
     * same reason the sign-off worklist is: one copy of the visibility rule,
     * in the database, rather than a second one in PHP that can drift.
     */
    public function dashboard(Project $project, User $actor, ?string $certificateId = null, string $lang = 'fa'): array
    {
        return $this->scoped($project, $actor, function () use ($project, $actor, $certificateId, $lang): array {
            $certificates = DB::table('material_certificates')->where('project_id', $project->id)
                ->orderByDesc('registered_at')->orderBy('id')->limit(self::REGISTRY_LIMIT)->get();

            $byCertificate = DB::table('material_certificate_bindings')->where('project_id', $project->id)
                ->whereIn('certificate_id', $certificates->pluck('id'))
                ->orderBy('bound_at')->orderBy('id')->get()->groupBy('certificate_id');

            foreach ($certificates as $row) {
                $history = $byCertificate->get($row->id, collect());
                // The current position is the last one: bindings are append-only,
                // so a re-bind is a later fact, not an edit of an earlier one.
                $row->bindings = $history;
                $row->binding = $history->last();
            }

            $certificate = $certificateId !== null && $certificateId !== ''
                ? $certificates->firstWhere('id', $certificateId)
                : null;

            return [
                'project' => $project,
                'certificates' => $certificates,
                'certificate' => $certificate,
                'bindings' => $certificate !== null ? $certificate->bindings : collect(),
                'coverage' => $this->coverage($project, $actor),
                'nominated' => $this->nominations->nominationFor($project, $actor, 'bind_certificate'),
                'registerNominated' => $this->nominations->nominationFor($project, $actor, 'register_certificate'),
                'certTypes' => InspectionResultService::CERT_TYPES,
                'bindingLabels' => $this->bindingLabels($lang),
                'companies' => DB::table('project_company')->join('companies', 'companies.id', '=', 'project_company.company_id')
                    ->where('project_id', $project->id)->select('companies.*')->orderBy('code')->get()->keyBy('id'),
            ];
        });
    }

    private function bindingLabels(string $lang): array
    {
        return [
            'CUSTODY_LOT_HEAT' => $lang === 'fa' ? 'متصل به custody (lot و heat)' : 'Bound to custody (lot and heat)',
            'CUSTODY_HEAT' => $lang === 'fa' ? 'متصل به custody (heat)' : 'Bound to custody (heat)',
            'UNRESOLVED' => $lang === 'fa' ? 'بی‌اتصال (با دلیل)' : 'Unresolved (with a reason)',
        ];
    }

    /**
     * The turnover question, answered honestly: for every heat custody attests,
     * how many certificates are bound to it — and every certificate we could
     * not bind, with the reason it is unresolved.
     *
     * @return array{attested: Collection, unresolved: Collection}
     */
    public function coverage(Project $project, User $actor): array
    {
        return $this->scoped($project, $actor, fn () => [
            'attested' => DB::select(<<<'SQL'
                SELECT btrim(e.eff->>'heat_ref')                                AS heat_ref,
                       NULLIF(btrim(COALESCE(e.eff->>'lot_ref', '')), '')       AS lot_ref,
                       poi.item_code                                            AS item_code,
                       count(DISTINCT r.id)                                     AS custody_records,
                       count(DISTINCT b.certificate_id)                         AS certificates
                  FROM piping_custody_records r
                  JOIN LATERAL (SELECT epcc_piping_effective_receipt(r.id) AS eff) e ON true
                  LEFT JOIN purchase_order_items poi
                         ON poi.id = NULLIF(e.eff->>'source_line_id', '')::uuid
                  LEFT JOIN material_certificate_bindings b
                         ON b.custody_id = r.id AND b.project_id = r.project_id
                 WHERE r.project_id = ?
                   AND btrim(COALESCE(e.eff->>'heat_ref', '')) <> ''
                 GROUP BY 1, 2, 3
                 ORDER BY 1, 2
            SQL, [$project->id]),
            'unresolved' => DB::table('material_certificate_bindings')
                ->where('project_id', $project->id)
                ->where('binding', 'UNRESOLVED')
                ->orderBy('bound_at')->orderBy('id')
                ->get(),
        ]);
    }

    public function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();

        $text = match (true) {
            str_contains($m, 'MTR_BIND_NOT_NOMINATED') => $this->msg($lang,
                'اتصالِ گواهی به custody نیازمند نامزدی است؛ شما برای این کار نامزد نشده‌اید.',
                'Binding a certificate to custody requires a nomination; you are not nominated for it.'),
            str_contains($m, 'MTR_CERTIFICATE_NOT_FOUND') => $this->msg($lang,
                'این گواهی در این پروژه یافت نشد یا برای این نشست قابل رؤیت نیست.',
                'The certificate was not found in this project or is not visible to this session.'),
            str_contains($m, 'MTR_LOT_NOT_ATTESTED') => $this->msg($lang,
                'custody این heat را دارد اما این lot را تأیید نکرده است؛ یا lot را در زنجیرهٔ custody ثبت کنید، یا گواهی را با ذکر دلیل به‌صورت unresolved نگه دارید.',
                'Custody attests this heat but not this lot: either record the lot on the custody chain, or keep the certificate unresolved with a reason.'),
            str_contains($m, 'MTR_HEAT_NOT_IN_CUSTODY') => $this->msg($lang,
                'هیچ متریالی با این heat در custody این پروژه دریافت نشده است؛ برای ثبتِ گواهیِ بدون اتصال، دلیل آن را بنویسید.',
                'No material with this heat was received into this project\'s custody; to keep the certificate unresolved, say why.'),
            str_contains($m, 'mtr_binding_custody_unique') => $this->msg($lang,
                'این گواهی پیش‌تر به همین custody متصل شده است.',
                'This certificate is already bound to that custody record.'),
            str_contains($m, 'mtr_binding_request_unique') => $this->msg($lang,
                'این درخواست قبلاً ثبت شده است؛ اتصال تکراری ثبت نمی‌شود.',
                'That request id was already recorded; the binding was not duplicated.'),
            str_contains($m, 'MTR_BIND_AUTHORITY') => $this->msg($lang,
                'اتصالِ گواهی باید به نامِ شرکت و شخصِ جاری باشد.',
                'A certificate binding must be made by the current company and actor.'),
            default => $this->msg($lang,
                'اتصال گواهی رد شد؛ هیچ رکوردی ذخیره نشد.',
                'The certificate binding was refused; nothing was recorded.'),
        };

        return ValidationException::withMessages(['binding' => $text]);
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
