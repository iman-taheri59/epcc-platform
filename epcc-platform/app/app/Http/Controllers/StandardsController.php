<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Standard;
use App\Models\StandardCitation;
use App\Services\StandardsLibrary;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** The library of codes and standards, and what this project works to. */
final class StandardsController extends Controller
{
    public function __construct(
        private readonly StandardsLibrary $library,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * Two lists on one screen: what this project works to, and the catalogue
     * it can draw from.
     *
     * Together rather than on separate pages because the question an engineer
     * arrives with is "is B31.3 on our list, and if not can I add it" — which
     * needs both halves visible at once.
     */
    public function index(Request $request, string $projectCode): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $category = $request->query('category');
        $category = is_string($category) && Standard::categoryIsValid($category) ? $category : null;

        [$adopted, $usage] = $this->tenant->runAs($user->company_id, function () use ($project): array {
            $adopted = $this->library->forProject($project);

            /*
             * How many times each standard is actually cited. This is the
             * number that makes the page worth opening: a standard on the
             * applicability list that nothing cites is either dead weight or
             * a gap in the ITPs, and both are worth seeing.
             */
            $usage = DB::table('standard_citations')
                ->where('project_id', $project->id)
                ->select('standard_id', DB::raw('count(*) as n'))
                ->groupBy('standard_id')
                ->pluck('n', 'standard_id');

            return [$adopted, $usage];
        }, $project->id);

        return view('standards.index', [
            'project' => $project,
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'fa' => $lang === 'fa',
            'adopted' => $adopted,
            'usage' => $usage,
            'catalogue' => $this->library->catalogue($category),
            'categories' => Standard::CATEGORIES,
            'category' => $category,
        ]);
    }

    /** Record that this project works to a standard. */
    public function adopt(Request $request, string $projectCode): RedirectResponse
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        $standardId = (string) $request->input('standard_id');
        $edition = $request->input('edition');
        $edition = is_string($edition) && trim($edition) !== '' ? trim($edition) : null;

        try {
            $this->tenant->runAs($user->company_id, function () use ($project, $standardId, $edition): void {
                $standard = Standard::findOrFail($standardId);
                $this->library->adopt($project, $standard, $edition);
            }, $project->id);
        } catch (ValidationException $e) {
            return redirect($this->indexUrl($projectCode, $lang))->withErrors($e->errors());
        }

        return redirect($this->indexUrl($projectCode, $lang));
    }

    /**
     * Everything in this project that cites a given standard.
     *
     * The question the whole library exists to answer. On the day a project
     * moves to a new edition of B31.3, this is the list of inspections that
     * have to be re-checked — unanswerable while the standard was prose in a
     * text column.
     */
    public function usage(Request $request, string $projectCode, string $standardId): View
    {
        $lang = $this->lang($request);
        $user = $request->user();
        $project = $this->resolveProject($user, $projectCode);

        [$standard, $adoption, $rows] = $this->tenant->runAs($user->company_id, function () use ($project, $standardId): array {
            $standard = Standard::findOrFail($standardId);

            $adoption = $this->library->forProject($project, activeOnly: false)
                ->firstWhere('standard_id', $standard->id);

            /*
             * Resolved to the ITP row, so the list is navigable rather than a
             * column of UUIDs. Joined rather than looped: a citation list can
             * be long, and N+1 on a page whose entire purpose is a list would
             * be indefensible.
             */
            $rows = DB::table('standard_citations as sc')
                ->join('itp_stages as st', 'st.id', '=', 'sc.citable_id')
                ->join('itps as i', 'i.id', '=', 'st.itp_id')
                ->where('sc.project_id', $project->id)
                ->where('sc.standard_id', $standard->id)
                ->where('sc.citable_type', StandardCitation::ITP_STAGE)
                ->orderBy('i.discipline')
                ->orderBy('st.sequence')
                ->select([
                    'sc.clause as cited_clause',
                    'st.clause as stage_clause',
                    'st.title_en', 'st.title_fa',
                    'i.id as itp_id', 'i.discipline', 'i.state',
                    'i.title_en as itp_title_en', 'i.title_fa as itp_title_fa',
                ])
                ->get();

            return [$standard, $adoption, $rows];
        }, $project->id);

        return view('standards.usage', [
            'project' => $project,
            'lang' => $lang,
            'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'fa' => $lang === 'fa',
            'standard' => $standard,
            'adoption' => $adoption,
            'rows' => $rows,
        ]);
    }

    private function indexUrl(string $projectCode, string $lang): string
    {
        return "/projects/{$projectCode}/standards?".http_build_query(['lang' => $lang]);
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
