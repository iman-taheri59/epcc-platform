<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\LegacyMaterialReconciliationService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * ADR-048 A.4h slice 4 — the legacy warehouse gets a way through the cutover.
 *
 * ADR-047 closed `material_lots` behind a cutoff and made the GRN guard refuse
 * any purchase order that still has legacy lots. Right, but it was a wall:
 * nothing in the platform could ever be done about those lots, so a project
 * that used the legacy warehouse before cutover could never post a controlled
 * GRN against those orders. A.4f made the decision recordable; this is where a
 * person records it.
 *
 * The screen's shape follows the rest of the quality surfaces — a form only for
 * someone nominated for `reconcile_legacy` — with one addition that matters
 * here: the TAKE_OVER field lists only the custody records that MAY take the
 * lot over, those attesting the lot's own heat. The same heat, or it is not the
 * same steel; offering the rest would be asking someone to make a decision in
 * order to be told it was never theirs to make.
 */
final class LegacyReconciliationController extends Controller
{
    public function __construct(
        private readonly LegacyMaterialReconciliationService $reconciliations,
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

    /** Legacy lots and the controlled chain both belong to the project. */
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

        $data = $this->reconciliations->dashboard($project, $request->user(), $request->query('lot'), $lang);

        return view('quality.legacy', array_merge($data, [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(),
            'acting' => $request->user()->company,
            'requestId' => (string) Str::uuid(),
        ]));
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $validated = $request->validate([
            'lot_id' => ['required', 'uuid'],
            'reconciliation' => ['required', 'array'],
        ]);

        $decided = $this->reconciliations->reconcile(
            $project,
            $request->user(),
            (string) $validated['lot_id'],
            $request->input('reconciliation'),
            $lang,
        );

        return redirect()->route('quality.legacy', [
            'projectCode' => $projectCode,
            'lang' => $lang,
            'lot' => $validated['lot_id'],
        ])->with('status', __('quality_legacy.saved', ['decision' => $decided['decision']]));
    }
}
