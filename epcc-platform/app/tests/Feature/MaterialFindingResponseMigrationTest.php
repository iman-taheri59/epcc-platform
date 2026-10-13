<?php

declare(strict_types=1);
namespace Tests\Feature;
use App\Models\Project;
use App\Services\FittingReviewService;
use App\Services\ProjectFittingDefinitionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MaterialFindingResponseMigrationTest extends TestCase
{
    public function test_forward_migration_preserves_approved_sources_snapshots_and_decisions_without_fabricating_notes(): void
    {
        $paths=array_values(array_filter(glob(database_path('migrations/*.php')),fn($p)=>basename($p)<'2026_09_29_210000'));
        $this->artisan('migrate:fresh',['--force'=>true,'--path'=>$paths,'--realpath'=>true])->assertExitCode(0);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);$this->seed(\Database\Seeders\AuthSeeder::class);
        $p=Project::where('code','PH-14')->firstOrFail();$a=$this->userByEmail('rezaei@pars.example');$o=$this->userByEmail('karimi@nioc.example');
        $row=app(ProjectFittingDefinitionService::class)->create($p,$a,[
            'component_type'=>'PIPE','qualifier'=>'SEAMLESS','size_designation'=>'NPS 6 in','reference_standard'=>'PROJECT QA',
            'material_spec'=>'SYNTHETIC ONLY','declaration_reason'=>'Migration fixture','spec_number'=>'OLD-REVIEW',
            'spec_revision'=>'A','spec_clause'=>'1','source_text'=>'Preserve all history','properties'=>[],
        ]);
        $svc=app(FittingReviewService::class);$svc->configure($p,$o,['family'=>'PIPE','stages'=>['user:'.$o->id]]);
        $svc->submit($p,$a,$row->id);$svc->decide($p,$o,$row->id,['stage'=>1,'decision'=>'APPROVE','comment'=>'Synthetic historical approval']);
        $capture=fn()=>app(TenantContext::class)->runAs($a->company_id,function(){
            $out=[];foreach(['project_fitting_definitions','fitting_review_policies','fitting_review_runs','fitting_review_decisions','material_screening_snapshots'] as $t){$out[$t]=(array)DB::table($t)->first();}return $out;
        },$p->id);
        $before=$capture();$this->artisan('migrate',['--force'=>true])->assertExitCode(0);self::assertSame($before,$capture());
        app(TenantContext::class)->runAs($a->company_id,fn()=>self::assertSame(0,DB::table('material_finding_responses')->count()),$p->id);
        self::assertSame('APPROVED',$svc->dashboard($p,$o,[$row],'PIPE')['reviewStates'][$row->id]['state']);
        $this->actingAs($a)->get('/projects/PH-14/piping/project-fittings?family=PIPE&lang=en')->assertOk()
            ->assertSee('data-screening-snapshot=',false)->assertDontSee('class="spec-grid finding-response-form"',false);
    }
}
