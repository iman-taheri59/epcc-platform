<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipingCustodyService;
use App\Services\PipingQaDecisionService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PipingQaDecisionController extends Controller
{
    public function __construct(private readonly PipingQaDecisionService $service, private readonly PipingCustodyService $custody, private readonly TenantContext $tenant) {}

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

        return view('materials.piping-qa', $this->service->dashboard($ctx['project'], $request->user(), $receiptId) + $ctx + ['requestId' => (string) Str::uuid()]);
    }

    public function store(Request $request, string $projectCode, string $receiptId): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        abort_unless($this->custody->policyId($ctx['project'], $request->user(), 'acceptance_qa') !== null, 403);
        $request->validate(['qa' => ['required', 'array']], ['required' => __('piping_qa.invalid'), 'array' => __('piping_qa.invalid')]);
        $input = $request->input('qa');
        if (! $request->expectsJson()) {
            foreach (['requirements_file_ids', 'evidence_file_ids'] as $key) {
                if (! isset($input[$key])) {
                    $input[$key] = [];
                } elseif (is_string($input[$key])) {
                    $input[$key] = preg_split('/[\s,]+/u', trim($input[$key]), -1, PREG_SPLIT_NO_EMPTY);
                }
            }
        }
        $this->service->create($ctx['project'], $request->user(), $receiptId, $input);

        return redirect()->route('materials.piping-qa', ['projectCode' => $projectCode, 'receiptId' => $receiptId, 'lang' => $ctx['lang']])->with('status', __('piping_qa.saved'));
    }
}
