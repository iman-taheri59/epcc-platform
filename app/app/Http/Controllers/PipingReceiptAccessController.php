<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipingReceiptAccessService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PipingReceiptAccessController extends Controller
{
    public function __construct(private readonly PipingReceiptAccessService $service, private readonly TenantContext $tenant) {}

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

        return view('materials.receipt-access', $this->service->dashboard($project, $request->user()) + [
            'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'user' => $request->user(),
            'acting' => $request->user()->company,
        ]);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $request->validate(['receipt_access' => ['required', 'array']]);
        $this->service->configure($project, $request->user(), $request->input('receipt_access'));

        return redirect()->route('materials.receipt-access', ['projectCode' => $project->code, 'lang' => $lang])
            ->with('status', __('receipt_access.saved'));
    }
}
