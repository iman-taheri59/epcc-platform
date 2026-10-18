<?php

declare(strict_types=1);
namespace Tests\Feature;
use App\Models\Project;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
final class MaterialMigrationTest extends TestCase
{
    public function test_populated_forward_migration_preserves_fitting_history_and_authority(): void
    {
        $paths=array_values(array_filter(glob(database_path('migrations/*.php')),fn($p)=>basename($p)<'2026_09_29_150000'));
        $this->artisan('migrate:fresh',['--force'=>true,'--path'=>$paths,'--realpath'=>true])->assertExitCode(0);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);$this->seed(\Database\Seeders\AuthSeeder::class);
        $p=Project::where('code','PH-14')->firstOrFail();$a=$this->userByEmail('rezaei@pars.example');$o=$this->userByEmail('karimi@nioc.example');
        $tenant=app(TenantContext::class);$id=(string)Str::uuid();$policy=(string)Str::uuid();$run=(string)Str::uuid();
        $identity=fn($u,$id)=>['id'=>$id,'project_id'=>$p->id,'company_id'=>$u->company_id,'created_by'=>$u->id,'created_at'=>now()];
        $tenant->runAs($a->company_id,fn()=>DB::table('project_fitting_definitions')->insert($identity($a,$id)+[
            'component_type'=>'CAP','qualifier'=>'','nps'=>52,'od_mm'=>1320.8,'dimension'=>'LENGTH','dimension_mm'=>300,
            'spec_number'=>'SYNTHETIC','spec_revision'=>'A','spec_clause'=>'1','source_text'=>'Original immutable source',
        ]),$p->id);
        $tenant->runAs($o->company_id,fn()=>DB::table('fitting_review_policies')->insert($identity($o,$policy)+[
            'version'=>1,'steps'=>json_encode([['selector'=>'user:'.$o->id,'label'=>'Historical snapshot']]),
        ]),$p->id);
        $tenant->runAs($a->company_id,fn()=>DB::table('fitting_review_runs')->insert($identity($a,$run)+['definition_id'=>$id,'policy_id'=>$policy]),$p->id);
        $tenant->runAs($o->company_id,fn()=>DB::table('fitting_review_decisions')->insert($identity($o,(string)Str::uuid())+[
            'run_id'=>$run,'stage'=>1,'decision'=>'APPROVE','comment'=>'Historical synthetic decision','actor_name'=>$o->name,
        ]),$p->id);
        $snapshot=fn()=>$tenant->runAs($o->company_id,function(){
            $out=[];foreach (['project_fitting_definitions','fitting_review_policies','fitting_review_runs','fitting_review_decisions'] as $t) {$out[$t]=(array)DB::table($t)->first();}return $out;
        },$p->id);
        $before=$snapshot();$this->artisan('migrate',['--force'=>true])->assertExitCode(0);$after=$snapshot();
        foreach ($before as $table=>$values) {self::assertEquals($values,array_intersect_key($after[$table],$values));}
        self::assertSame('FITTING',$after['fitting_review_policies']['material_family']);
        foreach (['PIPE','FLANGE','VALVE','GASKET','BOLTING'] as $family) {
            self::assertNull(app(\App\Services\FittingReviewService::class)->dashboard($p,$o,[],$family)['reviewPolicy']);
        }
    }
}
