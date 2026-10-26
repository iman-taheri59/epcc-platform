<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Services\PipingTraceabilityService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PipingTraceabilityController extends Controller
{
    public function __construct(private readonly PipingTraceabilityService $service, private readonly TenantContext $tenant) {}

    public function show(Request $request, string $projectCode, ?string $receiptId = null): View
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());

        return view('materials.piping-traceability', $this->service->report($project, $request->user(), $receiptId) + [
            'project' => $project, 'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(), 'acting' => $request->user()->company,
        ]);
    }
}
