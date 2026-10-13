<?php

declare(strict_types=1);
namespace App\Services\Engineering;

use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Services\FittingReviewService;
use App\Services\ProjectFittingDefinitionService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;

/** User-selected policy: current independent reviewer, record-only, cited N/A acceptance allowed. */
final class MaterialFindingDispositions
{
    public function __construct(private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $definitions,private readonly FittingReviewService $reviews,
        private readonly ProjectArchiveService $archives) {}
    public static function options(string $responseKind): array
    {
        return match($responseKind){
            'EXPLANATION'=>['ACCEPT_EXPLANATION','REQUEST_EVIDENCE'],
            'NA_REQUEST'=>['ACCEPT_NA','REJECT_NA','REQUEST_EVIDENCE'],default=>[],
        };
    }
    public function record(Project $project,User $actor,string $definitionId,string $responseId,array $input): string
    {
        $this->definitions->assertAccess($project,$actor);$this->archives->assertWritable($project);
        abort_unless(Str::isUuid($definitionId)&&Str::isUuid($responseId),404);
        $rules=['response_id'=>['required','uuid'],'run_id'=>['required','uuid'],'finding_key'=>['required','regex:/^[0-9a-f]{64}$/'],
            'request_key'=>['required','uuid'],'kind'=>['required',Rule::in(['ACCEPT_EXPLANATION','REQUEST_EVIDENCE','REJECT_NA','ACCEPT_NA'])],
            'rationale'=>['required','string','max:4000'],'spec_number'=>['required','string','max:120'],
            'spec_revision'=>['required','string','max:60'],'spec_clause'=>['required','string','max:120'],
            'source_quote'=>['nullable','required_if:kind,ACCEPT_NA','string','max:4000'],
            'supersedes_id'=>['nullable','uuid'],'change_reason'=>['nullable','string','max:4000']];
        $attributes=[];foreach(array_keys($rules) as $field){$attributes[$field]=__('finding_dispositions.'.$field);}
        $data=Validator::make($input,$rules,[],$attributes)->validate();
        foreach(['rationale','spec_number','spec_revision','spec_clause'] as $field){
            if(trim($data[$field])===''){throw ValidationException::withMessages([$field=>__('finding_dispositions.required_source')]);}
        }
        $data['supersedes_id']=$data['supersedes_id']??null;$data['change_reason']=$data['change_reason']??null;
        if($data['kind']==='ACCEPT_NA'&&trim($data['source_quote']??'')===''){
            throw ValidationException::withMessages(['source_quote'=>__('finding_dispositions.required_source')]);
        }
        if(($data['supersedes_id']!==null&&trim($data['change_reason']??'')==='')
            ||($data['supersedes_id']===null&&$data['change_reason']!==null)){
            throw ValidationException::withMessages(['change_reason'=>__('finding_dispositions.change_required')]);
        }
        try{return $this->tenant->runAs($actor->company_id,fn()=>DB::transaction(function()use($project,$actor,$definitionId,$responseId,$data){
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['fitting-review:'.$project->id]);
            $row=ProjectFittingDefinition::where('project_id',$project->id)->whereKey($definitionId)->firstOrFail();
            $state=$this->reviews->dashboard($project,$actor,[$row],$row->material_family)['reviewStates'][$row->id];
            abort_unless($state['can_decide']&&$state['state']==='IN_REVIEW',403);
            $note=DB::table('material_finding_responses')->where('project_id',$project->id)->where('id',$responseId)->first();
            abort_unless($note&&$note->created_by!==$actor->id,403);
            if($data['response_id']!==$responseId || $note->run_id!==($state['run']->id??null)
                || $data['run_id']!==$note->run_id || !hash_equals($note->finding_key,$data['finding_key'])){
                throw ValidationException::withMessages(['response_id'=>__('finding_dispositions.stale')]);
            }
            if(!in_array($data['kind'],self::options($note->kind),true)){
                throw ValidationException::withMessages(['kind'=>__('finding_dispositions.wrong_kind')]);
            }
            $latest=DB::table('material_finding_dispositions')->where('response_id',$responseId)->orderByDesc('sequence')->first();
            if(($latest->id??null)!==$data['supersedes_id']){
                throw ValidationException::withMessages(['supersedes_id'=>__('finding_dispositions.stale')]);
            }
            DB::table('material_finding_dispositions')->insert($data+[
                'id'=>(string)Str::uuid(),'project_id'=>$project->id,'company_id'=>$actor->company_id,
                'created_by'=>$actor->id,'created_at'=>now(),'actor_name'=>$actor->name,
                'review_stage'=>count($state['decisions'])+1,'effect'=>'RECORD_ONLY',
            ]);
            return $row->material_family;
        }),$project->id);}catch(QueryException $e){
            if($e->getCode()==='42501'){abort(403);}
            if($e->getCode()==='23505'){throw ValidationException::withMessages(['request_key'=>__('finding_dispositions.duplicate')]);}
            throw $e;
        }
    }
}
