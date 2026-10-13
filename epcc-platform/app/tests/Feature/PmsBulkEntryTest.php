<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\Project;
use App\Models\User;
use App\Services\FittingReviewService;
use App\Services\PmsAccessService;
use App\Services\PmsBulkEntryService;
use App\Services\PmsEntryService;
use App\Services\ProjectArchiveService;
use App\Services\ProjectFittingDefinitionService;
use App\Support\Reference\PipeReference;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PmsBulkEntryTest extends TestCase
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
            'material_spec' => 'SYNTHETIC ONLY', 'declaration_reason' => 'Test fixture', 'spec_number' => $approve ? 'PMS-DEFINITION' : 'PMS-UNAPPROVED', 'spec_revision' => 'A', 'spec_clause' => '1', 'source_text' => 'Not engineering clearance', 'properties' => ['schedule' => '80', 'wall_thickness_mm' => '10']]);
        if ($approve) {
            $r = app(FittingReviewService::class);
            $r->configure($this->p, $this->o, ['family' => 'PIPE', 'stages' => ['user:'.$this->c->id]]);
            $r->submit($this->p, $this->a, $d->id);
            $r->decide($this->p, $this->c, $d->id, ['stage' => 1, 'decision' => 'APPROVE', 'comment' => 'Synthetic approval']);
        }

        return $d;
    }

    private function bulk(): PmsBulkEntryService
    {
        return app(PmsBulkEntryService::class);
    }

    private function items(PipingClass $class): array
    {
        return $this->bound(fn () => PipingClassItem::where('piping_class_id', $class->id)->orderBy('dn')->get()->all());
    }

    public function test_preview_is_read_only_and_confirmation_creates_only_explicit_independent_sizes_with_audit(): void
    {
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(['dn' => 999, 'nps' => '999', 'company_id' => $this->o->company_id]), ['2', '6']);
        self::assertSame([], $this->items($class));
        self::assertCount(1, $this->events());
        self::assertSame([50, 150], array_column(array_column($preview['rows'], 'data'), 'dn'));
        self::assertSame(2, $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        $items = $this->items($class);
        self::assertCount(2, $items);
        self::assertSame([50, 150], array_map(fn ($i) => $i->dn, $items));
        self::assertSame(['2', '6'], array_map(fn ($i) => $i->nps, $items));
        foreach ($items as $item) {
            self::assertSame($this->a->company_id, $item->company_id);
            self::assertSame($this->row()['description_as_written'], $item->description_as_written);
            self::assertSame($item->nps, $item->entry_provenance['size_selection']['nps']);
            self::assertSame(1, $item->entry_version);
        }
        self::assertCount(3, $this->events());
        foreach (array_slice($this->events(), 1) as $event) {
            self::assertNotNull($event->author_policy_id);
            self::assertSame($this->a->id, $event->created_by);
        }
        $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row(['size_key' => '2', 'version' => 1, 'schedule' => '80']), $items[0]->id);
        self::assertSame(['80', '40'], array_map(fn ($i) => $i->schedule, $this->items($class)));
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        self::assertCount(2, $this->items($class));
    }

    public function test_existing_null_qualifier_axis_and_intervening_insert_block_entire_batch_without_overwrite(): void
    {
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2', '6']);
        $old = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row());
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        $conflict = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2', '6']);
        self::assertSame(1, $conflict['conflicts']);
        self::assertNull($conflict['token']);
        self::assertSame($old->id, $conflict['rows'][1]['existing_id']);
        self::assertCount(1, $this->items($class));
        self::assertSame(1, $this->items($class)[0]->entry_version);
        self::assertCount(2, $this->events());
    }

    public function test_late_database_failure_rolls_back_earlier_row_and_its_audit(): void
    {
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2', '6']);
        DB::unprepared("CREATE OR REPLACE FUNCTION pms_bulk_test_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.dn = 150 THEN RAISE EXCEPTION 'synthetic late bulk failure'; END IF; RETURN NEW; END $$;
        CREATE TRIGGER pms_bulk_test_failure BEFORE INSERT ON piping_class_items FOR EACH ROW EXECUTE FUNCTION pms_bulk_test_failure();");
        try {
            $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']);
            self::fail('Expected injected failure');
        } catch (QueryException $e) {
            self::assertStringContainsString('synthetic late bulk failure', $e->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER pms_bulk_test_failure ON piping_class_items; DROP FUNCTION pms_bulk_test_failure();');
        }
        self::assertSame([], $this->items($class));
        self::assertCount(1, $this->events());
    }

    public function test_header_or_note_changes_require_new_preview(): void
    {
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2']);
        $this->svc()->saveNote($this->p, $this->a, $class->id, ['version' => 1, 'number' => 1, 'text_en' => 'Changed basis', 'reason' => 'Add note']);
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        self::assertSame([], $this->items($class));
        self::assertCount(2, $this->events());
    }

    public function test_tampered_expired_and_cross_actor_or_class_tokens_do_not_write(): void
    {
        $this->freezeTime();
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2']);
        $other = $this->svc()->saveHeader($this->p, $this->a, $this->h(['code' => 'QA-OTHER']));
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $class->id, 'tampered'.$preview['token']));
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->o, $class->id, $preview['token']));
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $other->id, $preview['token']));
        $this->refuse(fn () => $this->bulk()->confirm(Project::where('code', 'BB-2')->firstOrFail(), $this->a, $class->id, $preview['token']));
        $this->travel(31)->minutes();
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        self::assertSame([], $this->items($class));
        self::assertCount(2, $this->events());
    }

    public function test_invalid_size_selections_or_missing_source_are_refused_without_writes(): void
    {
        $class = $this->create();
        foreach ([[], ['2', '2'], ['2-6'], ['not-a-size'], [null], array_fill(0, 101, '2')] as $sizes) {
            $this->refuse(fn () => $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), $sizes));
        }
        $this->refuse(fn () => $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(['source_ref' => '  ']), ['2']));
        self::assertSame([], $this->items($class));
        self::assertCount(1, $this->events());
    }

    public function test_http_preview_is_bilingual_escaped_and_confirm_ignores_forged_row_changes(): void
    {
        $class = $this->create();
        $base = '/projects/'.$this->p->code.'/piping/pms/'.$class->id;
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $this->actingAs($this->a)->get($base.'?lang='.$lang)->assertSee('id="pms-bulk-sizes"', false);
            $response = $this->actingAs($this->a)->post($base.'/bulk/preview?lang='.$lang, ['item' => $this->row(), 'sizes' => ['2', '6']]);
            $response->assertOk()->assertSee('dir="'.$dir.'"', false)->assertSee('&lt;script&gt;', false)->assertDontSee('<script>window.bad=true</script>', false);
            $response->assertSee('id="pms-bulk-confirm-form"', false);
            $token = $response->viewData('plan')['token'];
        }
        self::assertSame([], $this->items($class));
        $this->actingAs($this->a)->post($base.'/bulk/confirm?lang=en', ['preview_token' => $token, 'item' => ['schedule' => 'FORGED'], 'sizes' => ['12']])->assertRedirect($base.'?lang=en')->assertSessionHas('status', '2 independent rows saved.');
        self::assertSame(['40', '40'], array_map(fn ($i) => $i->schedule, $this->items($class)));
        self::assertCount(3, $this->events());
    }

    public function test_revoked_nomination_after_preview_blocks_http_confirmation(): void
    {
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2']);
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'suspend' => true, 'reason' => 'Suspend']);
        $base = '/projects/'.$this->p->code.'/piping/pms/'.$class->id;
        $this->actingAs($this->a)->post($base.'/bulk/confirm', ['preview_token' => $preview['token']])->assertForbidden();
        self::assertSame([], $this->items($class));
        self::assertCount(1, $this->events());
    }

    public function test_archive_after_preview_refuses_confirmation(): void
    {
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2']);
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->p, $this->o, 'Archive'), $this->o);
        $this->refuse(fn () => $this->bulk()->confirm($this->p->fresh(), $this->a, $class->id, $preview['token']));
        self::assertSame([], $this->items($class));
    }

    public function test_nominated_other_company_cannot_preview_class_and_cross_project_class_is_not_found(): void
    {
        $class = $this->create();
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'selectors' => ['user:'.$this->a->id, 'user:'.$this->o->id], 'suspend' => false, 'reason' => 'Both nominated']);
        $base = '/projects/'.$this->p->code.'/piping/pms/'.$class->id;
        $this->actingAs($this->o)->post($base.'/bulk/preview', ['item' => $this->row(), 'sizes' => ['2']])->assertForbidden();
        $this->actingAs($this->a)->post('/projects/BB-2/piping/pms/'.$class->id.'/bulk/preview', ['item' => $this->row(), 'sizes' => ['2']])->assertNotFound();
        self::assertSame([], $this->items($class));
    }

    public function test_http_validation_and_guest_boundary_are_applied(): void
    {
        $class = $this->create();
        $base = '/projects/'.$this->p->code.'/piping/pms/'.$class->id;
        $this->post($base.'/bulk/preview', ['item' => $this->row(), 'sizes' => ['2']])->assertRedirect();
        $this->actingAs($this->a)->postJson($base.'/bulk/preview', ['item' => $this->row(), 'sizes' => []])->assertUnprocessable();
        $this->actingAs($this->a)->postJson($base.'/bulk/confirm', [])->assertUnprocessable()->assertJsonValidationErrors(['preview_token']);
        self::assertSame([], $this->items($class));
    }

    public function test_approved_definition_link_is_preserved_per_row_and_unapproved_definition_is_rejected(): void
    {
        $class = $this->create();
        $definition = $this->definition();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(['project_definition_id' => $definition->id]), ['2', '6']);
        self::assertSame(2, $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        foreach ($this->items($class) as $row) {
            self::assertSame($definition->id, $row->project_definition_id);
            self::assertSame('40', $row->schedule);
            self::assertSame('APPROVED', $row->entry_provenance['project_definition']['state_at_selection']);
        }
        $unapproved = $this->definition(false);
        $this->refuse(fn () => $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(['project_definition_id' => $unapproved->id]), ['8']));
        self::assertCount(2, $this->items($class));
    }

    public function test_definition_superseded_after_preview_blocks_confirmation(): void
    {
        $class = $this->create();
        $definition = $this->definition();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(['project_definition_id' => $definition->id]), ['2', '6']);
        $data = $definition->only(['component_type', 'qualifier', 'size_designation', 'reference_standard', 'material_spec', 'declaration_reason', 'spec_number', 'spec_revision', 'spec_clause', 'source_text', 'properties']);
        $data['spec_revision'] = 'B';
        $data['correction_reason'] = 'Synthetic replacement after preview';
        app(FittingReviewService::class)->correct($this->p, $this->a, $definition->id, $data);
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        self::assertSame([], $this->items($class));
        self::assertCount(1, $this->events());
    }

    public function test_reference_basis_changed_after_preview_requires_new_confirmation(): void
    {
        $class = $this->create();
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(), ['2']);
        $dir = storage_path('framework/bulk-test-reference');
        mkdir($dir, 0700, true);
        foreach (glob(config('epcc.reference_dir').'/*.ref.json') as $file) {
            copy($file, $dir.'/'.basename($file));
        }
        $path = $dir.'/pipe-sizes.ref.json';
        $pack = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $pack['version'] = 'synthetic-changed-version';
        file_put_contents($path, json_encode($pack, JSON_THROW_ON_ERROR));
        app()->instance(PipeReference::class, new PipeReference($dir));
        $this->refuse(fn () => $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']));
        self::assertSame([], $this->items($class));
        self::assertCount(1, $this->events());
    }

    public function test_material_selection_is_resolved_in_preview_and_saved_without_replacing_source_words(): void
    {
        $class = $this->create();
        $catalog = $this->svc()->catalog();
        $key = array_key_first($catalog['materials']);
        $preview = $this->bulk()->preview($this->p, $this->a, $class->id, $this->row(['material_key' => $key]), ['2', '6']);
        foreach ($preview['rows'] as $row) {
            self::assertSame($catalog['materials'][$key]['material_spec_code'], $row['data']['material_spec_code']);
        }
        $this->bulk()->confirm($this->p, $this->a, $class->id, $preview['token']);
        foreach ($this->items($class) as $item) {
            self::assertSame($catalog['materials'][$key]['material_spec_code'], $item->material_spec_code);
            self::assertSame($this->row()['description_as_written'], $item->description_as_written);
            self::assertNotNull($item->entry_provenance['material_selection']);
        }
    }

    public function test_stale_browser_confirmation_returns_to_get_editor_with_inputs_not_post_only_preview(): void
    {
        $class = $this->create();
        $base = '/projects/'.$this->p->code.'/piping/pms/'.$class->id;
        $preview = $this->actingAs($this->a)->post($base.'/bulk/preview?lang=en', ['item' => $this->row(), 'sizes' => ['2', '6']]);
        $token = $preview->viewData('plan')['token'];
        $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row());
        $this->from($base.'/bulk/preview?lang=en')->post($base.'/bulk/confirm?lang=en', ['preview_token' => $token])
            ->assertRedirect($base.'?lang=en')->assertSessionHasErrors('bulk')->assertSessionHasInput('sizes', ['2', '6']);
        $this->get($base.'?lang=en')->assertOk()->assertSee('pms-item-form')->assertSee('A selected size already exists');
        self::assertCount(1, $this->items($class));
        self::assertCount(2, $this->events());
    }
}
