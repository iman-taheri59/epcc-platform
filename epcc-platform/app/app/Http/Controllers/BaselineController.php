<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BaselineBatch;
use App\Models\BaselineDiscrepancy;
use App\Models\PipingLine;
use App\Models\Project;
use App\Services\BaselineService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The MTO-versus-model reconciliation screen (ADR-005 §2.1).
 *
 * The page is built around the DISAGREEMENTS, not around the import. Anybody
 * can produce a list of quantities; the thing worth putting in front of a
 * project manager is the short list of places where engineering and the
 * take-off do not agree - each with both numbers, and neither of them chosen
 * on their behalf.
 */
final class BaselineController extends Controller
{
    public function __construct(
        private readonly BaselineService $baseline,
        private readonly TenantContext $tenant,
    ) {}

    public function index(Request $request, string $projectCode, string $lineId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$line, $batches, $discrepancies, $agreed, $shortage] = $this->tenant->runAs(
            $user->company_id,
            function () use ($lineId): array {
                $line = PipingLine::with('subsystem')->findOrFail($lineId);

                return [
                    $line,
                    BaselineBatch::with('importer')
                        ->where('piping_line_id', $line->id)
                        ->orderByDesc('created_at')
                        ->get(),
                    BaselineDiscrepancy::where('piping_line_id', $line->id)
                        ->orderBy('state')
                        ->orderBy('item_code')
                        ->get(),
                    $this->baseline->agreedBaseline($line),
                    $this->baseline->shortageReport($line),
                ];
            },
            $project->id,
        );

        return view('piping.baseline', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'line' => $line,
            'batches' => $batches,
            'discrepancies' => $discrepancies,
            'agreed' => $agreed,
            'shortage' => $shortage,
        ]);
    }

    /** Upload an MTO spreadsheet. */
    public function store(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:20480'],
            'revision_label' => ['nullable', 'string', 'max:32'],
        ]);

        $upload = $request->file('file');

        try {
            $this->tenant->runAs($user->company_id, function () use ($project, $user, $upload, $lineId, $data): void {
                $line = PipingLine::findOrFail($lineId);

                $this->baseline->importMto(
                    $project,
                    $user,
                    $upload->getRealPath(),
                    $upload->getClientOriginalName(),
                    $line,
                    $data['revision_label'] ?? null,
                );
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang));
    }

    /** Derive a take-off from the modelled components. */
    public function takeOff(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($lineId, $user): void {
                $this->baseline->takeOffFromModel(PipingLine::findOrFail($lineId), $user);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang));
    }

    public function accept(Request $request, string $projectCode, string $lineId, string $batchId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($batchId, $user): void {
                $this->baseline->accept(BaselineBatch::findOrFail($batchId), $user);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang));
    }

    /**
     * Compare the two most recent accepted batches from different sources.
     *
     * Two is the useful number: the MTO's claim and the model's. Comparing an
     * MTO against another MTO revision is a different report (what changed
     * between revisions) and deliberately not this button.
     */
    public function reconcile(Request $request, string $projectCode, string $lineId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($lineId, $user): void {
                $line = PipingLine::findOrFail($lineId);

                $mto = BaselineBatch::where('piping_line_id', $line->id)
                    ->where('source_kind', BaselineBatch::SOURCE_MTO)
                    ->where('state', BaselineBatch::ACCEPTED)
                    ->latest()->first();

                $model = BaselineBatch::where('piping_line_id', $line->id)
                    ->where('source_kind', BaselineBatch::SOURCE_PCF)
                    ->where('state', BaselineBatch::ACCEPTED)
                    ->latest()->first();

                if ($mto === null || $model === null) {
                    throw ValidationException::withMessages([
                        'file' => __('epcc.baseline.need_two_accepted_batches'),
                    ]);
                }

                $this->baseline->reconcile($line, $user, $mto, $model);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang));
    }

    public function resolve(Request $request, string $projectCode, string $lineId, string $discrepancyId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'state' => ['required', 'string', 'max:32'],
            'resolution_note' => ['nullable', 'string', 'max:2000'],
            'other_quantity' => ['nullable', 'numeric'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($discrepancyId, $user, $data): void {
                $this->baseline->resolve(
                    BaselineDiscrepancy::findOrFail($discrepancyId),
                    $user,
                    $data['state'],
                    (string) ($data['resolution_note'] ?? ''),
                    isset($data['other_quantity']) ? (float) $data['other_quantity'] : null,
                );
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->backTo($projectCode, $lineId, $lang))->withErrors($e->errors());
        }

        return redirect($this->backTo($projectCode, $lineId, $lang));
    }

    private function backTo(string $projectCode, string $lineId, string $lang): string
    {
        return "/projects/{$projectCode}/piping/{$lineId}/baseline?".http_build_query(['lang' => $lang]);
    }

    private function resolveProject($user, string $projectCode): Project
    {
        $project = Project::where('code', $projectCode)->first();

        abort_if($project === null, 404);

        $participates = $project->owner_company_id === $user->company_id
            || DB::table('project_company')
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
