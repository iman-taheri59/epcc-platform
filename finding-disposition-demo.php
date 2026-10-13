<?php
require '/home/user/epcc-platform/app/vendor/autoload.php';$app=require '/home/user/epcc-platform/app/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(getenv('EPCC_ALLOW_DEMO_WRITES')!=='yes')throw new RuntimeException('Demo flag required');
use App\Models\Project;use App\Models\User;use App\Services\FittingReviewService;use App\Services\ProjectFittingDefinitionService;use App\Support\TenantContext;
$p=Project::where('code','PH-14')->firstOrFail();$users=app(TenantContext::class)->forIdentityLookup(fn()=>User::whereIn('email',['rezaei@pars.example','karimi@nioc.example','tehrani@pmc.example'])->get()->keyBy('email'));
$a=$users['rezaei@pars.example'];$o=$users['karimi@nioc.example'];$c=$users['tehrani@pmc.example'];
$r=app(ProjectFittingDefinitionService::class)->create($p,$a,['component_type'=>'PIPE','qualifier'=>'SEAMLESS','size_designation'=>'NPS 6 in','reference_standard'=>'ASME B36.10M-2022','material_spec'=>'SYNTHETIC QA','declaration_reason'=>'Synthetic browser fixture only','spec_number'=>'FORMAL-DEMO','spec_revision'=>'A','spec_clause'=>'1','source_text'=>'Synthetic source, not engineering authorization','properties'=>['wall_thickness_mm'=>'10']]);
$s=app(FittingReviewService::class);$s->configure($p,$o,['family'=>'PIPE','stages'=>['user:'.$c->id,'user:'.$o->id]]);$s->submit($p,$a,$r->id);file_put_contents('/home/user/organization-report/finding-disposition-demo.json',json_encode(['definition'=>$r->id]));
