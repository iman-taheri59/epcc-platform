<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectFittingDefinition;
use App\Models\User;
use App\Services\ProjectFittingDefinitionService;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProjectFittingDefinitionTest extends TestCase
{
    private Project $project;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(\Database\Seeders\EpccDemoSeeder::class);
        $this->seed(\Database\Seeders\AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->actor = $this->userByEmail('rezaei@pars.example');
    }

    private function payload(array $replace = []): array
    {
        return array_replace([
            'component_type'=>'ELBOW', 'qualifier'=>'90 deg / radius per source',
            'nps'=>'52', 'dn'=>null, 'od_mm'=>'1320.8', 'dimension'=>'CENTRE_TO_END',
            'dimension_mm'=>'1981.2', 'spec_number'=>'SYNTHETIC-SPEC-001', 'spec_revision'=>'A',
            'spec_clause'=>'Table QA-7', 'source_text'=>'Synthetic test only — original source wording.',
        ], $replace);
    }

    private function create(array $replace = []): ProjectFittingDefinition
    {
        return app(ProjectFittingDefinitionService::class)->create($this->project, $this->actor, $this->payload($replace));
    }

    private function bound(string $company, callable $fn, ?string $project = null): mixed
    {
        return app(TenantContext::class)->runAs($company, $fn, $project ?? $this->project->id);
    }

    public function test_persian_and_english_pages_and_registration_preserve_language_and_source(): void
    {
        $hash = hash_file('sha256', base_path('../standards-packs/reference/fitting-dimensions.ref.json'));
        $this->actingAs($this->actor);
        $url = '/projects/PH-14/piping/project-fittings';
        $this->get($url.'?lang=fa')->assertOk()->assertSee('dir="rtl"', false);
        $this->get($url.'?lang=en')->assertOk()->assertSee('dir="ltr"', false);
        $this->post($url, $this->payload(['lang'=>'fa']))->assertRedirect($url.'?lang=fa');
        $this->get($url.'?lang=fa')->assertOk()->assertSee('SYNTHETIC-SPEC-001')->assertSee('بازبینی‌نشده');
        $row = $this->bound($this->actor->company_id, fn () => ProjectFittingDefinition::firstOrFail());
        self::assertSame('UNREVIEWED', $row->review_state);
        self::assertSame('PROJECT_SPEC', $row->provenance);
        self::assertSame($this->payload()['source_text'], $row->source_text);
        self::assertNull($row->dn);
        self::assertSame($hash, hash_file('sha256', base_path('../standards-packs/reference/fitting-dimensions.ref.json')));
    }

    public function test_contractor_can_discover_form_on_fresh_piping_register_without_creating_owner_config(): void
    {
        $this->actingAs($this->actor)->get('/projects/PH-14/piping?lang=fa')
            ->assertOk()->assertSee('/piping/project-fittings', false);
        $this->bound($this->actor->company_id,
            fn () => self::assertSame(0, DB::table('project_piping_config')->where('project_id',$this->project->id)->count()));
    }

    public function test_scope_validation_rejects_in_range_and_bad_numeric_inputs(): void
    {
        foreach (['0.5','24','48','-52','NaN','52.12345','1/0'] as $nps) {
            try { $this->create(['nps'=>$nps]); self::fail('Must reject '.$nps); }
            catch (ValidationException $e) { self::assertArrayHasKey('nps', $e->errors()); }
        }
        $small = $this->create(['nps'=>'0.25']);
        self::assertSame('0.2500', $small->nps);
    }

    public function test_required_sources_geometry_and_no_silent_rounding(): void
    {
        foreach ([['spec_number'=>' '], ['spec_revision'=>''], ['spec_clause'=>''],
            ['source_text'=>' '], ['od_mm'=>'0'], ['dimension_mm'=>'1.12345'],
            ['qualifier'=>''], ['dimension'=>'LENGTH'], ['component_type'=>'REDUCER']] as $changes) {
            try { $this->create($changes); self::fail('Invalid declaration accepted'); }
            catch (ValidationException $e) { self::assertNotEmpty($e->errors()); }
        }
        $cap = $this->create(['component_type'=>'CAP','qualifier'=>'','dimension'=>'LENGTH']);
        self::assertSame('CAP', $cap->component_type);
    }

    public function test_identity_and_approval_cannot_be_spoofed_and_html_is_escaped(): void
    {
        $this->actingAs($this->actor)->post('/projects/PH-14/piping/project-fittings', $this->payload([
            'company_id'=>$this->project->owner_company_id, 'project_id'=>'bad', 'created_by'=>'bad',
            'review_state'=>'APPROVED', 'provenance'=>'ASME_B16_9',
            'source_text'=>'<script>alert("test")</script>',
        ]))->assertRedirect();
        $row = $this->bound($this->actor->company_id, fn () => ProjectFittingDefinition::firstOrFail());
        self::assertSame($this->actor->company_id, $row->company_id);
        self::assertSame($this->project->id, $row->project_id);
        self::assertSame($this->actor->id, $row->created_by);
        self::assertSame('UNREVIEWED', $row->review_state);
        self::assertSame('PROJECT_SPEC', $row->provenance);
        $this->get('/projects/PH-14/piping/project-fittings')->assertOk()
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(', false);
    }

    public function test_duplicate_is_validation_error_and_does_not_overwrite_original(): void
    {
        $this->actingAs($this->actor);
        $url='/projects/PH-14/piping/project-fittings';
        $this->post($url, $this->payload())->assertRedirect();
        $this->post($url, $this->payload(['dimension_mm'=>'2000']))->assertSessionHasErrors('spec_number');
        $row=$this->bound($this->actor->company_id, fn () => ProjectFittingDefinition::firstOrFail());
        self::assertSame('1981.2000', $row->dimension_mm);
        $this->post($url, $this->payload(['spec_revision'=>'B']))->assertSessionHasNoErrors();
    }

    public function test_participants_can_read_but_no_one_can_update_or_delete(): void
    {
        $row=$this->create();
        foreach ([$this->actor->company_id, $this->project->owner_company_id] as $company) {
            $this->bound($company, function () use ($row) {
                self::assertTrue(ProjectFittingDefinition::whereKey($row->id)->exists());
                self::assertSame(0, ProjectFittingDefinition::whereKey($row->id)->update(['source_text'=>'overwrite']));
                self::assertSame(0, ProjectFittingDefinition::whereKey($row->id)->delete());
            });
        }
    }

    public function test_cross_project_outsider_and_removed_participant_are_hidden(): void
    {
        $this->create();
        $outsider=Company::where('code','EPC-ARVAND')->value('id');
        $this->bound($outsider, fn () => self::assertSame(0, ProjectFittingDefinition::count()));
        $other=Project::where('code','BB-2')->value('id');
        $this->bound($this->actor->company_id, fn () => self::assertSame(0, ProjectFittingDefinition::count()), $other);
        DB::table('project_company')->where('project_id',$this->project->id)->where('company_id',$this->actor->company_id)->delete();
        $this->bound($this->actor->company_id, fn () => self::assertSame(0, ProjectFittingDefinition::count()));
        $this->actingAs($this->actor)->get('/projects/PH-14/piping/project-fittings')->assertNotFound();
    }

    public function test_wrong_project_http_and_archived_project_cannot_create(): void
    {
        $this->actingAs($this->actor);
        $this->post('/projects/KISH-1/piping/project-fittings', $this->payload())->assertNotFound();
        DB::table('projects')->where('id',$this->project->id)->update(['state'=>'ARCHIVED', 'archived_at'=>now(), 'archived_by'=>$this->userByEmail('karimi@nioc.example')->id]);
        $this->post('/projects/PH-14/piping/project-fittings', $this->payload())->assertSessionHasErrors('project');
        $this->get('/projects/PH-14/piping/project-fittings')->assertOk()->assertDontSee('id="project-fitting-form"',false);
    }

    public function test_rls_rejects_forged_creator_company_and_archived_insert(): void
    {
        $row = $this->create();
        $values = $row->getAttributes();
        unset($values['created_at'], $values['updated_at']);
        foreach (['company','archive'] as $case) {
            $values['id']=(string) \Illuminate\Support\Str::uuid();
            $values['spec_revision']=$case;
            if ($case==='company') { $values['company_id']=$this->project->owner_company_id; }
            else {
                $values['company_id']=$this->actor->company_id;
                DB::table('projects')->where('id',$this->project->id)->update(['state'=>'ARCHIVED', 'archived_at'=>now(), 'archived_by'=>$this->userByEmail('karimi@nioc.example')->id]);
            }
            try {
                $this->bound($this->actor->company_id, fn () => DB::transaction(fn () => DB::table('project_fitting_definitions')->insert($values)));
                self::fail('RLS bypass');
            } catch (QueryException $e) { self::assertSame('42501',$e->getCode()); }
        }
    }
    public function test_unscoped_queries_and_user_without_membership_cannot_access_registry(): void
    {
        $this->create();
        app(TenantContext::class)->runAs($this->actor->company_id,
            fn () => self::assertSame(0, ProjectFittingDefinition::count()), null);
        $this->bound($this->actor->company_id, fn () => DB::table('project_memberships')
            ->where('project_id',$this->project->id)->where('user_id',$this->actor->id)->delete());
        $this->actingAs($this->actor)->get('/projects/PH-14/piping/project-fittings')->assertNotFound();
        $this->post('/projects/PH-14/piping/project-fittings', $this->payload())->assertNotFound();
    }

    public function test_database_constraints_reject_in_range_and_forged_approval_even_without_service(): void
    {
        $row=$this->create();
        foreach ([['nps'=>'48'],['review_state'=>'APPROVED'],['provenance'=>'ASME'],['dimension_mm'=>'0']] as $changes) {
            $values=array_replace($row->getAttributes(), $changes, ['id'=>(string) \Illuminate\Support\Str::uuid(), 'spec_revision'=>(string) \Illuminate\Support\Str::uuid()]);
            try {
                $this->bound($this->actor->company_id, fn () => DB::transaction(fn () => DB::table('project_fitting_definitions')->insert($values)));
                self::fail('Constraint bypass');
            } catch (QueryException $e) { self::assertSame('23514',$e->getCode()); }
        }
    }

    public function test_disabled_user_is_denied_and_post_keeps_english_language(): void
    {
        $url='/projects/PH-14/piping/project-fittings';
        $this->actingAs($this->actor)->post($url, $this->payload(['lang'=>'en']))->assertRedirect($url.'?lang=en');
        app(TenantContext::class)->forIdentityLookup(fn () => User::whereKey($this->actor->id)->update(['is_active'=>false]));
        $fresh=$this->userByEmail('rezaei@pars.example');
        $this->actingAs($fresh)->get($url)->assertForbidden();
        $this->post($url, $this->payload())->assertForbidden();
    }

    public function test_registry_paginates_and_preserves_language(): void
    {
        for ($i=0; $i<21; $i++) { $this->create(['spec_revision'=>'PAGE-'.$i]); }
        $response=$this->actingAs($this->actor)->get('/projects/PH-14/piping/project-fittings?lang=en');
        $response->assertOk()->assertSee('page=2',false)->assertSee('lang=en',false);
        self::assertSame(20, substr_count($response->getContent(),'data-definition='));
        $second=$this->get('/projects/PH-14/piping/project-fittings?lang=en&page=2');
        $second->assertOk();
        self::assertSame(1, substr_count($second->getContent(),'data-definition='));
    }

}
