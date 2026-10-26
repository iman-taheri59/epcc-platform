<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\DemandReaderService;
use App\Services\ScopeAdmissionAuthorityService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ScopeAdmissionAuthorityController extends Controller
{
    public function __construct(private readonly ScopeAdmissionAuthorityService $service, private readonly DemandReaderService $readers, private readonly TenantContext $tenant) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());

        return ['project' => $project, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr', 'user' => $request->user(), 'acting' => $request->user()->company];
    }

    public function show(Request $request, string $projectCode): View
    {
        $ctx = $this->context($request, $projectCode);

        return view('materials.scope-admission-actors', $this->service->dashboard($ctx['project'], $request->user(), $request->query()) + $ctx +
            ['langSwitchUrl' => route('materials.scope-admission-actors', $request->only(['target_company_id', 'batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id', 'action']) + ['projectCode' => $projectCode, 'lang' => $ctx['lang'] === 'fa' ? 'en' : 'fa'])]);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        $this->readers->assertConfigurator($ctx['project'], $request->user());
        $request->validate(['policy' => ['required', 'array:target_company_id,batch_id,document_id,scope_id,hierarchy_revision_id,action,version,suspended,selectors,reason'], 'policy.selectors' => ['sometimes', 'array', 'list']]);
        $d = $request->input('policy');
        if (! array_key_exists('selectors', $d)) {
            $d['selectors'] = [];
        }
        $this->service->configure($ctx['project'], $request->user(), $d);

        return redirect()->route('materials.scope-admission-actors', array_intersect_key($d, array_flip(['target_company_id', 'batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id', 'action'])) + ['projectCode' => $projectCode, 'lang' => $ctx['lang']])->with('status', __('scope_admission_authority.saved'));
    }

    public function suspensions(Request $request, string $projectCode): View
    {
        $ctx = $this->context($request, $projectCode);

        return view('materials.scope-admission-suspensions', $this->service->suspensionRegister($ctx['project'], $request->user()) + $ctx +
            ['langSwitchUrl' => route('materials.scope-admission-suspensions', ['projectCode' => $projectCode, 'lang' => $ctx['lang'] === 'fa' ? 'en' : 'fa'])]);
    }

    public function suspend(Request $request, string $projectCode): RedirectResponse
    {
        $ctx = $this->context($request, $projectCode);
        $this->readers->assertConfigurator($ctx['project'], $request->user());
        $request->validate(['suspension' => ['required', 'array:policy_id,version,reason']]);
        $this->service->suspendRecorded($ctx['project'], $request->user(), $request->input('suspension'));

        return redirect()->route('materials.scope-admission-suspensions', ['projectCode' => $projectCode, 'lang' => $ctx['lang']])->with('status', __('scope_admission_authority.suspension_saved'));
    }

    public function diagnostics(Request $request, string $projectCode): View
    {
        $ctx = $this->context($request, $projectCode);
        $d = $request->validate(['batch_id' => ['required', 'uuid'], 'document_id' => ['required', 'uuid'], 'scope_id' => ['required', 'uuid'],
            'hierarchy_revision_id' => ['required', 'string', 'max:96', 'regex:/\S/u'], 'proposer_id' => ['prohibited'], 'reviewer_id' => ['prohibited'], 'action' => ['prohibited']]);

        return view('materials.scope-admission-diagnostics', $ctx + ['assessment' => $this->service->diagnostics($ctx['project'], $request->user(), $d),
            'langSwitchUrl' => route('materials.scope-admission-diagnostics', array_intersect_key($d, array_flip(['batch_id', 'document_id', 'scope_id', 'hierarchy_revision_id'])) + ['projectCode' => $projectCode, 'lang' => $ctx['lang'] === 'fa' ? 'en' : 'fa'])]);
    }
}
