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
 * ITR compiler — slice 1 (ADR-048 decision 5: itr_generation=both).
 *
 * On-demand compilation is a pure read of the SAME SQL body that the freeze
 * trigger uses (`epcc_itr_test_pack_dossier` + `..._completeness`), so a
 * preview and a frozen snapshot taken at the same moment are byte-identical.
 * Freezing stores the dossier under a trigger-derived sha256 digest, append-
 * only, RLS+FORCE — the scope-proposal envelope pattern.
 *
 * The completeness report is explicit, never a certificate: every missing
 * item is enumerated (unreleased lines, unfitted joints, joints without weld
 * or NDT records, open rejections, packs without a passed result). The
 * dossier kind literally says ITR_COMPILATION_NOT_A_CERTIFICATE.
 *
 * SLICE BOUNDARIES (declared, not hidden): scope is TEST_PACK only; ITP
 * notifications and receipts are not assembled (no pack↔notification link
 * exists; receipt binding needs piece-level custody — A.4b/A.5b); MTR
 * binding to packs awaits the same; PDF export is optional at runtime.
 */
final class ItrCompilerService
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PdfRenderer $pdf,
    ) {}

    /** Runs $fn in the ITR session contract: project, compiling company, actor. */
    public function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            $prior = DB::selectOne("SELECT current_setting('epcc.itr_actor_id',true) AS actor")->actor;
            DB::select('SELECT set_config(\'epcc.itr_actor_id\',?,true)', [$actor->id]);
            try {
                return DB::transaction($fn);
            } finally {
                DB::select('SELECT set_config(\'epcc.itr_actor_id\',?,true)', [$prior ?? '']);
            }
        }, $project->id);
    }

    /**
     * On-demand compilation for review — nothing is stored.
     *
     * @return array{dossier: array, completeness: array}
     *
     * @throws ValidationException when the pack is not visible in this project
     */
    public function compile(Project $project, User $actor, string $testPackId, string $lang = 'fa'): array
    {
        $errors = [];
        $pack = $this->uuidArg($testPackId, 'test_pack_id', $errors, $lang);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $pack, $lang): array {
            // Visibility is checked by the read itself under RLS.
            $row = DB::selectOne(
                'SELECT epcc_itr_test_pack_dossier(?,?) AS dossier, epcc_itr_test_pack_completeness(?,?) AS completeness,
                        (SELECT 1 FROM piping_test_packs tp WHERE tp.id = ? AND tp.project_id = ?) AS visible',
                [$project->id, $pack, $project->id, $pack, $pack, $project->id],
            );
            if (! $row || $row->visible === null) {
                throw ValidationException::withMessages(['test_pack_id' => $this->msg($lang,
                    'بستهٔ آزمون در این پروژه پیدا نشد یا برای این جلسه قابل مشاهده نیست.',
                    'The test pack was not found in this project or is not visible to this session.')]);
            }

            return [
                'dossier' => json_decode((string) $row->dossier, true, 512, JSON_THROW_ON_ERROR),
                'completeness' => json_decode((string) $row->completeness, true, 512, JSON_THROW_ON_ERROR),
            ];
        });
    }

    /**
     * Freezes the compilation of one non-DRAFT test pack as a digest-bound
     * snapshot. The trigger derives dossier/completeness/digest server-side;
     * nothing about the content is client-declarable.
     *
     * @return array{id: string, digest: string}
     *
     * @throws ValidationException
     */
    public function freeze(Project $project, User $actor, string $testPackId, string $requestId, string $lang = 'fa'): array
    {
        $errors = [];
        $pack = $this->uuidArg($testPackId, 'test_pack_id', $errors, $lang);
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $pack, $requestId, $lang): array {
            try {
                $id = (string) DB::table('itr_snapshots')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'scope_kind' => 'TEST_PACK',
                    'test_pack_id' => $pack,
                    'dossier' => '{}',          // overwritten by the guard trigger
                    'completeness' => '{}',     // overwritten by the guard trigger
                    'digest' => str_repeat('0', 64), // overwritten by the guard trigger
                    'state' => 'FROZEN',
                    'compiled_by' => $actor->id,
                    'request_id' => $requestId,
                ], 'id');
            } catch (QueryException $e) {
                throw $this->mapFailure($e, $lang);
            }
            $digest = (string) DB::table('itr_snapshots')->where('id', $id)->value('digest');

            return ['id' => $id, 'digest' => $digest];
        });
    }

    /**
     * Reads one frozen snapshot and re-verifies its digest against the stored
     * dossier. The table is append-only, so a false here would mean database
     * level tampering — we surface it instead of trusting the row.
     *
     * @return array{row: object, digest_valid: bool}|null
     */
    public function snapshot(Project $project, User $actor, string $snapshotId): ?array
    {
        if (! Str::isUuid($snapshotId)) {
            return null;
        }

        return $this->scoped($project, $actor, function () use ($project, $snapshotId): ?array {
            $row = DB::table('itr_snapshots')->where('id', $snapshotId)
                ->where('project_id', $project->id)->first();
            if ($row === null) {
                return null;
            }
            $valid = (bool) DB::selectOne(
                "SELECT (digest = encode(sha256(convert_to(dossier::text,'UTF8')),'hex')) AS ok
                 FROM itr_snapshots WHERE id = ? AND project_id = ?",
                [$snapshotId, $project->id],
            )->ok;

            return ['row' => $row, 'digest_valid' => $valid];
        });
    }

    /** Every snapshot frozen for one pack, newest first. */
    public function snapshotsForPack(Project $project, User $actor, string $testPackId): Collection
    {
        return $this->scoped($project, $actor, fn () => DB::table('itr_snapshots')
            ->where('project_id', $project->id)->where('test_pack_id', $testPackId)
            ->orderBy('compiled_at', 'desc')->orderBy('id')->get());
    }

    /**
     * Renders a frozen snapshot to bilingual PDF bytes via the house renderer.
     * Returns null when no headless browser exists on this host (the
     * TransmittalPackager contract: data first, bytes optional).
     */
    /**
     * The frozen dossier as a document. Evidence is passed in rather than
     * re-read here because what a printed ITR carries — the HOLD notices it
     * was compiled against, and every company's current signature — belongs to
     * the caller's view of the project, not to the compiler's.
     *
     * @param  Collection<int, array<string, mixed>>  $notifications
     * @param  Collection<int, object>  $signatures
     */
    public function renderPdf(
        Project $project,
        User $actor,
        string $snapshotId,
        string $lang = 'fa',
        ?Collection $notifications = null,
        ?Collection $signatures = null,
    ): ?string {
        $snap = $this->snapshot($project, $actor, $snapshotId);
        if ($snap === null || ! $snap['digest_valid'] || ! $this->pdf->isAvailable()) {
            return null;
        }
        $dossier = json_decode((string) $snap['row']->dossier, true, 512, JSON_THROW_ON_ERROR);
        $completeness = json_decode((string) $snap['row']->completeness, true, 512, JSON_THROW_ON_ERROR);

        return $this->pdf->render(view('print.itr-dossier', [
            'lang' => $lang,
            'dossier' => $dossier,
            'completeness' => $completeness,
            'digest' => (string) $snap['row']->digest,
            'compiledAt' => (string) $snap['row']->compiled_at,
            'notifications' => $notifications ?? collect(),
            'signatures' => $signatures ?? collect(),
        ])->render());
    }

    // ------------------------------------------------------------- plumbing

    private function mapFailure(QueryException $e, string $lang): ValidationException
    {
        $m = $e->getMessage();
        $text = match (true) {
            str_contains($m, 'ITR_PACK_NOT_READY') => $this->msg($lang,
                'برای بستهٔ DRAFT، snapshot ITR معنایی ندارد؛ اول بسته را READY کنید.',
                'An ITR snapshot of a DRAFT pack is premature; mark the pack READY first.'),
            str_contains($m, 'ITR_PACK_NOT_FOUND') => $this->msg($lang,
                'بستهٔ آزمون در این پروژه پیدا نشد یا قابل مشاهده نیست.',
                'The test pack was not found in this project or is not visible.'),
            str_contains($m, 'ITR_PROJECT_NOT_WRITABLE') => $this->msg($lang,
                'پروژه برای ثبت snapshot writable نیست (آرشیو).',
                'The project is not writable for snapshot postings (archived).'),
            str_contains($m, 'ITR_AUTHORITY') => $this->msg($lang,
                'جلسه/actor با رکورد مطابقت ندارد؛ فقط freeze مستقیم توسط actor جاری مجاز است.',
                'The session actor does not match the record; only direct freezing by the current actor is allowed.'),
            str_contains($m, 'ITR_MANUAL_DIRECT_ONLY') => $this->msg($lang,
                'snapshot ها فقط مستقیم ثبت می‌شوند، نه از مسیر ماشه‌های تو در تو.',
                'Snapshots are posted directly only, never through nested trigger paths.'),
            str_contains($m, 'append-only') => $this->msg($lang,
                'snapshot های ITR تغییرناپذیرند؛ اصلاح با snapshot جدید است.',
                'ITR snapshots are immutable; corrections are new snapshots.'),
            str_contains($m, 'request_id_unique') => $this->msg($lang,
                'این شناسهٔ درخواست قبلاً ثبت شده است (snapshot تکراری).',
                'This request id was already frozen (duplicate snapshot).'),
            default => $this->msg($lang,
                'ثبت snapshot رد شد؛ هیچ رکوردی ذخیره نشد.',
                'The snapshot posting was refused; nothing was stored.'),
        };

        return ValidationException::withMessages(['itr' => $text]);
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
