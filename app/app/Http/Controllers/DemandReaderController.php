<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DemandReaderService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DemandReaderController extends Controller
{
    public function __construct(private readonly DemandReaderService $service, private readonly TenantContext $tenant) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());

        return ['project' => $project, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(), 'acting' => $request->user()->company];
    }

    public function show(Request $request, string $projectCode): View
    {
        $ctx = $this->context($request, $projectCode);

        return view('materials.demand-readers', $this->service->dashboard($ctx['project'], $request->user(), $request->query()) + $ctx + ['langSwitchUrl' => route('materials.demand-readers', $request->only(['target_company_id', 'batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id']) + ['projectCode' => $projectCode, 'lang' => $ctx['lang'] === 'fa' ? 'en' : 'fa'])]);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        $this->service->assertConfigurator($ctx['project'], $request->user());
        $request->validate(['policy' => ['required', 'array']]);
        $input = $request->input('policy');
        // Unchecked HTML checkbox groups are absent; this is an explicit empty list, never current-policy fallback.
        if (! array_key_exists('selectors', $input)) {
            $input['selectors'] = [];
        }
        $this->service->configure($ctx['project'], $request->user(), $input);

        return redirect()->route('materials.demand-readers', ['projectCode' => $projectCode, 'lang' => $ctx['lang']])
            ->with('status', __('demand_readers.saved'));
    }

    public function suspensions(Request $request, string $projectCode): View
    {
        $ctx = $this->context($request, $projectCode);

        return view('materials.demand-reader-suspensions', $this->service->suspensionRegister($ctx['project'], $request->user()) + $ctx +
            ['langSwitchUrl' => route('materials.demand-reader-suspensions', ['projectCode' => $projectCode, 'lang' => $ctx['lang'] === 'fa' ? 'en' : 'fa'])]);
    }

    public function suspend(Request $request, string $projectCode): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        $this->service->assertConfigurator($ctx['project'], $request->user());
        $request->validate(['suspension' => ['required', 'array:policy_id,version,reason']]);
        $this->service->suspendRecorded($ctx['project'], $request->user(), $request->input('suspension'));

        return redirect()->route('materials.demand-reader-suspensions', ['projectCode' => $projectCode, 'lang' => $ctx['lang']])->with('status', __('demand_readers.suspension_saved'));
    }

    public function readiness(Request $request, string $projectCode): View
    {
        $ctx = $this->context($request, $projectCode);
        $d = $request->validate(['batch_id' => ['required', 'uuid'], 'document_id' => ['required', 'uuid'],
            'scope_id' => ['required', 'uuid'], 'hierarchy_revision_id' => ['required', 'string', 'max:96', 'regex:/\S/u']]);
        $assessment = $this->service->assess($ctx['project'], $request->user(), $d['batch_id'], $d['document_id'], $d['scope_id'], $d['hierarchy_revision_id']);

        return view('materials.demand-readiness', $ctx + ['assessment' => $assessment, 'langSwitchUrl' => route('materials.demand-readiness', $d + ['projectCode' => $projectCode, 'lang' => $ctx['lang'] === 'fa' ? 'en' : 'fa'])]);
    }
}
