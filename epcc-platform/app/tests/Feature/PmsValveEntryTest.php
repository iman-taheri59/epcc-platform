<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\PipingClassValveSpec;
use App\Models\Project;
use App\Models\User;
use App\Services\PmsAccessService;
use App\Services\PmsBulkEntryService;
use App\Services\PmsEntryService;
use App\Services\PmsValveEntryService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PmsValveEntryTest extends TestCase
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

    private function valve(array $changes = []): array
    {
        return $this->row(array_replace(['component_type' => 'VALVE', 'qualifier' => 'GATE', 'material_group_code' => 'CASTINGS', 'material_spec_code' => 'A216', 'material_grade_key' => 'WCB',
            'dimensional_standard' => 'ASME B16.10', 'pressure_rating' => '150', 'valve_mode' => 'replace', 'valve_construction_standard' => 'API 600',
            'valve_trim_standard' => 'API600', 'valve_trim_code' => '8', 'valve_operator' => 'GEAR', 'valve_description_as_written' => 'Bolted bonnet <script>valveBad()</script>'], $changes));
    }

    private function spec(PipingClassItem $item): ?PipingClassValveSpec
    {
        return $this->bound(fn () => $item->fresh()->valveSpec);
    }

    public function test_http_create_persists_independent_body_trim_and_operator_with_complete_audit(): void
    {
        $class = $this->create();
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/items?lang=en', ['item' => $this->valve(['company_id' => $this->o->company_id, 'valve_company_id' => $this->o->company_id, 'valve_description_as_written' => '  Faithful description  '])])->assertRedirect()->assertSessionHasNoErrors();
        $item = $this->bound(fn () => PipingClassItem::where('piping_class_id', $class->id)->firstOrFail());
        $spec = $this->spec($item);
        self::assertSame('ASME B16.10', $item->dimensional_standard);
        self::assertSame('API 600', $spec->construction_standard);
        self::assertSame('API600', $spec->trim_standard);
        self::assertSame('8', $spec->trim_code);
        self::assertSame('GEAR', $spec->operator);
        self::assertSame('  Faithful description  ', $spec->description_as_written);
        self::assertSame($this->a->company_id, $spec->company_id);
        self::assertSame($this->p->id, $spec->project_id);
        foreach ($spec->toArray() as $key => $value) {
            self::assertSame($value, json_decode($this->events()[1]->after_data, true)['valve_spec'][$key]);
        }
        self::assertNotNull($this->events()[1]->author_policy_id);
    }

    public function test_explicit_library_selection_retains_provenance_and_keep_preserves_it(): void
    {
        $class = $this->create();
        $catalog = app(PmsValveEntryService::class)->catalog();
        $key = array_key_first(array_filter($catalog['trims'], fn ($t) => $t['standard'] === 'API600' && $t['code'] === '8'));
        self::assertNotNull($key);
        $counts = $this->bound(fn () => [DB::table('project_standards')->count(), DB::table('standard_citations')->count()]);
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve(['valve_trim_key' => $key, 'valve_trim_code' => '999']));
        self::assertSame('8', $this->spec($item)->trim_code);
        $original = $item->entry_provenance['valve_selection'];
        self::assertSame($catalog['trims'][$key], $original['trim']);
        $original = $this->bound(fn () => $item->fresh()->entry_provenance['valve_selection']);
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve(['version' => 1, 'valve_mode' => 'keep', 'valve_operator' => ['ignored']]), $item->id);
        self::assertSame($original, $item->entry_provenance['valve_selection']);
        self::assertSame('GEAR', $this->spec($item)->operator);
        self::assertSame($counts, $this->bound(fn () => [DB::table('project_standards')->count(), DB::table('standard_citations')->count()]));
    }

    public function test_unknown_source_pair_and_check_without_operator_are_recorded_not_silently_corrected(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve(['qualifier' => 'CHECK', 'valve_operator' => null, 'valve_construction_standard' => 'PROJECT-SPEC-X', 'valve_trim_standard' => 'UNKNOWN', 'valve_trim_code' => 'X9']));
        self::assertSame('UNKNOWN', $this->spec($item)->trim_standard);
        self::assertNull($this->spec($item)->operator);
        $this->actingAs($this->a)->get('/projects/PH-14/piping/pms/'.$class->id.'?lang=en')->assertOk()->assertSee('CONSTRUCTION_STANDARD_UNKNOWN')->assertSee('TRIM_UNKNOWN');
    }

    public function test_omission_keeps_existing_layer_and_explicit_clear_retains_before_history(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve());
        $old = $this->spec($item)->toArray();
        $input = array_diff_key($this->valve(['version' => 1]), array_flip(app(PmsValveEntryService::class)->inputKeys()));
        $this->svc()->saveItem($this->p, $this->a, $class->id, $input, $item->id);
        self::assertSame($old, $this->spec($item)->toArray());
        $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve(['version' => 2, 'valve_mode' => 'clear']), $item->id);
        self::assertNull($this->spec($item));
        $event = $this->events()[3];
        foreach ($old as $key => $value) {
            self::assertSame($value, json_decode($event->before_data, true)['valve_spec'][$key]);
        }
        self::assertNull(json_decode($event->after_data, true)['valve_spec']);
    }

    public function test_row_conversion_requires_explicit_clear_and_failure_rolls_back_parent(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve());
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row(['version' => 1]), $item->id));
        self::assertSame('VALVE', $this->bound(fn () => $item->fresh()->component_type));
        self::assertSame('GEAR', $this->spec($item)->operator);
        self::assertCount(2, $this->events());
        $this->svc()->saveItem($this->p, $this->a, $class->id, $this->row(['version' => 1, 'valve_mode' => 'clear']), $item->id);
        self::assertNull($this->spec($item));
        self::assertSame('PIPE', $this->bound(fn () => $item->fresh()->component_type));
    }

    public static function invalidValves(): array
    {
        return [
            'powered' => [['valve_operator' => 'ELECTRIC']],
            'half trim' => [['valve_trim_standard' => null]],
            'bad library' => [['valve_trim_key' => 'missing']],
            'array value' => [['valve_construction_standard' => ['API 600']]],
            'too long' => [['valve_trim_code' => str_repeat('A', 17)]],
            'invalid UTF8' => [['valve_description_as_written' => "bad\xFF"]],
            'control' => [['valve_description_as_written' => "bad\0text"]],
            'mode array' => [['valve_mode' => ['replace']]],
            'mode null' => [['valve_mode' => null]],
            'not a valve' => [['component_type' => 'PIPE']],
            'empty replacement' => [['valve_construction_standard' => null, 'valve_trim_standard' => null, 'valve_trim_code' => null, 'valve_operator' => null, 'valve_description_as_written' => null]],
        ];
    }

    #[DataProvider('invalidValves')]
    public function test_invalid_valve_input_never_creates_partial_item_or_audit(array $changes): void
    {
        $class = $this->create();
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve($changes)));
        self::assertSame(0, $this->bound(fn () => PipingClassItem::where('piping_class_id', $class->id)->count()));
        self::assertSame(0, $this->bound(fn () => PipingClassValveSpec::count()));
        self::assertCount(1, $this->events());
    }

    public function test_http_missing_mode_rejects_and_malformed_old_input_renders_safely(): void
    {
        $class = $this->create();
        $input = $this->valve(['target' => 'new', 'valve_trim_key' => ['bad']]);
        unset($input['valve_mode']);
        $url = '/projects/PH-14/piping/pms/'.$class->id;
        $this->actingAs($this->a)->from($url)->post($url.'/items?lang=en', ['item' => $input])->assertRedirect($url)->assertSessionHasErrors('item.valve_mode');
        $this->get($url.'?lang=en')->assertOk()->assertSee('Invalid valve details');
        self::assertCount(1, $this->events());
    }

    public function test_bilingual_readers_get_escaped_details_and_authors_get_editor(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve());
        foreach (['en' => 'ltr', 'fa' => 'rtl'] as $lang => $dir) {
            $url = '/projects/PH-14/piping/pms/'.$class->id.'?lang='.$lang.'&item='.$item->id;
            $this->actingAs($this->o)->get($url)->assertOk()->assertSee('dir="'.$dir.'"', false)->assertSee('data-valve-summary', false)->assertSee('&lt;script&gt;valveBad()', false)->assertDontSee('<script>valveBad()', false)->assertDontSee('id="pms-valve-editor"', false);
            $this->actingAs($this->a)->get($url)->assertOk()->assertSee('id="pms-valve-editor"', false)->assertSee('API 600')->assertDontSee('<script>valveBad()', false);
        }
    }

    public function test_other_company_even_nominated_and_stale_version_cannot_edit_layer(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve());
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'selectors' => ['user:'.$this->a->id, 'user:'.$this->o->id], 'suspend' => false, 'reason' => 'Two company authors']);
        $this->actingAs($this->o)->post('/projects/PH-14/piping/pms/'.$class->id.'/items/'.$item->id, ['item' => $this->valve(['version' => 1, 'valve_mode' => 'clear'])])->assertForbidden();
        $this->refuse(fn () => $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve(['version' => 0, 'valve_mode' => 'clear']), $item->id));
        self::assertSame('GEAR', $this->spec($item)->operator);
        self::assertCount(2, $this->events());
    }

    public function test_bulk_preview_displays_details_and_confirmation_creates_independent_valve_layers(): void
    {
        $class = $this->create();
        $url = '/projects/PH-14/piping/pms/'.$class->id;
        $this->actingAs($this->a)->post($url.'/bulk/preview?lang=en', ['item' => $this->valve(), 'sizes' => ['2', '6']])->assertOk()->assertSee('API 600')->assertSee('GEAR')->assertSee('&lt;script&gt;valveBad()', false);
        self::assertCount(1, $this->events());
        $bulk = app(PmsBulkEntryService::class);
        $preview = $bulk->preview($this->p, $this->a, $class->id, $this->valve(), ['2', '6']);
        self::assertSame(2, $bulk->confirm($this->p, $this->a, $class->id, $preview['token']));
        $specs = $this->bound(fn () => PipingClassValveSpec::get());
        self::assertCount(2, $specs);
        self::assertNotSame($specs[0]->piping_class_item_id, $specs[1]->piping_class_item_id);
        self::assertSame(['GEAR', 'GEAR'], $specs->pluck('operator')->all());
        self::assertCount(3, $this->events());
    }

    private function sqlRefused(callable $fn): void
    {
        try {
            $this->bound(fn () => DB::transaction($fn));
            self::fail('Expected database refusal');
        } catch (QueryException $e) {
            self::assertSame('42501', $e->errorInfo[0]);
        }
    }

    public function test_archive_sql_guards_block_insert_update_delete_but_preserve_reads_and_reopening(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve());
        $other = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve(['size_key' => '2', 'valve_mode' => 'keep']));
        $spec = $this->spec($item);
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->p, $this->o, 'Synthetic archive'), $this->o);
        $this->sqlRefused(fn () => DB::table('piping_class_valve_specs')->where('id', $spec->id)->update(['operator' => 'LEVER']));
        $this->sqlRefused(fn () => PipingClassValveSpec::create(['project_id' => $this->p->id, 'company_id' => $this->a->company_id, 'piping_class_item_id' => $other->id, 'operator' => 'GEAR']));
        self::assertSame(0, $this->bound(fn () => DB::table('piping_class_valve_specs')->where('id', $spec->id)->delete()));
        self::assertSame('GEAR', $this->spec($item)->operator);
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/items/'.$item->id, ['item' => $this->valve(['version' => 1])])->assertRedirect()->assertSessionHasErrors('project');
        $this->bound(fn () => app(ProjectArchiveService::class)->reopen($this->p->fresh(), $this->o), $this->o);
        self::assertSame(1, $this->bound(fn () => DB::table('piping_class_valve_specs')->where('id', $spec->id)->update(['operator' => 'HANDWHEEL'])));
        self::assertSame(0, DB::table('piping_class_valve_specs')->count());
    }

    public function test_existing_layer_replacement_is_versioned_and_both_values_are_audited(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve());
        $saved = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve(['version' => 1, 'valve_operator' => 'HANDWHEEL']), $item->id);
        self::assertSame(2, $saved->entry_version);
        self::assertSame('HANDWHEEL', $this->spec($item)->operator);
        $event = $this->events()[2];
        self::assertSame('GEAR', json_decode($event->before_data, true)['valve_spec']['operator']);
        self::assertSame('HANDWHEEL', json_decode($event->after_data, true)['valve_spec']['operator']);
    }

    public function test_revoked_author_cannot_clear_valve_layer(): void
    {
        $class = $this->create();
        $item = $this->svc()->saveItem($this->p, $this->a, $class->id, $this->valve());
        app(PmsAccessService::class)->configure($this->p, $this->o, ['version' => 1, 'suspend' => true, 'reason' => 'Suspend author']);
        $this->actingAs($this->a)->post('/projects/PH-14/piping/pms/'.$class->id.'/items/'.$item->id, ['item' => $this->valve(['version' => 1, 'valve_mode' => 'clear'])])->assertForbidden();
        self::assertSame('GEAR', $this->spec($item)->operator);
        self::assertCount(2, $this->events());
    }

    public function test_late_bulk_valve_write_failure_rolls_back_all_body_rows_layers_and_events(): void
    {
        $class = $this->create();
        $bulk = app(PmsBulkEntryService::class);
        $preview = $bulk->preview($this->p, $this->a, $class->id, $this->valve(), ['2', '6']);
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION pg_temp.refuse_second_valve() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM piping_class_items WHERE id=NEW.piping_class_item_id AND nps='6') THEN
        RAISE EXCEPTION 'synthetic late valve failure';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER test_late_valve BEFORE INSERT ON piping_class_valve_specs
FOR EACH ROW EXECUTE FUNCTION pg_temp.refuse_second_valve();
SQL);
        try {
            $bulk->confirm($this->p, $this->a, $class->id, $preview['token']);
            self::fail('Expected late database failure');
        } catch (QueryException $e) {
            self::assertSame('P0001', $e->errorInfo[0]);
        }
        self::assertSame(0, $this->bound(fn () => PipingClassItem::where('piping_class_id', $class->id)->count()));
        self::assertSame(0, $this->bound(fn () => PipingClassValveSpec::count()));
        self::assertCount(1, $this->events());
    }
}
