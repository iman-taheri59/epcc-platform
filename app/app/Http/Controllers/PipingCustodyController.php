<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipingCustodyService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PipingCustodyController extends Controller
{
    public function __construct(private readonly PipingCustodyService $service, private readonly TenantContext $tenant) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = in_array($request->query('lang'), ['en', 'fa'], true) ? $request->query('lang') : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());

        return ['project' => $project, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'user' => $request->user(), 'acting' => $request->user()->company];
    }

    public function index(Request $request, string $projectCode): View
    {
        $ctx = $this->context($request, $projectCode);

        return view('materials.piping-custody', $this->service->dashboard($ctx['project'], $request->user()) + $ctx + ['record' => null, 'requestId' => (string) Str::uuid()]);
    }

    public function show(Request $request, string $projectCode, string $receiptId): View
    {
        $ctx = $this->context($request, $projectCode);
        $record = $this->service->detail($ctx['project'], $request->user(), $receiptId);

        return view('materials.piping-custody', $ctx + ['record' => $record]);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        abort_unless($this->service->policyId($ctx['project'], $request->user(), 'custody_mrr') !== null, 403);
        $request->validate(['receipt' => ['required', 'array']], ['required' => __('piping_custody.invalid'), 'array' => __('piping_custody.invalid')]);
        $id = $this->service->create($ctx['project'], $request->user(), $request->input('receipt'));

        return redirect()->route('materials.piping-custody.show', ['projectCode' => $projectCode, 'receiptId' => $id, 'lang' => $ctx['lang']])->with('status', __('piping_custody.saved'));
    }
}
