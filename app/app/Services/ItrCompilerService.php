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
 * A.5b slice 3a — SCOPE. A dossier used to be about one test pack, which is
 * the unit a contractor tests and not the unit a client takes over. It is now
 * compiled for TEST_PACK, SUBSYSTEM or PROJECT, resolved by one SQL function
 * (`epcc_itr_scope_lines`) that both the dossier and the completeness report
 * read, so they cannot disagree about what a subsystem dossier is about. The
 * pack-scope functions survive as wrappers over the general ones: a preview
 * and a frozen snapshot must still agree byte-for-byte.
 *
 * SLICE BOUNDARIES (declared, not hidden): there is no system scope, because
 * the schema has subsystems, areas and facilities and no system level, and
 * grouping them would mean the platform inventing a hierarchy the project has
 * not declared. Receipts are not assembled (receipt binding needs piece-level
 * custody — A.4b), so MTR binding into the dossier awaits the same; PDF
 * export is optional at runtime.
 */
final class ItrCompilerService
{
    /**
     * The three scopes a dossier may be compiled for. There is no SYSTEM: the
     * project declares subsystems, areas and facilities, and a "system" would
     * be a hierarchy the platform made up rather than one the project stated.
     */
    public const SCOPES = ['TEST_PACK', 'SUBSYSTEM', 'PROJECT'];

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
    public function compile(
        Project $project,
        User $actor,
        ?string $scopeRef,
        string $lang = 'fa',
        string $scopeKind = 'TEST_PACK',
    ): array {
        $errors = [];
        $scope = $this->scopeArg($scopeKind, $scopeRef, $errors, $lang);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $scope, $lang): array {
            // Visibility is checked by the read itself under RLS: a scope that
            // this session cannot see resolves to nothing, and that is a
            // refusal rather than an empty dossier that looks finished.
            [$existsSql, $existsBindings] = $this->scopeExists($scope['kind'], $scope['ref'], $project->id);
            $row = DB::selectOne(
                'SELECT epcc_itr_scope_dossier(?,?,?) AS dossier, epcc_itr_scope_completeness(?,?,?) AS completeness,
                        (SELECT 1 FROM ('.$existsSql.') e) AS visible',
                [$project->id, $scope['kind'], $scope['ref'],
                 $project->id, $scope['kind'], $scope['ref'],
                 ...$existsBindings],
            );
            if (! $row || $row->visible === null) {
                throw ValidationException::withMessages([
                    $this->scopeField($scope['kind']) => $this->scopeMissingMessage($scope['kind'], $lang),
                ]);
            }

            return [
                'dossier' => json_decode((string) $row->dossier, true, 512, JSON_THROW_ON_ERROR),
                'completeness' => json_decode((string) $row->completeness, true, 512, JSON_THROW_ON_ERROR),
            ];
        });
    }

    /**
     * The one-row test that says the scope exists and is visible to this
     * session, with its own bindings so the caller cannot get the order wrong.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function scopeExists(string $kind, ?string $ref, string $projectId): array
    {
        return match ($kind) {
            'TEST_PACK' => ['SELECT 1 FROM piping_test_packs WHERE id = ? AND project_id = ?', [$ref, $projectId]],
            'SUBSYSTEM' => ['SELECT 1 FROM subsystems WHERE id = ? AND project_id = ?', [$ref, $projectId]],
            default => ['SELECT 1 FROM projects WHERE id = ? AND ?::uuid IS NULL', [$projectId, $ref]],
        };
    }

    private function scopeMissingMessage(string $kind, string $lang): string
    {
        return match ($kind) {
            'TEST_PACK' => $this->msg($lang,
                'بستهٔ آزمون در این پروژه پیدا نشد یا برای این جلسه قابل مشاهده نیست.',
                'The test pack was not found in this project or is not visible to this session.'),
            'SUBSYSTEM' => $this->msg($lang,
                'این زیرسیستم در این پروژه پیدا نشد یا برای این جلسه قابل مشاهده نیست.',
                'The subsystem was not found in this project or is not visible to this session.'),
            default => $this->msg($lang,
                'این پروژه برای این جلسه قابل مشاهده نیست.',
                'The project is not visible to this session.'),
        };
    }

    /** The field a refusal is reported against, named for what was asked for. */
    private function scopeField(string $kind): string
    {
        return match ($kind) {
            'TEST_PACK' => 'test_pack_id',
            'SUBSYSTEM' => 'subsystem_id',
            default => 'scope_ref',
        };
    }

    /**
     * One definition of a scope, validated once: the kind must be one of the
     * three, and a reference is required exactly when the scope names something
     * inside the project — the project itself needs none.
     *
     * @param  array<string, string>  $errors
     * @return array{kind: string, ref: ?string}
     */
    private function scopeArg(string $kind, ?string $ref, array &$errors, string $lang): array
    {
        $kind = strtoupper(trim($kind));
        if (! in_array($kind, self::SCOPES, true)) {
            $errors['scope_kind'] = $this->msg($lang,
                'دامنهٔ dossier باید یکی از این‌ها باشد: TEST_PACK، SUBSYSTEM، PROJECT.',
                'The dossier scope must be one of: TEST_PACK, SUBSYSTEM, PROJECT.');

            return ['kind' => 'TEST_PACK', 'ref' => null];
        }
        $field = $this->scopeField($kind);

        // A project dossier is the project: a reference beside it would let two
        // rows claim the same dossier under different names, which is exactly
        // what the resolver's one-definition rule exists to prevent.
        if ($kind === 'PROJECT' && trim((string) $ref) !== '') {
            $errors[$field] = $this->msg($lang,
                'دامنهٔ کلِ پروژه نیاز به شناسه ندارد: خودِ ردیف پروژه را نام می‌برد.',
                'The project scope takes no reference: the row already names the project.');

            return ['kind' => $kind, 'ref' => null];
        }

        if ($kind === 'PROJECT') {
            return ['kind' => $kind, 'ref' => null];
        }

        $ref = trim((string) $ref);
        if (! Str::isUuid($ref)) {
            // The field is named for what was asked for: a screen that posted a
            // pack gets told about its pack, not about an abstraction.
            $errors[$field] = $this->msg($lang,
                'شناسهٔ دامنه باید UUID باشد.',
                'The scope reference must be a UUID.');

            return ['kind' => $kind, 'ref' => null];
        }

        return ['kind' => $kind, 'ref' => $ref];
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
    public function freeze(
        Project $project,
        User $actor,
        ?string $scopeRef,
        string $requestId,
        string $lang = 'fa',
        string $scopeKind = 'TEST_PACK',
    ): array {
        $errors = [];
        $scope = $this->scopeArg($scopeKind, $scopeRef, $errors, $lang);
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang, 'شناسهٔ درخواست باید UUID باشد.', 'The request id must be a UUID.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $scope, $requestId, $lang): array {
            try {
                $id = (string) DB::table('itr_snapshots')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'scope_kind' => $scope['kind'],
                    'scope_ref' => $scope['ref'],
                    'test_pack_id' => $scope['kind'] === 'TEST_PACK' ? $scope['ref'] : null,
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
        return $this->snapshotsForScope($project, $actor, 'TEST_PACK', $testPackId);
    }

    /** Every snapshot frozen for one scope, newest first. */
    public function snapshotsForScope(Project $project, User $actor, string $scopeKind, ?string $scopeRef): Collection
    {
        if (! Str::isUuid($scopeRef)) {
            $scopeRef = null;
        }

        return $this->scoped($project, $actor, fn () => DB::table('itr_snapshots')
            ->where('project_id', $project->id)
            ->where('scope_kind', $scopeKind)
            ->where('scope_ref', $scopeRef)
            ->orderBy('compiled_at', 'desc')->orderBy('id')
            ->get());
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
            str_contains($m, 'ITR_SUBSYSTEM_NOT_FOUND') => $this->msg($lang,
                'این زیرسیستم در این پروژه پیدا نشد.',
                'That subsystem was not found in this project.'),
            str_contains($m, 'ITR_SCOPE_UNKNOWN') => $this->msg($lang,
                'دامنهٔ dossier باید یکی از این‌ها باشد: TEST_PACK، SUBSYSTEM، PROJECT.',
                'The dossier scope must be one of: TEST_PACK, SUBSYSTEM, PROJECT.'),
            str_contains($m, 'ITR_SCOPE_REF_MISMATCH') => $this->msg($lang,
                'ارجاعِ دامنه با نوعِ دامنه نمی‌خواند.',
                'The scope reference does not match the scope kind.'),
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

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'fa' ? $fa : $en;
    }
}
