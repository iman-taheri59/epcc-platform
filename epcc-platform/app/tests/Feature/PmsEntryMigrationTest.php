<?php

declare(strict_types=1);
namespace Tests\Feature;
use App\Models\{Project,PipingClass,PipingClassItem};
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
final class PmsEntryMigrationTest extends TestCase
{
    public function test_upgrade_preserves_populated_pms_and_does_not_invent_links_or_history():void
    {
        $paths=array_values(array_filter(glob(database_path('migrations/*.php')),fn($p)=>basename($p)<'2026_09_29_235000'));
        $this->artisan('migrate:fresh',['--force'=>true,'--path'=>$paths,'--realpath'=>true])->assertExitCode(0);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);$this->seed(\Database\Seeders\AuthSeeder::class);
        $p=Project::where('code','PH-14')->firstOrFail();$a=$this->userByEmail('rezaei@pars.example');
        $bound=fn($fn)=>app(TenantContext::class)->runAs($a->company_id,$fn,$p->id);
        $c=$bound(fn()=>PipingClass::create(['project_id'=>$p->id,'company_id'=>$a->company_id,'code'=>'OLD-PMS','material_as_written'=>'Source material']));
        $i=$bound(fn()=>PipingClassItem::create(['project_id'=>$p->id,'company_id'=>$a->company_id,'piping_class_id'=>$c->id,'component_type'=>'PIPE','dn'=>150,'nps'=>'6','description_as_written'=>'Historic source','schedule'=>'40']));
        $before=$bound(fn()=>[$c->fresh()->toArray(),$i->fresh()->toArray()]);
        $this->artisan('migrate',['--force'=>true])->assertExitCode(0);
        $after=$bound(fn()=>[$c->fresh()->toArray(),$i->fresh()->toArray()]);
        foreach($before as $n=>$row)foreach($row as $k=>$v)self::assertSame($v,$after[$n][$k]);
        self::assertSame(0,$after[0]['entry_version']);self::assertSame(0,$after[1]['entry_version']);self::assertNull($after[1]['project_definition_id']);
        self::assertSame(0,$bound(fn()=>DB::table('pms_entry_events')->count()));
        $this->actingAs($a)->get('/projects/PH-14/piping/pms/'.$c->id.'?lang=en')->assertOk()->assertSee('Historic source');
    }
}
