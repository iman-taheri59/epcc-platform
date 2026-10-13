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

/** Append-only cited discussion bound to an immutable captured finding; never changes engineering/review state. */
final class MaterialFindingResponses
{
    public function __construct(private readonly TenantContext $tenant,
        private readonly ProjectFittingDefinitionService $definitions, private readonly FittingReviewService $reviews,
        private readonly ProjectArchiveService $archives) {}

    public static function findingKey(string $resultHash,int $index): string
    {
        return hash('sha256','material-finding-v1:'.$resultHash.':'.$index);
    }
    private function role(Project $project,User $actor,ProjectFittingDefinition $row,array $state): ?string
    {
        if (!$state['screeningSnapshot'] || !$this->archives->isWritable($project)
            || !in_array($state['state'],['IN_REVIEW','CHANGES_REQUESTED'],true)) {return null;}
        $membership=$actor->membershipFor($project->id);
        if (!$membership || ($membership->discipline_scope!==null && !in_array('PIPING',$membership->discipline_scope,true))) {return null;}
        if ($state['can_decide']) {return 'REVIEWER';}
        return $row->company_id===$actor->company_id?'AUTHOR':null;
    }
    public function dashboard(Project $project,User $actor,iterable $rows,array $states): array
    {
        $this->definitions->assertAccess($project,$actor);
        $rows=is_array($rows)?$rows:iterator_to_array($rows,false);
        return $this->tenant->runAs($actor->company_id,function()use($project,$actor,$rows,$states){
            $out=[];$runIds=[];
            foreach($rows as $row){
                $state=$states[$row->id];$snapshot=$state['screeningSnapshot'];
                if (!$snapshot) {continue;}
                $runIds[]=$snapshot['record']->run_id;
                $out[$row->id]=['role'=>$this->role($project,$actor,$row,$state),'threads'=>[]];
            }
            $notes=$runIds===[]?collect():DB::table('material_finding_responses')->where('project_id',$project->id)
                ->whereIn('run_id',$runIds)->orderBy('sequence')->get();
            $histories=$notes->isEmpty()?collect():DB::table('material_finding_dispositions')->where('project_id',$project->id)
                ->whereIn('response_id',$notes->pluck('id'))->orderBy('sequence')->get()->groupBy('response_id');
            $byRun=[];foreach($notes as $note){
                $note->dispositions=isset($histories[$note->id])?$histories[$note->id]->all():[];
                $byRun[$note->run_id][$note->finding_index][]=$note;
            }
            foreach($rows as $row){if(isset($out[$row->id])){$out[$row->id]['threads']=$byRun[$states[$row->id]['run']->id]??[];}}
            return $out;
        },$project->id);
    }
    public function respond(Project $project,User $actor,string $definitionId,array $input): string
    {
        $this->definitions->assertAccess($project,$actor);$this->archives->assertWritable($project);
        abort_unless(Str::isUuid($definitionId),404);
        $rules=['run_id'=>['required','uuid'],'finding_index'=>['required','integer','min:0','max:32767'],
            'finding_key'=>['required','regex:/^[0-9a-f]{64}$/'],'request_key'=>['required','uuid'],
            'kind'=>['required',Rule::in(['EXPLANATION','NA_REQUEST','REVIEW_NOTE'])],
            'message'=>['required','string','max:4000'],'spec_number'=>['required','string','max:120'],
            'spec_revision'=>['required','string','max:60'],'spec_clause'=>['required','string','max:120'],
            'source_quote'=>['nullable','required_if:kind,NA_REQUEST','string','max:4000']];
        $attributes=[];foreach(array_keys($rules) as $field){$attributes[$field]=__('finding_responses.'.$field);}
        $data=Validator::make($input,$rules,[],$attributes)->validate();
        foreach(['message','spec_number','spec_revision','spec_clause'] as $field){
            if(trim($data[$field])===''){throw ValidationException::withMessages([$field=>__('finding_responses.citation_required')]);}
        }
        if($data['kind']==='NA_REQUEST' && trim($data['source_quote']??'')===''){
            throw ValidationException::withMessages(['source_quote'=>__('finding_responses.quote_required')]);
        }
        try {
            return $this->tenant->runAs($actor->company_id,fn()=>DB::transaction(function()use($project,$actor,$definitionId,$data){
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',['fitting-review:'.$project->id]);
                $row=ProjectFittingDefinition::where('project_id',$project->id)->whereKey($definitionId)->firstOrFail();
                $state=$this->reviews->dashboard($project,$actor,[$row],$row->material_family)['reviewStates'][$row->id];
                $snapshot=$state['screeningSnapshot'];$index=(int)$data['finding_index'];
                if(!$snapshot || $snapshot['record']->run_id!==$data['run_id']
                    || !isset($snapshot['result']['findings'][$index])
                    || !hash_equals(self::findingKey($snapshot['record']->result_sha256,$index),$data['finding_key'])){
                    throw ValidationException::withMessages(['finding_key'=>__('finding_responses.stale')]);
                }
                $role=$this->role($project,$actor,$row,$state);
                abort_unless($role && (($role==='REVIEWER')===($data['kind']==='REVIEW_NOTE')),403);
                $finding=$snapshot['result']['findings'][$index];
                DB::table('material_finding_responses')->insert($data+[
                    'id'=>(string)Str::uuid(),'project_id'=>$project->id,'company_id'=>$actor->company_id,
                    'created_by'=>$actor->id,'created_at'=>now(),'actor_name'=>$actor->name,'actor_kind'=>$role,
                    'review_stage'=>$role==='REVIEWER'?count($state['decisions'])+1:null,
                    'finding_code'=>$finding['code'],'finding_field'=>$finding['field'],
                ]);
                return $row->material_family;
            }),$project->id);
        }catch(QueryException $e){
            if($e->getCode()==='42501'){abort(403);}
            if($e->getCode()==='23505'){throw ValidationException::withMessages(['request_key'=>__('finding_responses.duplicate')]);}
            throw $e;
        }
    }
}
