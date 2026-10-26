<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\ScopeProposalEnvelopeReader;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ScopeProposalEnvelopeController extends Controller
{
    public function __construct(private readonly ScopeProposalEnvelopeReader $reader, private readonly TenantContext $tenant) {}

    public function show(Request $request, string $projectCode, string $proposalId): View
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());
        $inspection = $this->reader->inspect($project, $request->user(), $proposalId);

        return view('materials.scope-proposal-envelope', ['project' => $project, 'inspection' => $inspection, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(), 'acting' => $request->user()->company,
            'langSwitchUrl' => route('materials.scope-proposals.show', ['projectCode' => $projectCode, 'proposalId' => $proposalId, 'lang' => $lang === 'fa' ? 'en' : 'fa'])]);
    }
}
