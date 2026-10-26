<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipingGrnService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PipingGrnController extends Controller
{
    public function __construct(private readonly PipingGrnService $service, private readonly TenantContext $tenant) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());

        return ['project' => $project, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'user' => $request->user(), 'acting' => $request->user()->company];
    }

    public function show(Request $request, string $projectCode, string $receiptId): View
    {
        $ctx = $this->context($request, $projectCode);

        return view('materials.piping-grn', $this->service->dashboard($ctx['project'], $request->user(), $receiptId) + $ctx + ['requestId' => (string) Str::uuid()]);
    }

    public function store(Request $request, string $projectCode, string $receiptId): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        $request->validate(['grn' => ['required', 'array']]);
        $this->service->post($ctx['project'], $request->user(), $receiptId, $request->input('grn'));

        return redirect()->route('materials.piping-grn', ['projectCode' => $projectCode, 'receiptId' => $receiptId, 'lang' => $ctx['lang']])->with('status', __('piping_grn.saved'));
    }
}
