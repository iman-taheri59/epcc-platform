<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Services\ItrCompilerService;
use App\Services\ItrSignatureService;
use App\Services\PdfRenderer;
use App\Services\TestPackNotificationService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ADR-048 A.5 slice 1 + A.4h slice 6 — the ITR compiler gets a surface.
 *
 * The compiler has existed since A.5 slice 1 and could only be driven from
 * SQL: compile a dossier, freeze it under a digest, render it. This is the
 * screen, and it keeps the compiler's own honesty intact: the dossier kind
 * literally says ITR_COMPILATION_NOT_A_CERTIFICATE, and the completeness
 * report on this page enumerates every gap rather than collapsing them into a
 * verdict.
 *
 * Two things the screen refuses to do. It does not offer "freeze" for a DRAFT
 * pack, because a snapshot of a pack nobody has prepared is premature and the
 * guard would refuse it (`ITR_PACK_NOT_READY`) — offering the button only to
 * have it refused is the discovery-on-submit the earlier slices argued
 * against. And it does not pretend the PDF is the point: the renderer is
 * optional at runtime, so a missing browser returns 503 with an explanation
 * rather than a blank document.
 */
final class ItrDossierController extends Controller
{
    /** How many snapshots we re-verify on one page load. */
    private const SNAPSHOT_LIMIT = 20;

    public function __construct(
        private readonly ItrCompilerService $itr,
        private readonly TestPackNotificationService $notices,
        private readonly ItrSignatureService $signatures,
        private readonly PdfRenderer $pdf,
        private readonly TenantContext $tenant,
    ) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = in_array($request->query('lang'), ['en', 'fa'], true) ? $request->query('lang') : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());
        $this->assertMember($project, $request->user());

        return [$project, $lang];
    }

    /** Dossiers belong to the project; a non-member stops here. */
    private function assertMember(Project $project, User $actor): void
    {
        $member = $this->tenant->runAs($actor->company_id, fn (): bool => DB::table('project_memberships')
            ->where('project_id', $project->id)
            ->where('user_id', $actor->id)
            ->exists(), $project->id);

        abort_unless($member, 403);
    }

    public function show(Request $request, string $projectCode): View
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $actor = $request->user();

        $packs = $this->tenant->runAs($actor->company_id, fn () => PipingTestPack::where('project_id', $project->id)
            ->orderBy('code')->limit(200)->get(), $project->id);

        $pack = $request->query('pack') !== null ? $packs->firstWhere('id', $request->query('pack')) : null;
        $compiled = $pack !== null ? $this->itr->compile($project, $actor, (string) $pack->id, $lang) : null;

        // Every snapshot on the page is re-verified against its stored dossier.
        // The table is append-only, so a false here would mean tampering at the
        // database level — which is exactly the thing worth surfacing.
        $snapshots = $pack !== null
            ? $this->itr->snapshotsForPack($project, $actor, (string) $pack->id)->take(self::SNAPSHOT_LIMIT)
                ->map(fn (object $row): array => [
                    'row' => $row,
                    'valid' => (bool) ($this->itr->snapshot($project, $actor, (string) $row->id)['digest_valid'] ?? false),
                ])
            : collect();

        // Signatures are read per snapshot, in one query, from the same
        // expressions the guard uses: a screen that offered "endorse" for a
        // dossier with gaps would only be refused on submit.
        $signatureState = $snapshots->isNotEmpty()
            ? $this->signatures->forSnapshots($project, $actor, $snapshots->pluck('row.id')->map(fn ($id) => (string) $id)->all())
            : collect();

        return view('quality.itr', [
            'project' => $project,
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $actor,
            'acting' => $actor->company,
            'packs' => $packs,
            'pack' => $pack,
            'dossier' => $compiled['dossier'] ?? null,
            'completeness' => $compiled['completeness'] ?? null,
            'notifications' => $pack !== null
                ? $this->notices->forPack($project, $actor, (string) $pack->id, $lang)
                : collect(),
            'eligible' => $pack !== null
                ? $this->notices->eligibleForPack($project, $actor, (string) $pack->id)
                : collect(),
            'snapshots' => $snapshots,
            'signatureState' => $signatureState,
            'signable' => $pack !== null
                ? $this->signatures->signableFor($project, $actor, (string) $pack->id)
                : collect(),
            // Asked of the register the guard itself asks: one definition of
            // authority, so the form is never shown to someone it would refuse.
            'maySign' => $this->signatures->nominationFor($project, $actor) !== null,
            'pdfAvailable' => $this->pdf->isAvailable(),
            'requestId' => (string) Str::uuid(),
            'linkRequestId' => (string) Str::uuid(),
        ]);
    }

    public function freeze(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $validated = $request->validate(['test_pack_id' => ['required', 'uuid']]);

        $snapshot = $this->itr->freeze(
            $project,
            $request->user(),
            (string) $validated['test_pack_id'],
            (string) $request->input('request_id', ''),
            $lang,
        );

        return redirect()->route('quality.itr', [
            'projectCode' => $projectCode,
            'lang' => $lang,
            'pack' => $validated['test_pack_id'],
        ])->with('status', __('quality_itr.saved', ['digest' => substr($snapshot['digest'], 0, 12)]));
    }

    /**
     * Links one inspection notice to one pack. The link is what makes the
     * sign-off register load bearing: from here on, a HOLD point on that
     * notice gates the pack's claim to have been tested or accepted.
     */
    public function link(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $validated = $request->validate([
            'test_pack_id' => ['required', 'uuid'],
            'notification_id' => ['required', 'uuid'],
            'request_id' => ['required', 'uuid'],
        ]);

        $linked = $this->notices->link(
            $project,
            $request->user(),
            (string) $validated['test_pack_id'],
            (string) $validated['notification_id'],
            (string) $validated['request_id'],
            $lang,
        );

        return redirect()->route('quality.itr', [
            'projectCode' => $projectCode,
            'lang' => $lang,
            'pack' => $validated['test_pack_id'],
        ])->with('status', __('quality_itr.linked', ['number' => $linked['number']]));
    }

    /**
     * Signs one frozen dossier. The outcome that is offered on the form is the
     * outcome the guard will accept: a dossier with enumerated gaps has no
     * clean endorsement on offer at all.
     */
    public function sign(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $validated = $request->validate([
            'test_pack_id' => ['required', 'uuid'],
            'snapshot_id' => ['required', 'uuid'],
            'outcome' => ['required', 'string', Rule::in(ItrSignatureService::OUTCOMES)],
            'reason' => ['nullable', 'string', 'max:4000'],
            'previous_signature_id' => ['nullable', 'uuid'],
            'request_id' => ['required', 'uuid'],
        ]);

        $signed = $this->signatures->sign(
            $project,
            $request->user(),
            (string) $validated['snapshot_id'],
            $validated,
            $lang,
        );

        return redirect()->route('quality.itr', [
            'projectCode' => $projectCode,
            'lang' => $lang,
            'pack' => $validated['test_pack_id'],
        ])->with('status', __('quality_itr.signed', ['outcome' => __('quality_itr.sig_'.$signed['outcome'])]));
    }

    /**
     * The frozen dossier as a bilingual PDF. Optional by host: without a
     * headless browser the data is still the deliverable, so this says so
     * instead of handing back an empty document.
     */
    public function pdf(Request $request, string $projectCode, string $snapshotId): Response
    {
        [$project, $lang] = $this->context($request, $projectCode);

        abort_unless($this->pdf->isAvailable(), 503, __('quality_itr.pdf_unavailable'));

        $actor = $request->user();

        // The printed document carries the evidence the dossier was compiled
        // from and every signature on it — a signed dossier that hid the HOLD
        // evidence would be less honest than an unsigned one.
        [$notifications, $signatures] = $this->tenant->runAs($actor->company_id, function () use ($project, $actor, $snapshotId, $lang): array {
            $packId = DB::table('itr_snapshots')->where('id', $snapshotId)->value('test_pack_id');

            return [
                $packId !== null ? $this->notices->forPack($project, $actor, (string) $packId, $lang) : collect(),
                $this->signatures->latestPerCompany($project, $actor, $snapshotId),
            ];
        }, $project->id);

        $bytes = $this->itr->renderPdf($project, $actor, $snapshotId, $lang, $notifications, $signatures);
        abort_if($bytes === null, 404);

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="itr-'.$snapshotId.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
