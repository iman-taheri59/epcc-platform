<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ModelImport;
use App\Models\ModelImportDiff;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Services\ModelImportService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Upload a 3D model extract and see what it did (ADR-005 phase 2).
 *
 * The screen is deliberately as much about the DIFF REPORT as about the upload.
 * An importer that only says "imported 412 components" is an importer nobody
 * trusts on the second revision; the useful answer is "4 changes were withheld
 * because the field has already welded them, and here they are".
 */
final class ModelImportController extends Controller
{
    public function __construct(
        private readonly ModelImportService $imports,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$imports, $attention, $subsystems, $quarantined] = $this->tenant->runAs(
            $user->company_id,
            function (): array {
                $imports = ModelImport::with(['line', 'importer'])
                    ->orderByDesc('created_at')
                    ->limit(25)
                    ->get();

                // The queue of things a person still has to decide about. This
                // is the reason the screen exists.
                $attention = ModelImportDiff::with(['component', 'import'])
                    ->whereIn('change', [ModelImportDiff::PROTECTED, ModelImportDiff::UNMAPPED, ModelImportDiff::REMOVED_IN_MODEL])
                    ->where('acknowledged', false)
                    ->orderByDesc('created_at')
                    ->limit(50)
                    ->get();

                return [
                    $imports,
                    $attention,
                    Subsystem::orderBy('code')->get(),
                    PipingLine::where('is_quarantined', true)->orderBy('line_number')->get(),
                ];
            },
            $project->id,
        );

        return view('piping.model-import', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'imports' => $imports,
            'attention' => $attention,
            'subsystems' => $subsystems,
            'quarantined' => $quarantined,
        ]);
    }

    public function store(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'subsystem_id' => ['nullable', 'uuid'],
            'dry_run' => ['nullable'],
        ]);

        $upload = $request->file('file');
        $contents = (string) file_get_contents($upload->getRealPath());

        try {
            $this->tenant->runAs($user->company_id, function () use ($project, $user, $contents, $upload, $data): void {
                $subsystem = isset($data['subsystem_id'])
                    ? Subsystem::find($data['subsystem_id'])
                    : null;

                $this->imports->importPcf(
                    $project,
                    $user,
                    $contents,
                    $upload->getClientOriginalName(),
                    (bool) ($data['dry_run'] ?? false),
                    $subsystem,
                );
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lang));
    }

    /** Accept a quarantined line into the register. */
    public function accept(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($lineId, $user): void {
                $this->imports->acceptQuarantinedLine(PipingLine::findOrFail($lineId), $user);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lang));
    }

    /** Mark a withheld change as seen, so the queue empties as people work it. */
    public function acknowledge(Request $request, string $projectCode, string $diffId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($diffId, $user, $project): void {
                $diff = ModelImportDiff::findOrFail($diffId);
                if ($diff->company_id !== $user->company_id) {
                    throw new \Illuminate\Auth\Access\AuthorizationException(__('epcc.record_creator_only'));
                }
                app(\App\Services\ProjectArchiveService::class)->assertWritable($project);
                $diff->update([
                    'acknowledged' => true,
                    'acknowledged_by' => $user->id,
                    'acknowledged_at' => now(),
                ]);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lang));
    }

    private function backTo(string $projectCode, string $lang): string
    {
        return route('piping.model-import', ['projectCode' => $projectCode, 'lang' => $lang]);
    }

    /**
     * Resolve the project AND prove this user's company takes part in it.
     *
     * Same shape as ComponentController's: a 404 rather than a 403, because
     * telling a stranger that a project exists is itself a disclosure.
     */
    private function resolveProject($user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || \Illuminate\Support\Facades\DB::table('project_company')
                ->where('project_id', $project->id)
                ->where('company_id', $user->company_id)
                ->exists();

        abort_if(! $participates || $user->membershipFor($project->id) === null, 404);

        return $project;
    }

    private function lang(Request $request): string
    {
        return $request->query('lang') === 'fa' ? 'fa' : 'en';
    }
}
