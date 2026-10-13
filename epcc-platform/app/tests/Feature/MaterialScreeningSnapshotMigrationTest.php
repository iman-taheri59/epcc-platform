<?php

declare(strict_types=1);
namespace Tests\Feature;
use App\Models\Project;
use App\Services\FittingReviewService;
use App\Services\ProjectFittingDefinitionService;
use App\Services\Engineering\MaterialScreeningEvidence;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaterialScreeningSnapshotMigrationTest extends TestCase
{
    public function test_legacy_review_is_unchanged_and_cannot_be_retroactively_given_todays_evidence(): void
    {
        $paths=array_values(array_filter(glob(database_path('migrations/*.php')),fn($p)=>basename($p)<'2026_09_29_180000'));
        $this->artisan('migrate:fresh',['--force'=>true,'--path'=>$paths,'--realpath'=>true])->assertExitCode(0);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);$this->seed(\Database\Seeders\AuthSeeder::class);
        $p=Project::where('code','PH-14')->firstOrFail();$a=$this->userByEmail('rezaei@pars.example');$o=$this->userByEmail('karimi@nioc.example');
        $tenant=app(TenantContext::class);$reviews=app(FittingReviewService::class);
        $row=app(ProjectFittingDefinitionService::class)->create($p,$a,[
            'component_type'=>'PIPE','qualifier'=>'SEAMLESS','size_designation'=>'NPS 6 in','reference_standard'=>'PROJECT QA',
            'material_spec'=>'SYNTHETIC ONLY','declaration_reason'=>'Migration fixture','spec_number'=>'LEGACY-SCREEN',
            'spec_revision'=>'A','spec_clause'=>'1','source_text'=>'Original legacy source','properties'=>[],
        ]);
        $reviews->configure($p,$o,['family'=>'PIPE','stages'=>['user:'.$o->id]]);
        $run=['id'=>(string)Str::uuid(),'project_id'=>$p->id,'company_id'=>$a->company_id,'created_by'=>$a->id,'created_at'=>now(),
            'definition_id'=>$row->id,'policy_id'=>$tenant->runAs($a->company_id,fn()=>DB::table('fitting_review_policies')->value('id'),$p->id)];
        $tenant->runAs($a->company_id,fn()=>DB::table('fitting_review_runs')->insert($run),$p->id);
        $tenant->runAs($o->company_id,fn()=>DB::table('fitting_review_decisions')->insert([
            'id'=>(string)Str::uuid(),'project_id'=>$p->id,'company_id'=>$o->company_id,'created_by'=>$o->id,'created_at'=>now(),
            'run_id'=>$run['id'],'stage'=>1,'decision'=>'APPROVE','comment'=>'Legacy synthetic approval','actor_name'=>$o->name,
        ]),$p->id);
        $snapshot=fn()=>$tenant->runAs($a->company_id,function(){
            $out=[];foreach(['project_fitting_definitions','fitting_review_policies','fitting_review_runs','fitting_review_decisions'] as $t){$out[$t]=(array)DB::table($t)->first();}return $out;
        },$p->id);
        $before=$snapshot();$this->artisan('migrate',['--force'=>true])->assertExitCode(0);$after=$snapshot();
        foreach($before as $table=>$values){self::assertEquals($values,array_intersect_key($after[$table],$values));}
        self::assertNull($after['fitting_review_runs']['screening_run_id']);
        $state=$reviews->dashboard($p,$o,[$row],'PIPE')['reviewStates'][$row->id];
        self::assertSame('APPROVED',$state['state']);self::assertNull($state['screeningSnapshot']);
        $this->actingAs($o)->get('/projects/PH-14/piping/project-fittings?family=PIPE&lang=en')
            ->assertOk()->assertSee('data-screening-snapshot-missing',false)->assertDontSee('data-screening-snapshot=',false);
        try {
            $tenant->runAs($a->company_id,fn()=>DB::transaction(fn()=>app(MaterialScreeningEvidence::class)->capture($row->fresh(),$run)),$p->id);
            self::fail('Legacy evidence fabricated');
        }catch(\Illuminate\Database\QueryException $e){self::assertSame('42501',$e->getCode());}
        $tenant->runAs($a->company_id,fn()=>self::assertSame(0,DB::table('material_screening_snapshots')->count()),$p->id);
    }
}
