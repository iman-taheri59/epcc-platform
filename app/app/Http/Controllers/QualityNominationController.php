<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ADR-048 A.4g slice 1 — the quality nomination register gets a surface.
 *
 * Recording inspection evidence has been default-deny since A.4b, which is
 * right; but the register that grants the authority could only ever be written
 * by a test fixture inserting rows directly. This screen is how a project
 * actually nominates: a company names its own people for an action, or the
 * employer names anyone's.
 */
final class QualityNominationController extends Controller
{
    public function __construct(
        private readonly ResultRecorderPolicyService $service,
        private readonly TenantContext $tenant,
    ) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = in_array($request->query('lang'), ['en', 'fa'], true) ? $request->query('lang') : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());

        return [$project, $lang];
    }

    public function show(Request $request, string $projectCode): View
    {
        [$project, $lang] = $this->context($request, $projectCode);

        return view('quality.nominations', $this->service->dashboard($project, $request->user()) + [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(),
            'acting' => $request->user()->company,
        ]);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $request->validate(['policy' => ['required', 'array']]);

        $this->service->configure($project, $request->user(), $request->input('policy'), $lang);

        return redirect()->route('quality.nominations', ['projectCode' => $project->code, 'lang' => $lang])
            ->with('status', __('quality_nominations.saved'));
    }
}
