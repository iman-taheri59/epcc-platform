<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\Project;
use App\Models\User;
use App\Services\FittingReviewService;
use App\Services\PipingClassBody;
use App\Services\PmsAccessService;
use App\Services\PmsEntryService;
use App\Services\ProjectArchiveService;
use App\Services\ProjectFittingDefinitionService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PmsEntryTest extends TestCase
{
    private Project $p;

    private User $a;

    private User $o;

    private User $c;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->p = Project::where('code', 'PH-14')->firstOrFail();
        $this->a = $this->userByEmail('rezaei@pars.example');
        $this->o = $this->userByEmail('karimi@nioc.example');
        $this->c = $this->userByEmail('tehrani@pmc.example');
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 0, 'selectors' => ['user:'.$this->a->id], 'suspend' => false, 'reason' => 'Explicit test fixture author nomination']);
    }

    private function svc(): PmsEntryService
    {
        return app(PmsEntryService::class);
    }

    private function bound(callable $fn, ?User $u = null): mixed
    {
        return app(TenantContext::class)->runAs(($u ?? $this->a)->company_id, $fn, $this->p->id);
    }

    private function h(array $x = []): array
    {
        return array_replace(['code' => 'QA-150', 'source_document' => 'SYNTHETIC-PMS', 'source_revision' => 'A', 'source_text' => 'Source header <script>window.bad=true</script>',
            'reason' => 'Synthetic transcription', 'version' => 0, 'design_temp_min_c' => '-20', 'design_temp_max_c' => '200', 'corrosion_allowance_mm' => '3'], $x);
    }

    private function row(array $x = []): array
    {
        return array_replace(['component_type' => 'PIPE', 'qualifier' => null, 'size_key' => '6', 'description_as_written' => 'Original source row <script>window.bad=true</script>',
            'source_ref' => 'page 2 row 3', 'reason' => 'Transcribed only', 'version' => 0, 'schedule' => '40', 'dimensional_standard' => 'ASME B36.10M-2022', 'end_type' => 'BW'], $x);
    }

    private function create(): PipingClass
    {
        return $this->svc()->saveHeader($this->p, $this->a, $this->h());
    }

    private function events(): array
    {
        return $this->bound(fn () => DB::table('pms_entry_events')->orderBy('created_at')->get()->all());
    }

    private function refuse(callable $fn): void
    {
        try {
            $fn();
            self::fail('Expected validation refusal');
        } catch (ValidationException $e) {
            self::assertNotEmpty($e->errors());
        }
    }

    private function definition(bool $approve = true): object
    {
        $d = app(ProjectFittingDefinitionService::class)->create($this->p, $this->a, ['component_type' => 'PIPE', 'qualifier' => 'SEAMLESS', 'size_designation' => 'NPS 6 in', 'reference_standard' => 'ASME B36.10M-2022',
            'material_spec' => 'SYNTHETIC ONLY', 'declaration_reason' => 'Test fixture', 'spec_number' => 'PMS-DEFINITION', 'spec_revision' => 'A', 'spec_clause' => '1', 'source_text' => 'Not engineering clearance', 'properties' => ['schedule' => '80', 'wall_thickness_mm' => '10']]);
        if ($approve) {
            $r = app(FittingReviewService::class);
            $r->configure($this->p, $this->o, ['family' => 'PIPE', 'stages' => ['user:'.$this->c->id]]);
            $r->submit($this->p, $this->a, $d->id);
            $r->decide($this->p, $this->c, $d->id, ['stage' => 1, 'decision' => 'APPROVE', 'comment' => 'Synthetic approval']);
        }

return $d;
    }

    public function test_header_and_row_creation_preserve_source_and_stamp_actor_with_library_provenance(): void
    {
        $class = $this->create();
        $materials = $this->svc()->catalog()['materials'];
        $key = array_key_first($materials);
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row(['material_key' => $key, 'company_id' => $this->o->company_id, 'entry_version' => 99]));
        self::assertSame(150, $item->dn);
        self::assertSame('6', $item->nps);
        self::assertSame($materials[$key]['material_spec_code'], $item->material_spec_code);
        self::assertSame($this->a->company_id, $item->company_id);
        self::assertSame(1, $item->entry_version);
        self::assertSame($this->row()['description_as_written'], $item->description_as_written);
        self::assertSame('6', $item->entry_provenance['size_selection']['nps']);
        $events = $this->events();
        self::assertCount(2, $events);
        self::assertSame($this->a->id, $events[1]->created_by);
        self::assertSame($this->h()['source_text'], $class->source_text);
    }

    public function test_explicit_approved_definition_link_does_not_overwrite_row_or_original_evidence(): void
    {
        $class = $this->create();
        $d = $this->definition();
        $before = $this->bound(fn () => $d->fresh()->toArray());
        $i = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row(['project_definition_id' => $d->id]));
        self::assertSame('40', $i->schedule);
        self::assertSame($d->id, $i->project_definition_id);
        self::assertSame('APPROVED', $i->entry_provenance['project_definition']['state_at_selection']);
        self::assertNotEmpty($i->entry_provenance['project_definition']['approval_ids']);
        self::assertNotNull($i->entry_provenance['project_definition']['screening_run_id']);
        self::assertSame($before, $this->bound(fn () => $d->fresh()->toArray()));
    }

    public function test_unreviewed_link_refused_without_partial_row_or_audit(): void
    {
        $class = $this->create();
        $d = $this->definition(false);
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row(['project_definition_id' => $d->id])));
        self::assertSame(0, $this->bound(fn () => PipingClassItem::where('piping_class_id', $class->id)->count()));
        self::assertCount(1, $this->events());
    }

    public function test_row_edit_is_version_checked_and_before_after_are_retained(): void
    {
        $c = $this->create();
        $i = $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row());
        $new = $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row(['version' => 1, 'schedule' => '80', 'reason' => 'Correct source transcription']), $i->id);
        self::assertSame(2, $new->entry_version);
        $event = $this->events()[2];
        self::assertSame('40', json_decode($event->before_data, true)['schedule']);
        self::assertSame('80', json_decode($event->after_data, true)['schedule']);
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row(['version' => 1]), $i->id));
        self::assertCount(3, $this->events());
        $this->refuse(fn () => $this->svc()->saveHeader($this->p, $this->a, $this->h(), $c->id));
    }

    public function test_unknown_source_material_and_custom_size_are_recorded_not_invented(): void
    {
        $c = $this->create();
        $i = $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row(['component_type' => 'CAP', 'size_key' => null, 'nps' => '60', 'dn' => 1500, 'dimensional_standard' => 'PROJECT SPEC', 'material_group_code' => 'UNKNOWN', 'material_spec_code' => 'PROJECT-XYZ']));
        self::assertSame('60', $i->nps);
        self::assertSame(1500, $i->dn);
        self::assertNull($i->entry_provenance['size_selection']);
        self::assertNull($i->entry_provenance['material_selection']);
        self::assertNotEmpty(app(PipingClassBody::class)->findingsForItem($i));
        $r = app(PipingClassBody::class)->fittingDimensionForItem($i);
        self::assertNotSame('AVAILABLE', $r['status'] ?? null);
    }

    public function test_duplicate_axis_and_class_code_report_validation_errors(): void
    {
        $c = $this->create();
        $this->refuse(fn () => $this->create());
        $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row());
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row()));
        self::assertCount(2, $this->events());
    }

    public function test_validation_does_not_partially_write(): void
    {
        $this->refuse(fn () => $this->svc()->saveHeader($this->p, $this->a, $this->h(['design_temp_min_c' => 500, 'design_temp_max_c' => 100])));
        $c = $this->create();
        foreach ([['size_key' => null, 'dn' => null], ['size_key' => '900'], ['note_numbers_text' => '1, bad'], ['material_spec_code' => 'A106'], ['reason' => '  '], ['component_type' => ['PIPE']]] as $bad) {
            $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row($bad)));
        }
        self::assertCount(1, $this->events());
    }

    public function test_http_fa_en_forms_history_and_xss_escape(): void
    {
        $c = $this->create();
        $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row());
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $this->actingAs($this->a)->get('/projects/PH-14/piping/pms/'.$c->id.'?lang='.$lang)->assertOk()->assertSee('dir="'.$dir.'"', false)
                ->assertSee('pms-header-form')->assertSee('pms-item-form')->assertSee('data-pms-event=', false)->assertDontSee('<script>window.bad=true</script>', false)->assertSee('&lt;script&gt;', false);
        }
    }

    public function test_http_authorized_entry_uses_nested_payload_and_server_ownership(): void
    {
        $r = $this->actingAs($this->a)->post('/projects/PH-14/piping/pms?lang=en', ['header' => $this->h(), 'company_id' => $this->o->company_id]);
        $r->assertRedirect();
        $class = $this->bound(fn () => PipingClass::where('code', 'QA-150')->firstOrFail());
        self::assertSame($this->a->company_id, $class->company_id);
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/items?lang=en', ['item' => $this->row()])->assertRedirect();
        self::assertCount(2, $this->events());
    }

    public function test_other_company_reads_but_cannot_edit_class_or_add_rows(): void
    {
        $c = $this->create();
        $url = '/projects/PH-14/piping/pms/'.$c->id;
        $this->actingAs($this->o)->get($url)->assertOk()->assertDontSee('id="pms-header-form"', false)->assertDontSee('id="pms-item-form"', false);
        $this->actingAs($this->o)->post($url.'/header', ['header' => $this->h(['version' => 1])])->assertForbidden();
        $this->actingAs($this->o)->post($url.'/items', ['item' => $this->row()])->assertForbidden();
        self::assertCount(1, $this->events());
    }

    public function test_outsider_inactive_discipline_exclusion_and_cross_project_ids(): void
    {
        $c = $this->create();
        $url = '/projects/PH-14/piping/pms/'.$c->id;
        $this->actingAs($this->userByEmail('hosseini@arvand.example'))->get($url)->assertNotFound();
        $this->actingAs($this->userByEmail('former@pars.example'))->get($url)->assertForbidden();
        $this->actingAs($this->userByEmail('mousavi@sahand.example'))->post('/projects/PH-14/piping/pms', ['header' => $this->h()])->assertForbidden();
        $this->actingAs($this->a)->get('/projects/BB-2/piping/pms/'.$c->id)->assertNotFound();
        // Nominate in the target project too, so this still tests object scoping after the new policy gate.
        app(PmsAccessService::class)->configure(Project::where('code', 'BB-2')->firstOrFail(), $this->userByEmail('ahmadi@nigc.example'), ['version' => 0, 'selectors' => ['user:'.$this->a->id], 'suspend' => false, 'reason' => 'Explicit target-project fixture nomination']);
        $this->actingAs($this->a)->post('/projects/BB-2/piping/pms/'.$c->id.'/items', ['item' => $this->row()])->assertNotFound();
    }

    public function test_archive_is_read_only_and_audit_cannot_be_updated_or_deleted(): void
    {
        $c = $this->create();
        $e = $this->events()[0];
        self::assertSame(0, $this->bound(fn () => DB::table('pms_entry_events')->where('id', $e->id)->update(['reason' => 'forged'])));
        self::assertSame(0, $this->bound(fn () => DB::table('pms_entry_events')->where('id', $e->id)->delete()));
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->p, $this->o, 'Synthetic archive'), $this->o);
        $this->p->refresh();
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row()));
        $this->actingAs($this->a)->get('/projects/PH-14/piping/pms/'.$c->id)->assertOk()->assertDontSee('id="pms-item-form"', false);
    }

    public function test_sql_cannot_link_unreviewed_definition(): void
    {
        $c = $this->create();
        $d = $this->definition(false);
        try {
            $this->bound(fn () => DB::transaction(fn () => PipingClassItem::create(['project_id' => $this->p->id, 'company_id' => $this->a->company_id, 'piping_class_id' => $c->id, 'component_type' => 'PIPE', 'dn' => 150, 'description_as_written' => 'SQL test'])->forceFill(['project_definition_id' => $d->id])->save()));
            self::fail('SQL accepted');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
    }

    public function test_numbered_note_and_header_library_edits_are_audited_and_versioned(): void
    {
        $c = $this->create();
        $key = array_key_first($this->svc()->catalog()['materials']);
        $c = $this->svc()->saveHeader($this->p, $this->a, $this->h(['version' => 1, 'material_key' => $key]), $c->id);
        self::assertNotNull($c->material_spec_code);
        self::assertNotNull($c->entry_provenance['material_selection']);
        $this->svc()->saveNote($this->p, $this->a, $c->id, ['version' => 2, 'number' => 4, 'text_en' => 'Original note', 'reason' => 'Source note']);
        $this->svc()->saveNote($this->p, $this->a, $c->id, ['version' => 3, 'number' => 4, 'text_en' => 'Corrected note', 'reason' => 'Correct transcription']);
        $e = $this->events()[3];
        self::assertSame('Original note', json_decode($e->before_data, true)['notes'][0]['text_en']);
        self::assertSame('Corrected note', json_decode($e->after_data, true)['notes'][0]['text_en']);
        $this->refuse(fn () => $this->svc()->saveNote($this->p, $this->a, $c->id, ['version' => 3, 'number' => 4, 'text_en' => 'Stale text', 'reason' => 'Stale']));
        self::assertCount(4, $this->events());
    }

    public function test_supersession_warns_without_silent_relink_and_blocks_new_old_version_selection(): void
    {
        $c = $this->create();
        $d = $this->definition();
        $i = $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row(['project_definition_id' => $d->id]));
        $pinned = $i->entry_provenance['project_definition'];
        $data = $d->only(['component_type', 'qualifier', 'size_designation', 'reference_standard', 'material_spec', 'declaration_reason', 'spec_number', 'spec_revision', 'spec_clause', 'source_text', 'properties']);
        $data['spec_revision'] = 'B';
        $data['correction_reason'] = 'Synthetic replacement';
        $new = app(FittingReviewService::class)->correct($this->p, $this->a, $d->id, $data);
        $updated = $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row(['version' => 1, 'project_definition_id' => $d->id, 'reason' => 'Keep historic reference']), $i->id);
        self::assertEquals($pinned, $updated->entry_provenance['project_definition']);
        self::assertSame($d->id, $updated->project_definition_id);
        $all = $this->svc()->scoped($this->p, $this->a, fn () => $this->svc()->definitions($this->p, $this->a));
        self::assertContains('definition_stale', $this->svc()->linkedFindings($updated, $all));
        self::assertContains('schedule_check', $this->svc()->linkedFindings($updated, $all));
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row(['size_key' => '8', 'project_definition_id' => $d->id])));
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $c->id, $this->row(['size_key' => '8', 'project_definition_id' => $new->id])));
        $this->actingAs($this->a)->get('/projects/PH-14/piping/pms/'.$c->id)->assertOk()->assertSee('data-link-finding="definition_stale"', false);
    }

    public function test_forged_old_input_is_scalar_safe_and_does_not_cross_forms(): void
    {
        $c = $this->create();
        $url = '/projects/PH-14/piping/pms/'.$c->id.'?lang=en';
        $this->actingAs($this->a)->from($url)->post('/projects/PH-14/piping/pms/'.$c->id.'/items', ['item' => $this->row(['component_type' => ['bad'], 'target' => 'new'])])->assertSessionHasErrors();
        $this->get($url)->assertOk()->assertSee('Source header &lt;script&gt;', false);
        $this->get($url.'&item[]=bad')->assertRedirect();
    }

    public function test_project_and_company_scoped_audit_cannot_be_injected_or_read_by_outsider(): void
    {
        $c = $this->create();
        $events = $this->events();
        $fake = (array) $events[0];
        $fake['id'] = (string) Str::uuid();
        $fake['company_id'] = $this->o->company_id;
        $fake['created_by'] = $this->o->id;
        try {
            $this->bound(fn () => DB::transaction(fn () => DB::table('pms_entry_events')->insert($fake)), $this->o);
            self::fail('Foreign class audit accepted');
        } catch (QueryException $e) {
            self::assertSame('42501',$e->getCode());
        }
        $this->svc()->saveItem($this->p,$this->a,$c->id,$this->row());
        $outsider = $this->userByEmail('hosseini@arvand.example');
        self::assertSame(0,$this->bound(fn () => DB::table('pms_entry_events')->count(),$outsider));
        self::assertSame(0,$this->bound(fn () => PipingClassItem::where('piping_class_id',$c->id)->count(),$outsider));
    }
}
