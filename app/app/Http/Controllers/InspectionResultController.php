<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * ADR-048 A.4h slice 1 — recording a result, through a screen.
 *
 * The services behind this have existed since A.4a and the guards have refused
 * everything since A.4b, but until A.4g there was no way to nominate a
 * recorder and now there is: this is the surface that nomination unlocks.
 *
 * The screen is deliberately built around the register rather than around the
 * form: a form is offered only when `epcc_result_nomination()` says this actor
 * is nominated for that action, and otherwise it says why not. That is the
 * default-deny rule made visible instead of being discovered on submit.
 */
final class InspectionResultController extends Controller
{
    public function __construct(
        private readonly InspectionResultService $results,
        private readonly TenantContext $tenant,
    ) {}

    private function context(Request $request, string $projectCode): array
    {
        $lang = in_array($request->query('lang'), ['en', 'fa'], true) ? $request->query('lang') : 'fa';
        app()->setLocale($lang);
        $project = $this->tenant->forIdentityLookup(fn () => Project::where('code', $projectCode)->firstOrFail());
        $this->assertMember($project, $request->user());

        return [$project, $lang];
    }

    /**
     * The register, the joints and the results all belong to the project, so
     * someone who is not on it has nothing to do here - and is told so before
     * anything is read, rather than being shown an empty screen that looks
     * like a bug. The guards would refuse the write anyway; this is the same
     * rule applied one layer earlier, where it can be explained.
     */
    private function assertMember(Project $project, User $actor): void
    {
        $member = $this->tenant->runAs($actor->company_id, fn (): bool => DB::table('project_memberships')
            ->where('project_id', $project->id)
            ->where('user_id', $actor->id)
            ->exists(), $project->id);

        abort_unless($member, 403);
    }

    public function show(Request $request, string $projectCode): View
    {
        [$project, $lang] = $this->context($request, $projectCode);

        $data = $this->results->dashboard(
            $project,
            $request->user(),
            $request->query('joint'),
            $request->query('pack'),
            $lang,
        );

        return view('quality.results', $data + [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $request->user(),
            'acting' => $request->user()->company,
            'requestId' => (string) \Illuminate\Support\Str::uuid(),
        ]);
    }

    public function storeWeld(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $request->validate([
            'joint_id' => ['required', 'uuid'],
            'weld' => ['required', 'array'],
        ]);

        $this->results->recordWeld(
            $project,
            $request->user(),
            (string) $request->input('joint_id'),
            $request->input('weld'),
            $lang,
        );

        return $this->back($projectCode, $lang, ['joint' => $request->input('joint_id')], 'quality_results.weld_saved');
    }

    public function storeNdt(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $request->validate([
            'joint_id' => ['required', 'uuid'],
            'ndt' => ['required', 'array'],
        ]);

        $this->results->recordNdt(
            $project,
            $request->user(),
            (string) $request->input('joint_id'),
            $request->input('ndt'),
            $lang,
        );

        return $this->back($projectCode, $lang, ['joint' => $request->input('joint_id')], 'quality_results.ndt_saved');
    }

    public function storePack(Request $request, string $projectCode): RedirectResponse
    {
        [$project, $lang] = $this->context($request, $projectCode);
        $request->validate([
            'test_pack_id' => ['required', 'uuid'],
            'pack' => ['required', 'array'],
        ]);

        $this->results->recordTestResult(
            $project,
            $request->user(),
            (string) $request->input('test_pack_id'),
            $request->input('pack'),
            $lang,
        );

        return $this->back($projectCode, $lang, ['pack' => $request->input('test_pack_id')], 'quality_results.pack_saved');
    }

    /** @param  array<string,mixed>  $selection */
    private function back(string $projectCode, string $lang, array $selection, string $message): RedirectResponse
    {
        return redirect()->route('quality.results', ['projectCode' => $projectCode, 'lang' => $lang] + $selection)
            ->with('status', __($message));
    }
}
