<?php

declare(strict_types=1);
namespace Tests\Feature;
use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Services\FittingReviewService;
use App\Services\ProjectFittingDefinitionService;
use App\Services\Engineering\MaterialFindingResponses;
use App\Services\Engineering\MaterialFindingDispositions;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class MaterialFindingDispositionTest extends TestCase
{
    private Project $p;private User $a;private User $o;private User $c;private ProjectFittingDefinition $row;private object $note;
    protected function setUp(): void
    {
        parent::setUp();$this->artisan('migrate:fresh',['--force'=>true]);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);$this->seed(\Database\Seeders\AuthSeeder::class);
        $this->p=Project::where('code','PH-14')->firstOrFail();$this->a=$this->userByEmail('rezaei@pars.example');
        $this->o=$this->userByEmail('karimi@nioc.example');$this->c=$this->userByEmail('tehrani@pmc.example');
        $this->row=app(ProjectFittingDefinitionService::class)->create($this->p,$this->a,$this->data());
        $this->review()->configure($this->p,$this->o,['family'=>'PIPE','stages'=>['role:'.$this->c->company_id.':consultant','user:'.$this->o->id]]);
        $this->review()->submit($this->p,$this->a,$this->row->id);$this->note=$this->addResponse();
    }
    private function data(array $change=[]): array
    {return array_replace(['component_type'=>'PIPE','qualifier'=>'SEAMLESS','size_designation'=>'NPS 6 in','reference_standard'=>'ASME B36.10M-2022',
        'material_spec'=>'SYNTHETIC QA','declaration_reason'=>'Test only','spec_number'=>'FORMAL-QA','spec_revision'=>'A','spec_clause'=>'1',
        'source_text'=>'Synthetic immutable source','properties'=>['wall_thickness_mm'=>'10']],$change);}
    private function review(): FittingReviewService {return app(FittingReviewService::class);}
    private function svc(): MaterialFindingDispositions {return app(MaterialFindingDispositions::class);}
    private function bound(callable $fn,?User $u=null,?Project $p=null): mixed
    {return app(TenantContext::class)->runAs(($u??$this->a)->company_id,$fn,($p??$this->p)->id);}
    private function state(?ProjectFittingDefinition $r=null): array
    {$r??=$this->row;return $this->review()->dashboard($this->p,$this->o,[$r],'PIPE')['reviewStates'][$r->id];}
    private function addResponse(string $kind='NA_REQUEST',?User $u=null,?ProjectFittingDefinition $r=null): object
    {
        $r??=$this->row;$s=$this->state($r)['screeningSnapshot'];$key=(string)Str::uuid();
        app(MaterialFindingResponses::class)->respond($this->p,$u??$this->a,$r->id,[
            'run_id'=>$s['record']->run_id,'finding_index'=>0,'finding_key'=>MaterialFindingResponses::findingKey($s['record']->result_sha256,0),
            'request_key'=>$key,'kind'=>$kind,'message'=>'Synthetic cited response','spec_number'=>'CITED-QA','spec_revision'=>'B','spec_clause'=>'2','source_quote'=>'Synthetic excerpt only',
        ]);
        return $this->bound(fn()=>DB::table('material_finding_responses')->where('request_key',$key)->first());
    }
    private function events(?object $note=null): array
    {return $this->bound(fn()=>DB::table('material_finding_dispositions')->where('response_id',($note??$this->note)->id)->orderBy('sequence')->get()->all());}
    private function payload(array $change=[],?object $note=null): array
    {
        $note??=$this->note;$all=$this->events($note);$last=$all?end($all):null;
        return array_replace(['response_id'=>$note->id,'run_id'=>$note->run_id,'finding_key'=>$note->finding_key,'request_key'=>(string)Str::uuid(),
            'kind'=>$note->kind==='EXPLANATION'?'ACCEPT_EXPLANATION':'ACCEPT_NA','rationale'=>'Synthetic rationale only',
            'spec_number'=>'DECISION-QA','spec_revision'=>'C','spec_clause'=>'3','source_quote'=>'Synthetic decision excerpt',
            'supersedes_id'=>$last?->id,'change_reason'=>null],$change);
    }
    private function record(?User $u=null,array $change=[],?object $note=null,?ProjectFittingDefinition $r=null): void
    {$note??=$this->note;$this->svc()->record($this->p,$u??$this->c,($r??$this->row)->id,$note->id,$this->payload($change,$note));}
    private function refuse(callable $fn): void
    {
        try{$fn();self::fail('Operation should be refused');}catch(ValidationException|HttpException $e){
            if($e instanceof HttpException){self::assertContains($e->getStatusCode(),[403,404]);}else{self::assertNotEmpty($e->errors());}
        }
    }
    private function decide(User $u,int $step,string $decision='APPROVE',?ProjectFittingDefinition $r=null): void
    {$this->review()->decide($this->p,$u,($r??$this->row)->id,['stage'=>$step,'decision'=>$decision,'comment'=>'Synthetic separate review decision']);}
    public function test_cited_na_acceptance_is_bound_and_record_only(): void
    {
        $s=$this->state();$before=$this->bound(fn()=>$this->row->fresh()->getAttributes());$this->record();$d=$this->events()[0];
        self::assertSame('ACCEPT_NA',$d->kind);self::assertSame('RECORD_ONLY',$d->effect);self::assertSame(1,$d->review_stage);
        self::assertSame($this->note->id,$d->response_id);self::assertSame($this->note->run_id,$d->run_id);
        self::assertSame($this->c->id,$d->created_by);self::assertSame('C',$d->spec_revision);
        self::assertSame('IN_REVIEW',$this->state()['state']);self::assertCount(0,$this->state()['decisions']);
        self::assertSame($s['screeningSnapshot']['record']->result_json,$this->state()['screeningSnapshot']['record']->result_json);
        self::assertSame($before,$this->bound(fn()=>$this->row->fresh()->getAttributes()));
    }
    public function test_decisions_are_limited_to_response_type(): void
    {
        $ex=$this->addResponse('EXPLANATION');$this->record(null,[],$ex);
        self::assertSame('ACCEPT_EXPLANATION',$this->events($ex)[0]->kind);
        $this->refuse(fn()=>$this->record(null,['kind'=>'ACCEPT_NA','change_reason'=>'Bad mapping'],$ex));
        $this->refuse(fn()=>$this->record(null,['kind'=>'ACCEPT_EXPLANATION']));
        $this->record(null,['kind'=>'REJECT_NA']);self::assertSame('REJECT_NA',$this->events()[0]->kind);
        $reviewNote=$this->addResponse('REVIEW_NOTE',$this->c);$this->decide($this->c,1);
        $this->refuse(fn()=>$this->record($this->o,['kind'=>'REQUEST_EVIDENCE'],$reviewNote));
    }
    public function test_rationale_citation_and_na_excerpt_are_mandatory(): void
    {
        foreach(['rationale','spec_number','spec_revision','spec_clause','source_quote'] as $key){$this->refuse(fn()=>$this->record(null,[$key=>' ']));}
        self::assertCount(0,$this->events());
        $this->record(null,['kind'=>'REQUEST_EVIDENCE','source_quote'=>null]);self::assertCount(1,$this->events());
    }
    public function test_only_current_reviewer_and_captured_policy_determine_authority(): void
    {
        $this->refuse(fn()=>$this->record($this->a));$this->refuse(fn()=>$this->record($this->o));
        $this->review()->configure($this->p,$this->o,['family'=>'PIPE','stages'=>['user:'.$this->o->id]]);
        $this->refuse(fn()=>$this->record($this->o));$this->record(null,['kind'=>'REQUEST_EVIDENCE']);$this->decide($this->c,1);
        $this->refuse(fn()=>$this->record($this->c,['change_reason'=>'No longer current']));
        $this->record($this->o,['change_reason'=>'Additional cited basis reviewed']);
        self::assertSame([1,2],array_column($this->events(),'review_stage'));self::assertSame([1,2],array_column($this->events(),'sequence'));
    }
    public function test_replacements_require_reason_preserve_history_and_refuse_stale_or_replayed_forms(): void
    {
        $first=$this->payload();$this->svc()->record($this->p,$this->c,$this->row->id,$this->note->id,$first);$original=(array)$this->events()[0];
        $this->refuse(fn()=>$this->svc()->record($this->p,$this->c,$this->row->id,$this->note->id,$first));
        $this->refuse(fn()=>$this->record(null,['kind'=>'REJECT_NA']));
        $stale=$this->payload(['kind'=>'REJECT_NA','change_reason'=>'Stale concurrent form']);
        $this->record(null,['kind'=>'REQUEST_EVIDENCE','change_reason'=>'Further source verification needed']);
        $this->refuse(fn()=>$this->svc()->record($this->p,$this->c,$this->row->id,$this->note->id,$stale));
        $events=$this->events();self::assertCount(2,$events);self::assertSame($original,(array)$events[0]);self::assertSame($original['id'],$events[1]->supersedes_id);
    }
    public function test_a_current_reviewer_cannot_dispose_their_own_earlier_author_response(): void
    {
        $qc=$this->userByEmail('sadeghi@pars.example');
        $this->review()->configure($this->p,$this->o,['family'=>'PIPE','stages'=>['user:'.$this->c->id,'user:'.$qc->id]]);
        $r=app(ProjectFittingDefinitionService::class)->create($this->p,$this->a,$this->data(['spec_number'=>'SELF-RESPONSE-QA']));
        $this->review()->submit($this->p,$this->a,$r->id);$n=$this->addResponse('EXPLANATION',$qc,$r);$this->decide($this->c,1,'APPROVE',$r);
        $this->refuse(fn()=>$this->record($qc,[],$n,$r));self::assertCount(0,$this->events($n));
        self::assertTrue($this->review()->dashboard($this->p,$qc,[$r],'PIPE')['reviewStates'][$r->id]['can_decide']);
        $forged=$this->payload([],$n)+['id'=>(string)Str::uuid(),'project_id'=>$this->p->id,'company_id'=>$qc->company_id,
            'created_by'=>$qc->id,'created_at'=>now(),'actor_name'=>$qc->name,'review_stage'=>2,'effect'=>'RECORD_ONLY'];
        try{$this->bound(fn()=>DB::transaction(fn()=>DB::table('material_finding_dispositions')->insert($forged)),$qc);self::fail('Self-disposition SQL accepted');}
        catch(QueryException $e){self::assertSame('42501',$e->getCode());}
    }
    public function test_record_only_never_blocks_final_approval_even_when_more_evidence_is_requested(): void
    {
        $this->record(null,['kind'=>'REQUEST_EVIDENCE']);$this->decide($this->c,1);$this->decide($this->o,2);
        self::assertSame('APPROVED',$this->state()['state']);self::assertSame('REQUEST_EVIDENCE',$this->events()[0]->kind);
        self::assertFalse($this->state()['screeningSnapshot']['result']['engineering_approved']);
        $this->refuse(fn()=>$this->record($this->o,['change_reason'=>'Attempt after closure']));
    }
    public function test_new_responses_and_corrected_versions_do_not_inherit_dispositions(): void
    {
        $this->record();$other=$this->addResponse();self::assertCount(0,$this->events($other));
        $this->decide($this->c,1,'RETURN');$this->refuse(fn()=>$this->record(null,['change_reason'=>'Returned run']));
        $new=$this->review()->correct($this->p,$this->a,$this->row->id,$this->data(['source_text'=>'Corrected source','correction_reason'=>'New source evidence']));
        $this->review()->submit($this->p,$this->a,$new->id);$n=$this->addResponse('NA_REQUEST',null,$new);
        self::assertCount(0,$this->events($n));self::assertCount(1,$this->events());
        $this->refuse(fn()=>$this->record(null,['change_reason'=>'Superseded source']));
    }
    public function test_current_role_and_discipline_are_rechecked(): void
    {
        $this->bound(fn()=>DB::table('project_memberships')->where('project_id',$this->p->id)->where('user_id',$this->c->id)->update(['role_codes'=>'[]']),$this->o);
        $this->refuse(fn()=>$this->record());
        $this->bound(fn()=>DB::table('project_memberships')->where('project_id',$this->p->id)->where('user_id',$this->c->id)->update(['role_codes'=>'["consultant"]','discipline_scope'=>'["CIVIL"]']),$this->o);
        $this->refuse(fn()=>$this->record());self::assertCount(0,$this->events());
        $this->bound(fn()=>DB::table('project_memberships')->where('project_id',$this->p->id)->where('user_id',$this->c->id)->update(['discipline_scope'=>null]),$this->o);
        app(TenantContext::class)->forIdentityLookup(fn()=>User::whereKey($this->c->id)->update(['is_active'=>false]));
        $this->refuse(fn()=>$this->record());
    }
    public function test_append_only_tenant_isolation_and_archive_read_only(): void
    {
        $this->record();$this->bound(function(){
            self::assertSame(0,DB::table('material_finding_dispositions')->update(['rationale'=>'Overwrite']));
            self::assertSame(0,DB::table('material_finding_dispositions')->delete());
        },$this->c);
        $other=Project::where('code','KISH-1')->firstOrFail();$outsider=$this->userByEmail('hosseini@arvand.example');
        $this->bound(fn()=>self::assertSame(0,DB::table('material_finding_dispositions')->count()),$this->o,$other);
        $this->bound(fn()=>self::assertSame(0,DB::table('material_finding_dispositions')->count()),$outsider);
        $this->refuse(fn()=>$this->record($outsider));
        DB::table('projects')->where('id',$this->p->id)->update(['state'=>'ARCHIVED','archived_at'=>now(),'archived_by'=>$this->o->id]);
        $this->refuse(fn()=>$this->record(null,['change_reason'=>'Archived']));
        $this->actingAs($this->c)->get('/projects/PH-14/piping/project-fittings?family=PIPE&lang=en')->assertOk()
            ->assertSee('data-current-disposition="ACCEPT_NA"',false)->assertDontSee('class="spec-grid finding-disposition-form"',false);
    }
    public function test_identity_binding_and_server_owned_attribution_effect_and_sequence(): void
    {
        foreach([['response_id'=>(string)Str::uuid()],['run_id'=>(string)Str::uuid()],['finding_key'=>str_repeat('0',64)]] as $change){$this->refuse(fn()=>$this->record(null,$change));}
        $this->record(null,['created_by'=>$this->a->id,'company_id'=>$this->a->company_id,'review_stage'=>5,'sequence'=>999,'effect'=>'BLOCK_FINAL','actor_name'=>'Fake']);
        $d=$this->events()[0];self::assertSame($this->c->id,$d->created_by);self::assertSame($this->c->company_id,$d->company_id);
        self::assertSame('RECORD_ONLY',$d->effect);self::assertSame(1,$d->sequence);self::assertSame(1,$d->review_stage);
    }
    public function test_bilingual_http_scoped_form_and_escaped_evidence(): void
    {
        $base='/projects/PH-14/piping/project-fittings';$url=$base.'/'.$this->row->id.'/finding-responses/'.$this->note->id.'/dispositions?family=PIPE&lang=en';
        $bad=$this->payload(['spec_number'=>['not text']]);
        $this->actingAs($this->c)->from($base.'?family=PIPE&lang=en')->post($url,['finding_disposition'=>$bad])->assertSessionHasErrors('spec_number');
        $this->get($base.'?family=PIPE&lang=en')->assertOk();
        $this->post($url,['finding_disposition'=>$this->payload(['rationale'=>'<script>test()</script>','source_quote'=>'<b>Quoted evidence</b>'])])
            ->assertRedirect($base.'?lang=en&family=PIPE');
        foreach(['fa','en'] as $lang){$this->get($base.'?family=PIPE&lang='.$lang)->assertOk()->assertSee('data-current-disposition="ACCEPT_NA"',false)
            ->assertSee('&lt;script&gt;test()',false)->assertDontSee('<script>test()',false)->assertSee('finding_disposition[change_reason]',false);}
    }
    public function test_sql_guard_rejects_wrong_reviewer_stage_finding_effect_and_missing_na_quote(): void
    {
        foreach(['author','future','stage','key','effect','quote','type'] as $case){
            $u=$case==='author'?$this->a:($case==='future'?$this->o:$this->c);
            $d=$this->payload()+['id'=>(string)Str::uuid(),'project_id'=>$this->p->id,'company_id'=>$u->company_id,'created_by'=>$u->id,
                'created_at'=>now(),'actor_name'=>$u->name,'review_stage'=>1,'effect'=>'RECORD_ONLY'];$code='42501';
            if($case==='stage'){$d['review_stage']=2;}if($case==='key'){$d['finding_key']=str_repeat('0',64);}
            if($case==='effect'){$d['effect']='BLOCK_FINAL';$code='23514';}if($case==='quote'){$d['source_quote']=' ';$code='23514';}
            if($case==='type'){$d['kind']='ACCEPT_EXPLANATION';}
            try{$this->bound(fn()=>DB::transaction(fn()=>DB::table('material_finding_dispositions')->insert($d)),$u);self::fail('Forged disposition accepted: '.$case);}
            catch(QueryException $e){self::assertSame($code,$e->getCode(),$case);}
        }
        self::assertCount(0,$this->events());
    }
    public function test_sql_rejects_stale_predecessor_and_missing_replacement_reason(): void
    {
        $this->record();$original=(array)$this->events()[0];
        foreach(['stale','reason'] as $case){
            $d=$this->payload(['supersedes_id'=>$case==='stale'?null:$original['id'],'change_reason'=>null])+[
                'id'=>(string)Str::uuid(),'project_id'=>$this->p->id,'company_id'=>$this->c->company_id,'created_by'=>$this->c->id,
                'created_at'=>now(),'actor_name'=>$this->c->name,'review_stage'=>1,'effect'=>'RECORD_ONLY'];
            try{$this->bound(fn()=>DB::transaction(fn()=>DB::table('material_finding_dispositions')->insert($d)),$this->c);self::fail('Invalid replacement accepted');}
            catch(QueryException $e){self::assertSame('23514',$e->getCode());}
        }
        self::assertSame($original,(array)$this->events()[0]);self::assertCount(1,$this->events());
    }
}
