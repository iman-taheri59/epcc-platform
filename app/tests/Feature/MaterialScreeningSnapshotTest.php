<?php

declare(strict_types=1);
namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Services\FittingReviewService;
use App\Services\ProjectFittingDefinitionService;
use App\Services\Engineering\MaterialScreeningEvidence;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MaterialScreeningSnapshotTest extends TestCase
{
    private Project $project;
    private User $author;
    private User $owner;
    private ProjectFittingDefinition $row;
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh',['--force'=>true]);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);$this->seed(\Database\Seeders\AuthSeeder::class);
        $this->project=Project::where('code','PH-14')->firstOrFail();
        $this->author=$this->userByEmail('rezaei@pars.example');$this->owner=$this->userByEmail('karimi@nioc.example');
        $this->row=app(ProjectFittingDefinitionService::class)->create($this->project,$this->author,$this->data());
        $this->svc()->configure($this->project,$this->owner,['family'=>'PIPE','stages'=>['user:'.$this->owner->id]]);
    }
    private function data(array $change=[]): array
    {
        return array_replace(['component_type'=>'PIPE','qualifier'=>'SEAMLESS','size_designation'=>'NPS 6 in',
            'reference_standard'=>'ASME B36.10M-2022','material_spec'=>'SYNTHETIC QA','material_grade'=>'QA',
            'declaration_reason'=>'Test evidence only','spec_number'=>'SNAPSHOT-QA','spec_revision'=>'A','spec_clause'=>'1',
            'source_text'=>'Original <script>qa()</script> wording.',
            'properties'=>['schedule'=>'40','product_form'=>'SEAMLESS','outside_diameter_mm'=>'168.3','wall_thickness_mm'=>'7.11']],$change);
    }
    private function svc(): FittingReviewService {return app(FittingReviewService::class);}
    private function bound(callable $fn,?User $user=null,?Project $project=null): mixed
    {
        return app(TenantContext::class)->runAs(($user??$this->author)->company_id,$fn,($project??$this->project)->id);
    }
    private function submit(?ProjectFittingDefinition $row=null): void
    {
        $this->svc()->submit($this->project,$this->author,($row??$this->row)->id);
    }
    private function state(?ProjectFittingDefinition $row=null): array
    {
        $r=$row??$this->row;
        return $this->svc()->dashboard($this->project,$this->owner,[$r],'PIPE')['reviewStates'][$r->id];
    }
    private function snapshot(?ProjectFittingDefinition $row=null): object
    {
        return $this->bound(fn()=>DB::table('material_screening_snapshots')->where('definition_id',($row??$this->row)->id)->first());
    }
    private function rawRun(): array
    {
        return ['id'=>(string)Str::uuid(),'project_id'=>$this->project->id,'company_id'=>$this->author->company_id,
            'created_by'=>$this->author->id,'created_at'=>now(),'definition_id'=>$this->row->id,
            'policy_id'=>$this->bound(fn()=>DB::table('fitting_review_policies')->where('material_family','PIPE')->value('id'))];
    }
    private function payload(array $run): array
    {
        $row=$this->bound(fn()=>$this->row->fresh());$evidence=app(MaterialScreeningEvidence::class);
        $input=json_encode($evidence->input($row),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
        $result=json_encode($evidence->current($row),JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
        return ['run_id'=>$run['id'],'project_id'=>$this->project->id,'company_id'=>$this->author->company_id,
            'definition_id'=>$this->row->id,'created_by'=>$this->author->id,'created_at'=>$run['created_at'],
            'input_json'=>$input,'result_json'=>$result,'result_sha256'=>hash('sha256',$result),
            'implementation_sha256'=>hash_file('sha256',app_path('Services/Engineering/MaterialScreening.php'))];
    }
    public function test_submission_captures_exact_source_result_and_provenance_without_approval(): void
    {
        $before=$this->bound(fn()=>$this->row->fresh()->getAttributes());$this->submit();$r=$this->snapshot();
        $result=json_decode($r->result_json,true);$input=json_decode($r->input_json,true);
        self::assertSame($this->row->id,$r->definition_id);self::assertSame($this->author->id,$r->created_by);
        self::assertSame('A',$input['spec_revision']);self::assertSame($this->row->source_text,$input['source_text']);
        self::assertSame(hash('sha256',$r->input_json),$result['input_sha256']);
        self::assertSame(hash('sha256',$r->result_json),$r->result_sha256);
        self::assertCount(2,$result['references']);self::assertFalse($result['engineering_approved']);
        self::assertFalse($result['automatic_consumption_allowed']);self::assertSame('IN_REVIEW',$this->state()['state']);
        self::assertSame($r->run_id,$this->state()['run']->screening_run_id);
        self::assertSame($before,$this->bound(fn()=>$this->row->fresh()->getAttributes()));
    }
    public function test_bilingual_http_uses_stored_evidence_not_forged_submission_payload(): void
    {
        $base='/projects/PH-14/piping/project-fittings';
        $this->actingAs($this->author)->post($base.'/'.$this->row->id.'/submit?family=PIPE',[
            'lang'=>'en','result_json'=>'{"status":"APPROVED"}','input_json'=>'forged','screening_run_id'=>null,
        ])->assertRedirect($base.'?lang=en&family=PIPE');
        foreach (['fa','en'] as $lang) {
            $this->get($base.'?family=PIPE&lang='.$lang)->assertOk()->assertSee('data-screening-snapshot=',false)
                ->assertSee('data-screening=',false)->assertSee('&lt;script&gt;qa()',false)->assertDontSee('<script>qa()',false)
                ->assertDontSee('data-screening-snapshot-missing',false);
        }
        self::assertStringNotContainsString('forged',$this->snapshot()->input_json);
        self::assertFalse(json_decode($this->snapshot()->result_json,true)['engineering_approved']);
    }
    public function test_changed_reference_changes_live_view_not_captured_evidence(): void
    {
        $this->submit();$before=(array)$this->snapshot();
        $dir=sys_get_temp_dir().'/screening-ref-'.Str::uuid();mkdir($dir);
        try {
            foreach (['pipe-sizes','pipe-schedules'] as $id) {
                $pack=json_decode(file_get_contents(base_path('../standards-packs/reference/'.$id.'.ref.json')),true);
                if ($id==='pipe-sizes') {foreach ($pack['sizes'] as &$size) {if ($size['nps']==='6') {$size['odMm']=200;}}unset($size);}
                file_put_contents($dir.'/'.$id.'.ref.json',json_encode($pack));
            }
            app()->instance(MaterialScreeningEvidence::class,new MaterialScreeningEvidence($dir));
            $response=$this->actingAs($this->owner)->get('/projects/PH-14/piping/project-fittings?family=PIPE&lang=en');
            $response->assertOk()->assertSee('REFERENCE_VALUE_DIFFERENCE');
            $html=$response->getContent();$start=strpos($html,'data-screening-snapshot=');
            self::assertNotFalse($start);self::assertStringNotContainsString('REFERENCE_VALUE_DIFFERENCE',substr($html,$start));
            self::assertSame($before,(array)$this->snapshot());
        } finally {foreach (glob($dir.'/*') as $file) {unlink($file);}rmdir($dir);}
    }
    public function test_correction_captures_new_version_and_keeps_previous_evidence(): void
    {
        $this->submit();$old=(array)$this->snapshot();
        $this->svc()->decide($this->project,$this->owner,$this->row->id,['stage'=>1,'decision'=>'RETURN','comment'=>'Synthetic correction request']);
        $new=$this->svc()->correct($this->project,$this->author,$this->row->id,$this->data([
            'properties'=>['schedule'=>'40','product_form'=>'SEAMLESS','wall_thickness_mm'=>'7.12'], 'correction_reason'=>'Fix transcription',
        ]));
        self::assertNull($this->state($new)['screeningSnapshot']);$this->submit($new);
        $replacement=$this->snapshot($new);self::assertNotSame($old['run_id'],$replacement->run_id);
        self::assertNotSame($old['input_json'],$replacement->input_json);
        self::assertSame('A',json_decode($replacement->input_json,true)['spec_revision']);
        self::assertSame($old,(array)$this->snapshot());
        self::assertSame('SUPERSEDED',$this->state()['state']);
    }
    public function test_snapshots_are_immutable_and_tenant_project_scoped(): void
    {
        $this->submit();
        $this->bound(function(){
            self::assertSame(0,DB::table('material_screening_snapshots')->update(['created_at'=>now()]));
            self::assertSame(0,DB::table('material_screening_snapshots')->delete());
            self::assertSame(1,DB::table('material_screening_snapshots')->count());
        });
        $other=Project::where('code','KISH-1')->firstOrFail();
        $this->bound(fn()=>self::assertSame(0,DB::table('material_screening_snapshots')->count()),$this->owner,$other);
        $outside=$this->userByEmail('hosseini@arvand.example');
        $this->bound(fn()=>self::assertSame(0,DB::table('material_screening_snapshots')->count()),$outside);
        $this->actingAs($outside)->get('/projects/PH-14/piping/project-fittings?family=PIPE')->assertNotFound();
    }
    public function test_duplicate_submission_does_not_duplicate_or_replace_evidence(): void
    {
        $this->submit();$before=(array)$this->snapshot();
        try {$this->submit();self::fail('Duplicate allowed');} catch (ValidationException) {}
        self::assertSame($before,(array)$this->snapshot());
        self::assertSame(1,$this->bound(fn()=>DB::table('material_screening_snapshots')->count()));
    }
    public function test_failed_capture_rolls_back_submission(): void
    {
        DB::unprepared("CREATE OR REPLACE FUNCTION qa_fail_capture() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'QA capture failure' USING ERRCODE='23514'; END; $$;
            CREATE TRIGGER qa_fail_capture BEFORE INSERT ON material_screening_snapshots FOR EACH ROW EXECUTE FUNCTION qa_fail_capture()");
        try {$this->submit();self::fail('Failed capture committed');}catch(QueryException $e){self::assertSame('23514',$e->getCode());}
        self::assertSame(0,$this->bound(fn()=>DB::table('fitting_review_runs')->count()));
        self::assertSame(0,$this->bound(fn()=>DB::table('material_screening_snapshots')->count()));
        self::assertSame('UNREVIEWED',$this->state()['state']);
    }
    public function test_raw_run_cannot_disable_or_omit_required_capture(): void
    {
        $run=$this->rawRun()+['screening_run_id'=>null];
        try {
            $this->bound(fn()=>DB::transaction(fn()=>DB::table('fitting_review_runs')->insert($run)));
            self::fail('Uncaptured new run committed');
        } catch(\PDOException $e) {self::assertSame('23503',$e->getCode());}
        self::assertSame(0,$this->bound(fn()=>DB::table('fitting_review_runs')->count()));
    }
    public function test_sql_rejects_forged_source_hash_approval_and_creator(): void
    {
        foreach (['hash','source','approval','creator'] as $case) {
            $run=$this->rawRun();$payload=$this->payload($run);$expected='23514';
            if ($case==='hash') {$payload['result_sha256']=str_repeat('0',64);}
            if ($case==='source') {
                $input=json_decode($payload['input_json'],true);$input['spec_revision']='INVENTED';
                $payload['input_json']=json_encode($input,JSON_THROW_ON_ERROR|JSON_PRESERVE_ZERO_FRACTION);
                $r=json_decode($payload['result_json'],true);$r['input_sha256']=hash('sha256',$payload['input_json']);
                $payload['result_json']=json_encode($r);$payload['result_sha256']=hash('sha256',$payload['result_json']);$expected='42501';
            }
            if ($case==='approval') {
                $r=json_decode($payload['result_json'],true);$r['engineering_approved']=true;
                $payload['result_json']=json_encode($r);$payload['result_sha256']=hash('sha256',$payload['result_json']);
            }
            if ($case==='creator') {$payload['created_by']=$this->userByEmail('sadeghi@pars.example')->id;$expected='23503';}
            try {
                $this->bound(fn()=>DB::transaction(function()use($run,$payload){
                    DB::table('fitting_review_runs')->insert($run);DB::table('material_screening_snapshots')->insert($payload);
                }));self::fail('Forged snapshot accepted: '.$case);
            }catch(QueryException $e){self::assertSame($expected,$e->getCode(),$case);}
        }
        self::assertSame(0,$this->bound(fn()=>DB::table('fitting_review_runs')->count()));
    }
    public function test_empty_technical_properties_all_families_are_captured_without_becoming_approval_gates(): void
    {
        foreach (['PIPE','FLANGE','VALVE','GASKET','BOLTING'] as $family) {
            $row=app(ProjectFittingDefinitionService::class)->create($this->project,$this->author,$this->data([
                'component_type'=>$family,'spec_number'=>'EMPTY-'.$family,'properties'=>[],
            ]));
            $this->svc()->configure($this->project,$this->owner,['family'=>$family,'stages'=>['user:'.$this->owner->id]]);
            $this->submit($row);$s=$this->snapshot($row);
            self::assertSame([] ,json_decode($s->input_json,true)['properties']);
            self::assertSame('INCOMPLETE',json_decode($s->result_json,true)['status']);
            $this->svc()->decide($this->project,$this->owner,$row->id,['stage'=>1,'decision'=>'APPROVE','comment'=>'Independent synthetic review']);
            self::assertSame('APPROVED',$this->state($row)['state']);
            self::assertFalse(json_decode($this->snapshot($row)->result_json,true)['engineering_approved']);
        }
    }
}
