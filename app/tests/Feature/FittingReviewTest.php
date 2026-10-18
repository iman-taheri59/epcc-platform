<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Services\FittingReviewService;
use App\Services\ProjectFittingDefinitionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class FittingReviewTest extends TestCase
{
    private Project $project;
    private User $author;
    private User $owner;
    private User $consultant;
    private ProjectFittingDefinition $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh',['--force'=>true]);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);
        $this->seed(\Database\Seeders\AuthSeeder::class);
        $this->project=Project::where('code','PH-14')->firstOrFail();
        $this->author=$this->userByEmail('rezaei@pars.example');
        $this->owner=$this->userByEmail('karimi@nioc.example');
        $this->consultant=$this->userByEmail('tehrani@pmc.example');
        $this->row=app(ProjectFittingDefinitionService::class)->create($this->project,$this->author,$this->data());
    }

    private function data(array $change=[]): array
    {
        return array_replace(['component_type'=>'ELBOW','qualifier'=>'90LR','nps'=>'52','dn'=>null,
            'od_mm'=>'1320.8','dimension'=>'CENTRE_TO_END','dimension_mm'=>'1981.2',
            'spec_number'=>'REVIEW-QA','spec_revision'=>'A','spec_clause'=>'QA-1','source_text'=>'Synthetic original wording.'], $change);
    }
    private function svc(): FittingReviewService { return app(FittingReviewService::class); }
    private function policy(?array $steps=null): void
    {
        $this->svc()->configure($this->project,$this->owner,['stages'=>$steps??[
            'role:'.$this->consultant->company_id.':consultant','user:'.$this->owner->id]]);
    }
    private function submit(): void { $this->svc()->submit($this->project,$this->author,$this->row->id); }
    private function decide(User $actor, int $stage, string $decision='APPROVE', ?string $id=null): void
    {
        $this->svc()->decide($this->project,$actor,$id??$this->row->id,
            ['stage'=>$stage,'decision'=>$decision,'comment'=>'Synthetic review decision']);
    }
    private function state(?ProjectFittingDefinition $row=null): array
    {
        $row??=$this->row;
        return $this->svc()->dashboard($this->project,$this->owner,[$row])['reviewStates'][$row->id];
    }
    private function refused(callable $fn): void
    {
        try { $fn(); self::fail('Operation should be refused'); }
        catch (ValidationException|HttpException $e) {
            if ($e instanceof HttpException) { self::assertContains($e->getStatusCode(),[403,404]); }
            else { self::assertNotEmpty($e->errors()); }
        }
    }
    private function bound(callable $fn, ?string $company=null, ?string $project=null): mixed
    {
        return app(TenantContext::class)->runAs($company??$this->owner->company_id,$fn,$project??$this->project->id);
    }

    public function test_no_configuration_blocks_submission_and_approval(): void
    {
        $this->refused(fn ()=>$this->submit());
        $this->refused(fn ()=>$this->decide($this->owner,1));
        self::assertSame('UNREVIEWED',$this->state()['state']);
    }
    public function test_configured_ordered_independent_review_reaches_approval_without_mutating_definition(): void
    {
        $before=$this->bound(fn ()=>$this->row->fresh()->getAttributes()); $this->policy(); $this->submit();
        self::assertSame('IN_REVIEW',$this->state()['state']);
        $this->refused(fn ()=>$this->decide($this->owner,2));
        $this->refused(fn ()=>$this->decide($this->owner,1));
        $this->decide($this->consultant,1);
        $this->refused(fn ()=>$this->decide($this->consultant,2));
        $this->decide($this->owner,2);
        self::assertSame('APPROVED',$this->state()['state']);
        self::assertCount(2,$this->state()['decisions']);
        self::assertEquals($before,$this->bound(fn ()=>$this->row->fresh()->getAttributes()));
        $this->refused(fn ()=>$this->decide($this->owner,2));
    }
    public function test_policy_change_does_not_rewrite_in_flight_snapshot(): void
    {
        $this->policy();$this->submit();
        $old=$this->state()['policy']->id;
        $this->policy(['user:'.$this->owner->id]);
        self::assertSame($old,$this->state()['policy']->id);
        self::assertCount(2,$this->state()['steps']);
        $this->decide($this->consultant,1);$this->decide($this->owner,2);
        self::assertSame('APPROVED',$this->state()['state']);
        self::assertSame(2,$this->svc()->dashboard($this->project,$this->owner,[])['reviewPolicy']->version);
    }
    public function test_authors_cannot_approve_and_impossible_independent_assignment_is_blocked(): void
    {
        $this->policy(['user:'.$this->author->id]);
        $this->refused(fn ()=>$this->submit());
        $this->policy(['role:'.$this->author->company_id.':project_manager']);
        $this->refused(fn ()=>$this->submit());
        $this->policy();$this->submit();
        $this->refused(fn ()=>$this->decide($this->author,1));
    }
    public function test_only_authorized_employer_can_configure_and_selectors_must_be_valid(): void
    {
        $this->refused(fn ()=>$this->svc()->configure($this->project,$this->author,['stages'=>['user:'.$this->owner->id]]));
        foreach ([[],['user:'.$this->owner->id,'user:'.$this->owner->id],['user:'.str_repeat('x',36)],
            ['role:'.$this->author->company_id.':invented']] as $steps) {
            $this->refused(fn ()=>$this->policy($steps));
        }
        $civil=$this->userByEmail('mousavi@sahand.example');
        $this->refused(fn ()=>$this->policy(['user:'.$civil->id]));
    }
    public function test_current_role_and_account_status_are_rechecked_on_signing(): void
    {
        $this->policy();$this->submit();
        $this->bound(fn ()=>DB::table('project_memberships')->where('project_id',$this->project->id)
            ->where('user_id',$this->consultant->id)->update(['role_codes'=>'[]']));
        $this->refused(fn ()=>$this->decide($this->consultant,1));
        self::assertCount(0,$this->state()['decisions']);
    }
    public function test_return_ends_cycle_and_correction_preserves_source_revision_and_decisions(): void
    {
        $this->policy();$this->submit();$this->decide($this->consultant,1,'RETURN');
        self::assertSame('CHANGES_REQUESTED',$this->state()['state']);
        $this->refused(fn ()=>$this->decide($this->owner,2));
        $new=$this->svc()->correct($this->project,$this->author,$this->row->id,
            $this->data(['dimension_mm'=>'2000','correction_reason'=>'Fix transcribed dimension']));
        self::assertSame($this->row->id,$new->revises_id);
        self::assertSame('A',$new->spec_revision);
        self::assertSame('UNREVIEWED',$this->state($new)['state']);
        self::assertSame('SUPERSEDED',$this->state()['state']);
        self::assertCount(1,$this->state()['decisions']);
        $this->refused(fn ()=>$this->svc()->correct($this->project,$this->author,$this->row->id,
            $this->data(['dimension_mm'=>'2100','correction_reason'=>'Stale edit'])));
        $this->svc()->submit($this->project,$this->author,$new->id);
        $this->decide($this->consultant,1,'APPROVE',$new->id);$this->decide($this->owner,2,'APPROVE',$new->id);
        self::assertSame('APPROVED',$this->state($new)['state']);
    }
    public function test_correcting_an_approved_record_does_not_transfer_approval(): void
    {
        $this->policy(['user:'.$this->owner->id]);$this->submit();$this->decide($this->owner,1);
        $new=$this->svc()->correct($this->project,$this->author,$this->row->id,
            $this->data(['dimension_mm'=>'2001','correction_reason'=>'New evidence']));
        self::assertSame('SUPERSEDED',$this->state()['state']);
        self::assertSame('UNREVIEWED',$this->state($new)['state']);
        self::assertNull($this->state($new)['run']);
    }
    public function test_corrections_require_ownership_reason_and_actual_change(): void
    {
        $this->refused(fn ()=>$this->svc()->correct($this->project,$this->owner,$this->row->id,
            $this->data(['dimension_mm'=>'2000','correction_reason'=>'Not my company'])));
        $this->refused(fn ()=>$this->svc()->correct($this->project,$this->author,$this->row->id,
            $this->data(['dimension_mm'=>'2000','correction_reason'=>' '])));
        $this->refused(fn ()=>$this->svc()->correct($this->project,$this->author,$this->row->id,
            $this->data(['correction_reason'=>'No change'])));
    }
    public function test_events_are_immutable_and_hidden_from_wrong_project_and_nonparticipant(): void
    {
        $this->policy();$this->submit();$this->decide($this->consultant,1);
        foreach (['fitting_review_policies','fitting_review_runs','fitting_review_decisions'] as $table) {
            $this->bound(function () use ($table) {
                self::assertSame(1,DB::table($table)->count());
                self::assertSame(0,DB::table($table)->update(['created_at'=>now()]));
                self::assertSame(0,DB::table($table)->delete());
            });
            $other=Project::where('code','KISH-1')->firstOrFail();
            $this->bound(fn ()=>self::assertSame(0,DB::table($table)->count()),null,$other->id);
            $outsider=$this->userByEmail('hosseini@arvand.example');
            $this->bound(fn ()=>self::assertSame(0,DB::table($table)->count()),$outsider->company_id);
        }
    }
    public function test_http_forms_language_and_archived_project_lock(): void
    {
        $base='/projects/PH-14/piping/project-fittings';
        $this->actingAs($this->owner)->post($base.'/review-policy',['lang'=>'fa','stages'=>['user:'.$this->owner->id]])
            ->assertRedirect($base.'?lang=fa');
        $this->actingAs($this->author)->get($base.'?lang=fa&correct='.$this->row->id)->assertOk()->assertSee('correction_reason',false);
        $this->post($base.'/'.$this->row->id.'/submit',['lang'=>'en'])->assertRedirect($base.'?lang=en');
        $this->actingAs($this->owner)->get($base.'?lang=fa')->assertOk()->assertSee('review-decision',false);
        DB::table('projects')->where('id',$this->project->id)->update(['state'=>'ARCHIVED','archived_at'=>now(),'archived_by'=>$this->owner->id]);
        $this->post($base.'/'.$this->row->id.'/decide',['stage'=>1,'decision'=>'APPROVE','comment'=>'Blocked'])
            ->assertSessionHasErrors('project');
        $this->get($base.'?lang=en')->assertOk()->assertDontSee('class="spec-grid review-decision"',false);
    }
    public function test_database_guard_rejects_forged_author_signature_and_out_of_order_step(): void
    {
        $this->policy();$this->submit();$run=$this->state()['run'];
        foreach ([[$this->author,1],[$this->owner,2],[$this->owner,1]] as [$actor,$stage]) {
            try {
                $this->bound(fn ()=>DB::transaction(fn ()=>DB::table('fitting_review_decisions')->insert([
                    'id'=>(string)\Illuminate\Support\Str::uuid(),'project_id'=>$this->project->id,
                    'company_id'=>$actor->company_id,'created_by'=>$actor->id,'created_at'=>now(),
                    'run_id'=>$run->id,'stage'=>$stage,'decision'=>'APPROVE','comment'=>'Forged','actor_name'=>$actor->name,
                ])),$actor->company_id);
                self::fail('Forged SQL approval accepted');
            } catch (\Illuminate\Database\QueryException $e) { self::assertSame('42501',$e->getCode()); }
        }
        self::assertCount(0,$this->state()['decisions']);
    }

    public function test_original_author_is_excluded_after_a_colleague_corrects_the_record(): void
    {
        $qc=$this->userByEmail('sadeghi@pars.example');
        $new=$this->svc()->correct($this->project,$qc,$this->row->id,
            $this->data(['dimension_mm'=>'2002','correction_reason'=>'Colleague correction']));
        $this->policy(['user:'.$this->author->id]);
        $this->refused(fn ()=>$this->svc()->submit($this->project,$qc,$new->id));
        self::assertSame('UNREVIEWED',$this->state($new)['state']);
    }

    public function test_http_register_denies_nonparticipant_reads_and_writes(): void
    {
        $outsider = $this->userByEmail('hosseini@arvand.example');
        $base = '/projects/PH-14/piping/project-fittings';
        $this->actingAs($outsider)->get($base.'?lang=en')->assertNotFound();
        $this->post($base, $this->data())->assertNotFound();
        $this->post($base.'/'.$this->row->id.'/submit', ['lang' => 'en'])->assertNotFound();
        $this->actingAs($this->author)->get($base.'?lang=en')->assertOk();
    }

    public function test_foreign_project_or_foreign_company_cannot_act_on_a_definition(): void
    {
        $this->policy();
        $this->refused(fn ()=>$this->svc()->submit($this->project,$this->owner,$this->row->id));
        $other=Project::where('code','KISH-1')->firstOrFail();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->svc()->submit($other,$this->owner,$this->row->id);
    }

}
