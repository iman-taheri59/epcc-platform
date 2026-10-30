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
 * ADR-048 A.5b slice 2 — signatures on a frozen ITR dossier.
 *
 * A compilation nobody can sign is a draft nobody can close. But a signature
 * that means nothing is worse than no signature, because it looks like the
 * thing it is not — the previous slice's argument exactly: a record which
 * gates nothing is decoration.
 *
 * So this layer is built to be consequential, and the consequences are the
 * database's, not this class's:
 *
 *   - the digest it signs is derived from the frozen snapshot, never declared;
 *   - a snapshot whose dossier no longer matches its digest is refused (the
 *     table is append-only, so a mismatch means the document was changed
 *     underneath);
 *   - ENDORSED is refused while the completeness report still enumerates a
 *     gap, and the honest way past it is ENDORSED_WITH_COMMENTS, whose
 *     comments are mandatory;
 *   - signing is default-deny behind the nomination register, and the policy
 *     that authorised each signature is stamped on the row.
 *
 * This class writes the row and reads the register back. It decides nothing
 * about whether the work was accepted — the dossier's `kind` still says
 * ITR_COMPILATION_NOT_A_CERTIFICATE, and that is deliberate.
 */
final class ItrSignatureService
{
    /** The three things a signer may say about a dossier. */
    public const OUTCOMES = ['ENDORSED', 'ENDORSED_WITH_COMMENTS', 'REFUSED'];

    /** The one outcome that carries no qualification, and therefore no reason. */
    private const CLEAN = 'ENDORSED';

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ResultRecorderPolicyService $nominations,
    ) {}

    /** Runs $fn in the results session contract: project, company, actor. */
    private function scoped(Project $project, User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, function () use ($actor, $fn): mixed {
            // The row carries `signed_by`, which the guard matches against the
            // results actor — the contract the nomination register is read in.
            $prior = DB::selectOne("SELECT current_setting('epcc.result_actor_id', true) AS v")->v;
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$actor->id]);

            try {
                // Nested on purpose: a savepoint, so a refused insert rolls
                // back to here instead of aborting the session transaction the
                // restore below still needs.
                return DB::transaction($fn);
            } finally {
                DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$prior ?? '']);
            }
        }, $project->id);
    }

    /**
     * Signs one frozen dossier. Append-only: a second signature from the same
     * company is a new row chained to the head, never an edit.
     *
     * @param  array<string, mixed>  $input  outcome, reason?, signed_at?, previous_signature_id?, request_id
     *
     * @return array{id: string, outcome: string, digest: string}
     *
     * @throws ValidationException
     */
    public function sign(Project $project, User $actor, string $snapshotId, array $input, string $lang = 'fa'): array
    {
        $errors = [];

        if (! Str::isUuid($snapshotId)) {
            $errors['snapshot_id'] = $this->msg($lang,
                'شناسهٔ snapshot باید UUID باشد.',
                'The snapshot id must be a UUID.');
        }

        $outcome = strtoupper(trim((string) ($input['outcome'] ?? '')));
        if (! in_array($outcome, self::OUTCOMES, true)) {
            $errors['outcome'] = $this->msg($lang,
                'نتیجهٔ امضا باید یکی از این‌ها باشد: ENDORSED، ENDORSED_WITH_COMMENTS، REFUSED.',
                'The signature outcome must be one of: ENDORSED, ENDORSED_WITH_COMMENTS, REFUSED.');
            $outcome = '';
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($outcome !== '' && $outcome !== self::CLEAN && ($reason === '' || mb_strlen($reason) > 4000)) {
            $errors['reason'] = $this->msg($lang,
                'این امضا بدون شرح ثبت نمی‌شود: پذیرشِ مشروط همان نظرها را می‌خواهد و خودداری از امضا علتش را.',
                'This signature is never recorded without a reason: a conditional endorsement needs the comments, and a refusal needs the cause.');
        }
        if ($outcome === self::CLEAN && $reason !== '') {
            $errors['reason'] = $this->msg($lang,
                'یک تأییدِ بی‌قید شرح ندارد؛ اگر قیدی هست ثبتش کن (ENDORSED_WITH_COMMENTS).',
                'A clean endorsement carries no qualification; if there is one, record it (ENDORSED_WITH_COMMENTS).');
        }

        $previous = null;
        if (($input['previous_signature_id'] ?? null) !== null && (string) $input['previous_signature_id'] !== '') {
            if (! Str::isUuid((string) $input['previous_signature_id'])) {
                $errors['previous_signature_id'] = $this->msg($lang,
                    'شناسهٔ امضای قبلی باید UUID باشد.',
                    'The previous signature id must be a UUID.');
            } else {
                $previous = (string) $input['previous_signature_id'];
            }
        }

        $requestId = trim((string) ($input['request_id'] ?? ''));
        if (! Str::isUuid($requestId)) {
            $errors['request_id'] = $this->msg($lang,
                'شناسهٔ درخواست باید UUID باشد.',
                'The request id must be a UUID.');
        }

        $signedAt = trim((string) ($input['signed_at'] ?? now()->toIso8601String()));

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $reason = $reason === '' ? null : $reason;

        return $this->scoped($project, $actor, function () use ($project, $actor, $snapshotId, $outcome, $reason, $previous, $requestId, $signedAt, $lang): array {
            try {
                $id = (string) DB::table('itr_snapshot_signatures')->insertGetId([
                    'id' => (string) Str::uuid(),
                    'project_id' => $project->id,
                    'company_id' => $actor->company_id,
                    'snapshot_id' => $snapshotId,
                    'digest' => str_repeat('0', 64), // overwritten by the guard
                    'outcome' => $outcome,
                    'reason' => $reason,
                    'signed_by' => $actor->id,
                    'signed_at' => $signedAt,
                    'signer_policy_id' => (string) Str::uuid(), // overwritten by the guard
                    'previous_signature_id' => $previous,
                    'request_id' => $requestId,
                ], 'id');
            } catch (QueryException $e) {
                throw ValidationException::withMessages(['outcome' => $this->mapFailure($e, $lang)]);
            }

            $row = DB::table('itr_snapshot_signatures')->where('id', $id)->first();

            return [
                'id' => $id,
                'outcome' => (string) $row->outcome,
                'digest' => (string) $row->digest,
            ];
        });
    }

    /**
     * Everything one snapshot means to a reader, in one query: whether the
     * document is still intact and complete, the current word of every company
     * that signed it, and this company's head (what a new signature must name).
     *
     * `is_complete` and `digest_ok` are read with the same expressions the
     * guard uses, so a screen can withhold ENDORSED from a dossier that has
     * gaps instead of offering it and being refused on submit — the
     * discovery-on-submit every earlier slice argued against.
     *
     * @param  list<string>  $snapshotIds
     * @return Collection<string, array{is_complete: bool, digest_ok: bool, signatures: Collection<int, object>, head: ?object}>
     */
    public function forSnapshots(Project $project, User $actor, array $snapshotIds): Collection
    {
        $ids = array_values(array_filter($snapshotIds, Str::isUuid(...)));
        if ($ids === []) {
            return collect();
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $ids): Collection {
            $docs = DB::table('itr_snapshots as s')
                ->where('s.project_id', $project->id)
                ->whereIn('s.id', $ids)
                ->get([
                    's.id',
                    DB::raw("(s.digest = encode(sha256(convert_to(s.dossier::text, 'UTF8')), 'hex')) AS digest_ok"),
                    DB::raw("COALESCE((s.completeness -> 'is_complete')::boolean, false) AS is_complete"),
                ])
                ->keyBy('id');

            // The signer's name and company are joined for the printed
            // document; both are LEFT, because a signature that outlives its
            // author's access must still read as a signature.
            $rows = DB::table('itr_snapshot_signatures')
                ->leftJoin('companies as c', 'c.id', '=', 'itr_snapshot_signatures.company_id')
                ->leftJoin('users as u', 'u.id', '=', 'itr_snapshot_signatures.signed_by')
                ->where('itr_snapshot_signatures.project_id', $project->id)
                ->whereIn('snapshot_id', $ids)
                ->orderBy('recorded_at')->orderBy('id')
                ->get(['itr_snapshot_signatures.*', 'c.code as company_code', 'u.name as signer_name'])
                ->groupBy('snapshot_id');

            return $docs->map(function (object $doc) use ($rows, $actor): array {
                $all = $rows->get($doc->id, collect());
                $head = $all->where('company_id', $actor->company_id)->last();

                return [
                    'is_complete' => (bool) $doc->is_complete,
                    'digest_ok' => (bool) $doc->digest_ok,
                    // A reader needs each company's current word, not its
                    // history; the history is still in the audit trail.
                    'signatures' => $all->groupBy('company_id')
                        ->map(fn (Collection $chain): object => $chain->last())
                        ->values()
                        ->sortBy('recorded_at')
                        ->values(),
                    'head' => $head,
                ];
            });
        });
    }

    /**
     * The current word of each company: the latest signature per company,
     * which is what a printed dossier should carry.
     */
    public function latestPerCompany(Project $project, User $actor, string $snapshotId): Collection
    {
        return $this->forSnapshots($project, $actor, [$snapshotId])
            ->get($snapshotId, ['signatures' => collect()])['signatures'];
    }

    /**
     * What the sign form needs: the pack's frozen snapshots with the state
     * that decides which outcomes are honestly available.
     *
     * @return Collection<string, array{id: string, compiled_at: string, digest: string, is_complete: bool, digest_ok: bool, head_id: ?string, head_outcome: ?string}>
     */
    public function signableFor(Project $project, User $actor, string $packId): Collection
    {
        if (! Str::isUuid($packId)) {
            return collect();
        }

        return $this->scoped($project, $actor, function () use ($project, $actor, $packId): Collection {
            $ids = DB::table('itr_snapshots as s')
                ->where('s.project_id', $project->id)
                ->where('s.test_pack_id', $packId)
                ->orderBy('s.compiled_at', 'desc')->orderBy('s.id')
                ->limit(50)
                ->pluck('s.id')
                ->map(fn (mixed $id): string => (string) $id)
                ->all();

            if ($ids === []) {
                return collect();
            }

            $meta = DB::table('itr_snapshots')->whereIn('id', $ids)->get(['id', 'compiled_at', 'digest'])->keyBy('id');

            return $this->forSnapshots($project, $actor, $ids)
                ->map(function (array $state, string $id) use ($meta): array {
                    $row = $meta->get($id);

                    return [
                        'id' => $id,
                        'compiled_at' => (string) ($row->compiled_at ?? ''),
                        'digest' => (string) ($row->digest ?? ''),
                        'is_complete' => $state['is_complete'],
                        'digest_ok' => $state['digest_ok'],
                        'head_id' => $state['head'] !== null ? (string) $state['head']->id : null,
                        'head_outcome' => $state['head'] !== null ? (string) $state['head']->outcome : null,
                    ];
                })
                ->sortByDesc('compiled_at')
                ->values();
        });
    }

    /**
     * Whether this actor may sign at all, asked of the register the guard
     * itself asks. One definition of authority, not two.
     */
    public function nominationFor(Project $project, User $actor): ?string
    {
        return $this->nominations->nominationFor($project, $actor, 'sign_itr_snapshot');
    }

    private function mapFailure(QueryException $e, string $lang): string
    {
        $m = $e->getMessage();

        return match (true) {
            str_contains($m, 'ITR_SIGN_ENDORSEMENT_OVER_GAPS') => $this->msg($lang,
                'این dossier هنوز شکاف دارد (گزارشِ کامل‌بودن آن‌ها را می‌شمارد)؛ تأییدِ بی‌قید ممکن نیست. یا شکاف‌ها را ببند، یا با ENDORSED_WITH_COMMENTS و شرحِ آن امضا کن.',
                'This dossier still has gaps — the completeness report enumerates them — so a clean endorsement is not available. Close the gaps, or sign ENDORSED_WITH_COMMENTS and say why they are acceptable.'),
            str_contains($m, 'ITR_SIGN_DIGEST_INVALID') => $this->msg($lang,
                'این snapshot دیگر با digest ی خود یکی نیست؛ امضای آن امضایِ چیزِ دیگری است.',
                'That snapshot no longer matches its own digest; signing it would be signing something else.'),
            str_contains($m, 'ITR_SIGN_NOT_NOMINATED') => $this->msg($lang,
                'امضای dossier نیازمند نامزدیِ معتبر برای sign_itr_snapshot است.',
                'Signing a dossier requires a live nomination for sign_itr_snapshot.'),
            str_contains($m, 'ITR_SIGN_SNAPSHOT_NOT_FOUND') => $this->msg($lang,
                'این snapshot در این پروژه پیدا نشد یا برای این شرکت قابل مشاهده نیست.',
                'That snapshot was not found in this project, or is not visible to this company.'),
            str_contains($m, 'ITR_SIGN_PREVIOUS_STALE') => $this->msg($lang,
                'شرکتِ شما روی این snapshot امضایِ تازه‌تری دارد؛ امضایِ جدید باید همان را نام ببرد تا تاریخچه دو شاخه نشود.',
                'Your company has a later signature on this snapshot; a new one must name it, so the history cannot fork.'),
            str_contains($m, 'ITR_SIGN_PREVIOUS_UNKNOWN') => $this->msg($lang,
                'امضایِ قبلی نام‌برده متعلق به امضایِ جاریِ این شرکت روی این snapshot نیست.',
                'The previous signature named is not this company’s current signature on that snapshot.'),
            str_contains($m, 'itr_signature_request_unique') => $this->msg($lang,
                'این درخواست قبلاً ثبت شده است.',
                'That request has already been recorded.'),
            str_contains($m, 'ITR_SIGN_PROJECT_NOT_WRITABLE') => $this->msg($lang,
                'پروژه برای این تغییر writable نیست (آرشیو).',
                'The project is not writable for this change (archived).'),
            default => $this->msg($lang,
                'امضای dossier رد شد.',
                'The dossier could not be signed.'),
        };
    }

    private function msg(string $lang, string $fa, string $en): string
    {
        return $lang === 'fa' ? $fa : $en;
    }
}
