<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Itp;
use App\Models\ItpRecord;
use App\Models\ItpTemplate;
use App\Models\MaterialLot;
use App\Models\Project;
use App\Services\ItpService;
use App\Services\StandardsLibrary;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Inspection and Test Plans: the register, one plan, and lot sign-off. */
final class ItpController extends Controller
{
    public function __construct(
        private readonly ItpService $itp,
        private readonly TenantContext $tenant,
    ) {}

    /** The project's ITPs, by discipline. */
    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$itps, $templates] = $this->tenant->runAs($user->company_id, fn (): array => [
            Itp::with('document')->withCount('stages')
                ->where('project_id', $project->id)
                ->orderBy('discipline')
                ->orderByDesc('created_at')
                ->get(),
            ItpTemplate::where('is_active', true)->orderBy('discipline')->get(),
        ], $project->id);

        return view('itp.index', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'itps' => $itps,
            'templates' => $templates,
        ]);
    }

    /** One plan, with its stages and the full party grid. */
    public function show(Request $request, string $projectCode, string $itpId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $itp = $this->tenant->runAs(
            $user->company_id,
            function () use ($itpId, $project): Itp {
                $itp = Itp::with(['stages.parties', 'stages.documents.document', 'stages.documents.pinnedRevision', 'stages.standardCitations.standard', 'project', 'document.currentRevision', 'annexes.document'])->findOrFail($itpId);
                $adoptions = app(StandardsLibrary::class)->forProject($project, false)->keyBy('standard_id')->all();
                foreach ($itp->stages as $stage) {
                    $stage->setRelation('itp', $itp);
                    $stage->setAttribute('printed_standard_basis', $stage->printedStandards($adoptions));
                }

                return $itp;
            },
            $project->id,
        );

        return view('itp.show', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'itp' => $itp,
        ]);
    }

    public function createFromTemplate(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'template_id' => ['required', 'string'],
            'number' => ['nullable', 'string', 'max:64'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($project, $user, $data, $lang): void {
                $this->itp->createFromTemplate(
                    $project,
                    $user,
                    ItpTemplate::findOrFail($data['template_id']),
                    $data['number'] ?? null,
                    $lang,
                );
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->indexUrl($projectCode, $lang))->withErrors($e->errors());
        }

        return redirect($this->indexUrl($projectCode, $lang));
    }

    public function activate(Request $request, string $projectCode, string $itpId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($itpId, $user, $lang): void {
                $this->itp->activate(Itp::findOrFail($itpId), $user, $lang);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->indexUrl($projectCode, $lang))->withErrors($e->errors());
        }

        return redirect($this->indexUrl($projectCode, $lang));
    }

    /**
     * Enter the plan in the project document register, and bind in the report
     * forms its rows cite as annexes.
     *
     * Both in one action because they are one intent: "issue this as a
     * controlled document". Splitting them would let a plan be registered with
     * no annexes, which is not a state anybody wants and would just mean two
     * buttons the user has to remember to press in order.
     */
    public function registerDocument(Request $request, string $projectCode, string $itpId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        try {
            $this->tenant->runAs($user->company_id, function () use ($itpId, $user, $lang): void {
                $itp = Itp::findOrFail($itpId);
                $this->itp->registerAsDocument($itp, $user, $lang);
                $this->itp->syncAnnexesFromStages($itp->fresh());
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->showUrl($projectCode, $itpId, $lang))->withErrors($e->errors());
        }

        return redirect($this->showUrl($projectCode, $itpId, $lang));
    }

    /** The inspection checklist of one material lot. */
    public function lot(Request $request, string $projectCode, string $lotId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$lot, $records, $progress, $holds] = $this->tenant->runAs(
            $user->company_id,
            function () use ($lotId): array {
                $lot = MaterialLot::findOrFail($lotId);

                return [
                    $lot,
                    ItpRecord::with(['stage.parties', 'signer'])
                        ->where('material_lot_id', $lot->id)
                        ->get()
                        ->sortBy(fn (ItpRecord $r): int => $r->stage?->sequence ?? 0)
                        ->values(),
                    $this->itp->progressFor($lot),
                    $this->itp->openHoldsFor($lot),
                ];
            },
            $project->id,
        );

        return view('itp.lot', [
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user,
            'project' => $project,
            'lot' => $lot,
            'records' => $records,
            'progress' => $progress,
            'holds' => $holds,
        ]);
    }

    public function openLot(Request $request, string $projectCode, string $lotId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate(['discipline' => ['required', 'string', 'max:32']]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($lotId, $data, $lang): void {
                $this->itp->openForLot(MaterialLot::findOrFail($lotId), $data['discipline'], $lang);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->lotUrl($projectCode, $lotId, $lang))->withErrors($e->errors());
        }

        return redirect($this->lotUrl($projectCode, $lotId, $lang));
    }

    public function notify(Request $request, string $projectCode, string $lotId, string $recordId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate(['notice_reference' => ['required', 'string', 'max:128']]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($recordId, $user, $data, $lang): void {
                $this->itp->notify(ItpRecord::findOrFail($recordId), $user, $data['notice_reference'], $lang);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->lotUrl($projectCode, $lotId, $lang))->withErrors($e->errors());
        }

        return redirect($this->lotUrl($projectCode, $lotId, $lang));
    }

    public function sign(Request $request, string $projectCode, string $lotId, string $recordId): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $data = $request->validate([
            'result' => ['required', 'string', 'max:24'],
            'findings_en' => ['nullable', 'string', 'max:2000'],
            'findings_fa' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->tenant->runAs($user->company_id, function () use ($recordId, $user, $data, $lang): void {
                $this->itp->sign(
                    ItpRecord::findOrFail($recordId),
                    $user,
                    $data['result'],
                    $data['findings_en'] ?? null,
                    $data['findings_fa'] ?? null,
                    $lang,
                );
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->lotUrl($projectCode, $lotId, $lang))->withErrors($e->errors());
        }

        return redirect($this->lotUrl($projectCode, $lotId, $lang));
    }

    private function indexUrl(string $projectCode, string $lang): string
    {
        return "/projects/{$projectCode}/itp?".http_build_query(['lang' => $lang]);
    }

    private function showUrl(string $projectCode, string $itpId, string $lang): string
    {
        return "/projects/{$projectCode}/itp/{$itpId}?".http_build_query(['lang' => $lang]);
    }

    private function lotUrl(string $projectCode, string $lotId, string $lang): string
    {
        return "/projects/{$projectCode}/itp/lots/{$lotId}?".http_build_query(['lang' => $lang]);
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
        // Registration posts lang as a hidden field; other forms use the query.
        return $request->input('lang') === 'fa' ? 'fa' : 'en';
    }
}
