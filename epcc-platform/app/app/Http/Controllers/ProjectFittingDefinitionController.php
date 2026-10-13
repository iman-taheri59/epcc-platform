<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Services\ProjectArchiveService;
use App\Services\ProjectFittingDefinitionService;
use App\Services\FittingReviewService;
use App\Services\ProjectMaterialSchema;
use App\Services\Engineering\MaterialScreeningEvidence;
use App\Services\Engineering\MaterialFindingResponses;
use App\Services\Engineering\MaterialFindingDispositions;
use App\Support\TenantContext;
use Illuminate\Http\Request;

final class ProjectFittingDefinitionController extends Controller
{
    public function __construct(
        private readonly ProjectFittingDefinitionService $service,
        private readonly TenantContext $tenant,
        private readonly ProjectArchiveService $archives,
        private readonly FittingReviewService $reviews,
        private readonly MaterialScreeningEvidence $evidence,
        private readonly MaterialFindingResponses $findingResponses,
        private readonly MaterialFindingDispositions $findingDispositions,
    ) {}

    public function index(Request $request, string $projectCode)
    {
        $lang = $request->input('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);
        $user = $request->user();
        $project = Project::where('code', $projectCode)->firstOrFail();
        $this->service->assertAccess($project, $user);
        $family = ProjectMaterialSchema::family($request->all());
        $rows = $this->tenant->runAs($user->company_id,
            fn () => ProjectFittingDefinition::where('project_id', $project->id)
                ->where('material_family',$family)->orderByDesc('created_at')->orderBy('id')->paginate(20)->withQueryString(), $project->id);

        $review = $this->reviews->dashboard($project, $user, $rows, $family);
        $editing = $request->filled('correct') ? $this->reviews->correctionSource($project, $user, (string) $request->query('correct')) : null;
        abort_if($editing && $editing->material_family !== $family, 404);
        // Only authorized, tenant-scoped stored records. Never trust posted findings or approval flags.
        $screenings=[];
        if ($family !== 'FITTING') {
            foreach ($rows as $row) {
                $screenings[$row->id]=$this->evidence->current($row);
            }
        }
        return view('piping.project-fittings', [
            'family'=>$family, 'screenings'=>$screenings,
            'findingResponses'=>$this->findingResponses->dashboard($project,$user,$rows,$review['reviewStates']),
            'lang' => $lang, 'dir' => $lang === 'fa' ? 'rtl' : 'ltr',
            'user' => $user, 'project' => $project, 'rows' => $rows,
            'canCreate' => $this->archives->isWritable($project), 'editing' => $editing,
        ] + $review);
    }

    public function store(Request $request, string $projectCode)
    {
        $lang = $request->input('lang') === 'en' ? 'en' : 'fa';
        app()->setLocale($lang);
        $project = Project::where('code', $projectCode)->firstOrFail();
        ProjectMaterialSchema::family($request->all());
        $created=$this->service->create($project, $request->user(), $request->all());
        $query = ['lang' => $lang];
        if ($created->material_family !== 'FITTING') { $query['family']=$created->material_family; }
        if ($request->query('sid')) { $query['sid'] = $request->query('sid'); }

        return redirect('/projects/'.rawurlencode($projectCode).'/piping/project-fittings?'.http_build_query($query))
            ->with('status', __('project_fittings.saved'));
    }
    public function configure(Request $request, string $projectCode)
    {
        [$project,$lang] = $this->context($request,$projectCode);
        $this->reviews->configure($project,$request->user(),$request->all());
        return $this->reviewRedirect($request,$projectCode,$lang);
    }

    public function submit(Request $request, string $projectCode, string $definitionId)
    {
        [$project,$lang] = $this->context($request,$projectCode);
        $this->reviews->submit($project,$request->user(),$definitionId);
        return $this->reviewRedirect($request,$projectCode,$lang);
    }

    public function decide(Request $request, string $projectCode, string $definitionId)
    {
        [$project,$lang] = $this->context($request,$projectCode);
        $this->reviews->decide($project,$request->user(),$definitionId,$request->all());
        return $this->reviewRedirect($request,$projectCode,$lang);
    }

    public function correct(Request $request, string $projectCode, string $definitionId)
    {
        [$project,$lang] = $this->context($request,$projectCode);
        $this->reviews->correct($project,$request->user(),$definitionId,$request->all());
        return $this->reviewRedirect($request,$projectCode,$lang);
    }

    public function respondToFinding(Request $request, string $projectCode, string $definitionId)
    {
        [$project,$lang]=$this->context($request,$projectCode);
        $data=$request->validate(['finding_response'=>['required','array']],[],['finding_response'=>__('finding_responses.title')]);
        $family=$this->findingResponses->respond($project,$request->user(),$definitionId,$data['finding_response']);
        $request->merge(['family'=>$family]);
        return $this->reviewRedirect($request,$projectCode,$lang)->with('status',__('finding_responses.saved'));
    }

    public function disposeFindingResponse(Request $request, string $projectCode, string $definitionId, string $responseId)
    {
        [$project,$lang]=$this->context($request,$projectCode);
        $data=$request->validate(['finding_disposition'=>['required','array']],[],['finding_disposition'=>__('finding_dispositions.title')]);
        $family=$this->findingDispositions->record($project,$request->user(),$definitionId,$responseId,$data['finding_disposition']);
        $request->merge(['family'=>$family]);
        return $this->reviewRedirect($request,$projectCode,$lang)->with('status',__('finding_dispositions.saved'));
    }

    private function context(Request $request, string $projectCode): array
    {
        ProjectMaterialSchema::family($request->all());
        $lang=$request->input('lang')==='en'?'en':'fa'; app()->setLocale($lang);
        return [Project::where('code',$projectCode)->firstOrFail(),$lang];
    }

    private function reviewRedirect(Request $request, string $code, string $lang)
    {
        $q=['lang'=>$lang]; if (ProjectMaterialSchema::family($request->all()) !== 'FITTING') { $q['family']=ProjectMaterialSchema::family($request->all()); } if ($request->query('sid')) { $q['sid']=$request->query('sid'); }
        return redirect('/projects/'.rawurlencode($code).'/piping/project-fittings?'.http_build_query($q))
            ->with('status',__('fitting_review.saved'));
    }

}
