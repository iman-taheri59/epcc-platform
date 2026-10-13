<?php

declare(strict_types=1);
namespace Tests\Feature;
use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Services\FittingReviewService;
use App\Services\ProjectFittingDefinitionService;
use App\Services\Engineering\MaterialFindingResponses;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class MaterialFindingResponseTest extends TestCase
{
    private Project $p;private User $author;private User $owner;private User $pmc;private ProjectFittingDefinition $row;
    protected function setUp(): void
    {
        parent::setUp();$this->artisan('migrate:fresh',['--force'=>true]);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);$this->seed(\Database\Seeders\AuthSeeder::class);
        $this->p=Project::where('code','PH-14')->firstOrFail();$this->author=$this->userByEmail('rezaei@pars.example');
        $this->owner=$this->userByEmail('karimi@nioc.example');$this->pmc=$this->userByEmail('tehrani@pmc.example');
        $this->row=app(ProjectFittingDefinitionService::class)->create($this->p,$this->author,$this->data());
        $this->review()->configure($this->p,$this->owner,['family'=>'PIPE','stages'=>['role:'.$this->pmc->company_id.':consultant','user:'.$this->owner->id]]);
        $this->review()->submit($this->p,$this->author,$this->row->id);
    }
    private function data(array $change=[]): array
    {
        return array_replace(['component_type'=>'PIPE','qualifier'=>'SEAMLESS','size_designation'=>'NPS 6 in',
            'reference_standard'=>'ASME B36.10M-2022','material_spec'=>'SYNTHETIC QA','declaration_reason'=>'Synthetic test only',
            'spec_number'=>'RESPONSE-QA','spec_revision'=>'A','spec_clause'=>'1','source_text'=>'Synthetic source wording.',
            'properties'=>['wall_thickness_mm'=>'10']],$change);
    }
    private function svc(): MaterialFindingResponses {return app(MaterialFindingResponses::class);}
    private function review(): FittingReviewService {return app(FittingReviewService::class);}
    private function bound(callable $fn,?User $actor=null,?Project $p=null): mixed
    {return app(TenantContext::class)->runAs(($actor??$this->author)->company_id,$fn,($p??$this->p)->id);}
    private function state(?ProjectFittingDefinition $row=null): array
    {
        $row??=$this->row;return $this->review()->dashboard($this->p,$this->owner,[$row],'PIPE')['reviewStates'][$row->id];
    }
    private function payload(array $change=[],?ProjectFittingDefinition $row=null): array
    {
        $s=$this->state($row)['screeningSnapshot'];
        return array_replace(['run_id'=>$s['record']->run_id,'finding_index'=>0,
            'finding_key'=>MaterialFindingResponses::findingKey($s['record']->result_sha256,0),'request_key'=>(string)Str::uuid(),
            'kind'=>'EXPLANATION','message'=>'Synthetic clarification.','spec_number'=>'EVIDENCE-QA','spec_revision'=>'B',
            'spec_clause'=>'QA-2','source_quote'=>null],$change);
    }
    private function send(?User $actor=null,array $change=[],?ProjectFittingDefinition $row=null): void
    {$this->svc()->respond($this->p,$actor??$this->author,($row??$this->row)->id,$this->payload($change,$row));}
    private function notes(): array {return $this->bound(fn()=>DB::table('material_finding_responses')->orderBy('sequence')->get()->all());}
    private function refuse(callable $fn): void
    {
        try{$fn();self::fail('Operation should be refused');}catch(ValidationException|HttpException $e){
            if($e instanceof HttpException){self::assertContains($e->getStatusCode(),[403,404]);}else{self::assertNotEmpty($e->errors());}
        }
    }
    private function decide(User $actor,int $stage,string $decision='APPROVE'): void
    {$this->review()->decide($this->p,$actor,$this->row->id,['stage'=>$stage,'decision'=>$decision,'comment'=>'Synthetic review decision']);}
    public function test_cited_author_response_preserves_source_snapshot_and_review_status(): void
    {
        $before=$this->state();$source=$this->bound(fn()=>$this->row->fresh()->getAttributes());
        $this->send();$note=$this->notes()[0];
        self::assertSame('AUTHOR',$note->actor_kind);self::assertNull($note->review_stage);self::assertSame(1,$note->sequence);
        self::assertSame($this->author->id,$note->created_by);self::assertSame('B',$note->spec_revision);
        self::assertSame('A',$this->row->spec_revision);self::assertSame('IN_REVIEW',$this->state()['state']);
        self::assertSame($before['screeningSnapshot']['record']->result_json,$this->state()['screeningSnapshot']['record']->result_json);
        self::assertSame($source,$this->bound(fn()=>$this->row->fresh()->getAttributes()));self::assertCount(0,$this->state()['decisions']);
    }
    public function test_not_applicable_is_a_cited_proposal_not_automatic_resolution(): void
    {
        foreach([['source_quote'=>null],['source_quote'=>' '],['message'=>' '],['spec_clause'=>' ']] as $change){
            $this->refuse(fn()=>$this->send(null,array_replace(['kind'=>'NA_REQUEST','source_quote'=>'Synthetic excerpt'],$change)));
        }
        self::assertCount(0,$this->notes());
        $this->send(null,['kind'=>'NA_REQUEST','source_quote'=>'Synthetic excerpt; not a real engineering instruction.']);
        self::assertSame('NA_REQUEST',$this->notes()[0]->kind);self::assertSame('IN_REVIEW',$this->state()['state']);
        self::assertFalse($this->state()['screeningSnapshot']['result']['engineering_approved']);
        $this->refuse(fn()=>$this->send(null,['kind'=>'ACCEPT_NA']));
    }
    public function test_only_current_independent_reviewer_can_record_reviewer_note(): void
    {
        $this->refuse(fn()=>$this->send($this->author,['kind'=>'REVIEW_NOTE']));
        $this->refuse(fn()=>$this->send($this->owner,['kind'=>'REVIEW_NOTE']));
        $this->send($this->pmc,['kind'=>'REVIEW_NOTE']);$note=$this->notes()[0];
        self::assertSame('REVIEWER',$note->actor_kind);self::assertSame(1,$note->review_stage);
        $this->refuse(fn()=>$this->send($this->pmc,['kind'=>'NA_REQUEST','source_quote'=>'x']));
        self::assertCount(0,$this->state()['decisions']);
    }
    public function test_future_policy_does_not_reassign_response_authority_and_stage_advances_only_by_decision(): void
    {
        $this->review()->configure($this->p,$this->owner,['family'=>'PIPE','stages'=>['user:'.$this->owner->id]]);
        $this->refuse(fn()=>$this->send($this->owner,['kind'=>'REVIEW_NOTE']));
        $this->send($this->pmc,['kind'=>'REVIEW_NOTE']);$this->decide($this->pmc,1);
        $this->refuse(fn()=>$this->send($this->pmc,['kind'=>'REVIEW_NOTE']));
        $this->send($this->owner,['kind'=>'REVIEW_NOTE']);$notes=$this->notes();
        self::assertSame([1,2],array_column($notes,'sequence'));self::assertSame([1,2],array_column($notes,'review_stage'));
        self::assertSame('IN_REVIEW',$this->state()['state']);
    }
    public function test_same_company_independent_reviewer_uses_reviewer_role_not_author_mode(): void
    {
        $qc=$this->userByEmail('sadeghi@pars.example');
        $this->review()->configure($this->p,$this->owner,['family'=>'PIPE','stages'=>['user:'.$qc->id]]);
        $row=app(ProjectFittingDefinitionService::class)->create($this->p,$this->author,$this->data(['spec_number'=>'QC-REVIEW']));
        $this->review()->submit($this->p,$this->author,$row->id);
        $this->refuse(fn()=>$this->send($qc,[],$row));$this->send($qc,['kind'=>'REVIEW_NOTE'],$row);
        self::assertSame('REVIEWER',$this->notes()[0]->actor_kind);
    }
    public function test_current_membership_role_discipline_and_activity_are_required(): void
    {
        $this->bound(fn()=>DB::table('project_memberships')->where('project_id',$this->p->id)->where('user_id',$this->pmc->id)->update(['role_codes'=>'[]']),$this->owner);
        $this->refuse(fn()=>$this->send($this->pmc,['kind'=>'REVIEW_NOTE']));
        $this->bound(fn()=>DB::table('project_memberships')->where('project_id',$this->p->id)->where('user_id',$this->author->id)->update(['discipline_scope'=>'["CIVIL"]']),$this->owner);
        $this->refuse(fn()=>$this->send());
        $this->bound(fn()=>DB::table('project_memberships')->where('project_id',$this->p->id)->where('user_id',$this->author->id)->update(['discipline_scope'=>null]),$this->owner);
        app(TenantContext::class)->forIdentityLookup(fn()=>User::whereKey($this->author->id)->update(['is_active'=>false]));
        $this->refuse(fn()=>$this->send());self::assertCount(0,$this->notes());
    }
    public function test_return_and_correction_keep_old_threads_without_inheriting_them(): void
    {
        $this->send();$this->decide($this->pmc,1,'RETURN');
        $this->refuse(fn()=>$this->send($this->pmc,['kind'=>'REVIEW_NOTE']));
        $this->send(null,['message'=>'Clarification after return']);
        $new=$this->review()->correct($this->p,$this->author,$this->row->id,$this->data(['source_text'=>'Corrected source text','correction_reason'=>'Fix source transcription']));
        $this->refuse(fn()=>$this->send());self::assertNull($this->state($new)['screeningSnapshot']);
        $this->review()->submit($this->p,$this->author,$new->id);$state=$this->state($new);
        $dashboard=$this->svc()->dashboard($this->p,$this->author,[$new],[$new->id=>$state]);
        self::assertSame([],$dashboard[$new->id]['threads']);self::assertCount(2,$this->notes());
        $this->send(null,['message'=>'New version response'],$new);self::assertCount(3,$this->notes());
    }
    public function test_approved_and_archived_versions_are_read_only(): void
    {
        $this->send();$this->decide($this->pmc,1);$this->decide($this->owner,2);
        $this->refuse(fn()=>$this->send());$this->refuse(fn()=>$this->send($this->owner,['kind'=>'REVIEW_NOTE']));
        DB::table('projects')->where('id',$this->p->id)->update(['state'=>'ARCHIVED','archived_at'=>now(),'archived_by'=>$this->owner->id]);
        $this->refuse(fn()=>$this->send());
        $this->actingAs($this->author)->get('/projects/PH-14/piping/project-fittings?family=PIPE&lang=en')
            ->assertOk()->assertSee('Synthetic clarification.')->assertDontSee('class="spec-grid finding-response-form"',false);
    }
    public function test_retry_token_prevents_duplicates_and_corrections_append_in_sequence(): void
    {
        $payload=$this->payload();$this->svc()->respond($this->p,$this->author,$this->row->id,$payload);$before=(array)$this->notes()[0];
        $this->refuse(fn()=>$this->svc()->respond($this->p,$this->author,$this->row->id,$payload));
        $this->send(null,['message'=>'Correction to note 1: revised clarification']);
        $notes=$this->notes();self::assertCount(2,$notes);self::assertSame($before,(array)$notes[0]);self::assertSame(2,$notes[1]->sequence);
    }
    public function test_finding_identity_is_bound_and_server_assigns_identity_and_role(): void
    {
        foreach([['run_id'=>(string)Str::uuid()],['finding_index'=>9999],['finding_key'=>str_repeat('0',64)]] as $change){$this->refuse(fn()=>$this->send(null,$change));}
        $this->send(null,['created_by'=>$this->owner->id,'actor_kind'=>'REVIEWER','review_stage'=>5,'sequence'=>999,'actor_name'=>'Forged','project_id'=>(string)Str::uuid()]);
        $n=$this->notes()[0];self::assertSame($this->author->id,$n->created_by);self::assertSame('AUTHOR',$n->actor_kind);
        self::assertSame(1,$n->sequence);self::assertNull($n->review_stage);self::assertSame($this->author->name,$n->actor_name);
    }
    public function test_notes_are_append_only_and_hidden_from_foreign_contexts(): void
    {
        $this->send();$this->bound(function(){
            self::assertSame(0,DB::table('material_finding_responses')->update(['message'=>'Overwrite']));
            self::assertSame(0,DB::table('material_finding_responses')->delete());
        });
        $other=Project::where('code','KISH-1')->firstOrFail();
        $this->bound(fn()=>self::assertSame(0,DB::table('material_finding_responses')->count()),$this->owner,$other);
        $outside=$this->userByEmail('hosseini@arvand.example');
        $this->bound(fn()=>self::assertSame(0,DB::table('material_finding_responses')->count()),$outside);
        $this->refuse(fn()=>$this->send($outside));
        $this->actingAs($outside)->get('/projects/PH-14/piping/project-fittings?family=PIPE')->assertNotFound();
    }
    public function test_http_forms_are_only_on_historical_findings_and_escape_notes_in_both_languages(): void
    {
        $base='/projects/PH-14/piping/project-fittings';$payload=$this->payload(['message'=>'<script>response()</script>','kind'=>'NA_REQUEST','source_quote'=>'<b>Quoted source</b>']);
        $this->actingAs($this->author)->post($base.'/'.$this->row->id.'/finding-responses?family=PIPE&lang=en',['finding_response'=>$payload])
            ->assertRedirect($base.'?lang=en&family=PIPE');
        foreach(['fa','en'] as $lang){
            $r=$this->get($base.'?family=PIPE&lang='.$lang)->assertOk()->assertSee('&lt;script&gt;response()',false)
                ->assertDontSee('<script>response()',false)->assertSee('&lt;b&gt;Quoted source&lt;/b&gt;',false);
            $html=$r->getContent();$start=strpos($html,'data-screening=');$end=strpos($html,'data-screening-snapshot=');
            self::assertStringNotContainsString('finding-response-form',substr($html,$start,$end-$start));
            self::assertStringContainsString('finding-response-form',substr($html,$end));
        }
    }
    public function test_raw_sql_guard_rejects_wrong_finding_role_stage_and_missing_na_evidence(): void
    {
        $state=$this->state();$f=$state['screeningSnapshot']['result']['findings'][0];
        foreach(['key','index','code','self_review','wrong_stage','quote'] as $case){
            $actor=$case==='wrong_stage'?$this->pmc:$this->author;
            $data=$this->payload()+['id'=>(string)Str::uuid(),'project_id'=>$this->p->id,'company_id'=>$actor->company_id,
                'created_by'=>$actor->id,'created_at'=>now(),'actor_name'=>$actor->name,'actor_kind'=>'AUTHOR','review_stage'=>null,
                'finding_code'=>$f['code'],'finding_field'=>$f['field']];
            $code='42501';
            if($case==='key'){$data['finding_key']=str_repeat('0',64);}
            if($case==='index'){$data['finding_index']=-1;}
            if($case==='code'){$data['finding_code']='INVENTED';}
            if(in_array($case,['self_review','wrong_stage'],true)){$data['actor_kind']='REVIEWER';$data['kind']='REVIEW_NOTE';$data['review_stage']=$case==='wrong_stage'?2:1;}
            if($case==='quote'){$data['kind']='NA_REQUEST';$data['source_quote']=' ';$code='23514';}
            try{$this->bound(fn()=>DB::transaction(fn()=>DB::table('material_finding_responses')->insert($data)),$actor);self::fail('Forged note accepted: '.$case);}
            catch(QueryException $e){self::assertSame($code,$e->getCode(),$case);}
        }
        self::assertCount(0,$this->notes());
    }

    public function test_invalid_response_input_stays_scoped_to_its_thread_without_breaking_definition_form(): void
    {
        $base='/projects/PH-14/piping/project-fittings?family=PIPE&lang=en';
        $payload=$this->payload(['spec_number'=>['not','text']]);
        $this->actingAs($this->author)->from($base)->post('/projects/PH-14/piping/project-fittings/'.$this->row->id.'/finding-responses?family=PIPE&lang=en',
            ['finding_response'=>$payload])->assertSessionHasErrors('spec_number');
        $this->get($base)->assertOk()->assertSee('finding_response[spec_number]',false);
        self::assertCount(0,$this->notes());
    }
}
