<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipingCustodyAmendmentService;
use App\Services\PipingCustodyService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PipingCustodyAmendmentController extends Controller
{
    public function __construct(private readonly PipingCustodyAmendmentService $service, private readonly PipingCustodyService $custody, private readonly TenantContext $tenant) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = in_array($request->query('lang'), ['en', 'fa'], true) ? $request->query('lang') : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());

        return ['project' => $project, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'user' => $request->user(), 'acting' => $request->user()->company];
    }

    public function show(Request $request, string $projectCode, string $receiptId): View
    {
        $ctx = $this->context($request, $projectCode);

        return view('materials.piping-custody-amendments', $this->service->dashboard($ctx['project'], $request->user(), $receiptId) + $ctx + ['requestId' => (string) Str::uuid()]);
    }

    public function store(Request $request, string $projectCode, string $receiptId): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        abort_unless($this->custody->policyId($ctx['project'], $request->user(), 'custody_mrr') !== null, 403);
        $request->validate(['amendment' => ['required', 'array']], ['required' => __('piping_amendments.invalid'), 'array' => __('piping_amendments.invalid')]);
        $this->service->create($ctx['project'], $request->user(), $receiptId, $request->input('amendment'));

        return redirect()->route('materials.piping-custody-amendments', ['projectCode' => $projectCode, 'receiptId' => $receiptId, 'lang' => $ctx['lang']])->with('status', __('piping_amendments.saved'));
    }
}
