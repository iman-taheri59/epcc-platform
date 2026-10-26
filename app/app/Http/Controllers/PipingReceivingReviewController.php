<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipingCustodyService;
use App\Services\PipingReceivingReviewService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PipingReceivingReviewController extends Controller
{
    public function __construct(private readonly PipingReceivingReviewService $service, private readonly PipingCustodyService $custody, private readonly TenantContext $tenant) {}

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

        return view('materials.piping-receiving-qc', $this->service->dashboard($ctx['project'], $request->user(), $receiptId) + $ctx + ['requestId' => (string) Str::uuid()]);
    }

    public function store(Request $request, string $projectCode, string $receiptId): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        abort_unless($this->custody->policyId($ctx['project'], $request->user(), 'receiving_qc') !== null, 403);
        $request->validate(['review' => ['required', 'array']], ['required' => __('piping_receiving_qc.invalid'), 'array' => __('piping_receiving_qc.invalid')]);
        $this->service->create($ctx['project'], $request->user(), $receiptId, $request->input('review'));

        return redirect()->route('materials.piping-receiving-qc', ['projectCode' => $projectCode, 'receiptId' => $receiptId, 'lang' => $ctx['lang']])->with('status', __('piping_receiving_qc.saved'));
    }
}
