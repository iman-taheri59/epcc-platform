<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\Project;
use App\Models\User;
use App\Services\PmsAccessService;
use App\Services\PmsEntryService;
use App\Services\PmsMeasurementService;
use App\Services\ProjectArchiveService;
use App\Services\UnitPreferenceService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PmsMeasurementTest extends TestCase
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

    private function measured(array $changes = []): array
    {
        $header = $this->h();
        unset($header['design_temp_min_c'], $header['design_temp_max_c'], $header['corrosion_allowance_mm']);

        return array_replace($header, [
            'design_temp_min_value' => '-40.000000', 'design_temp_min_unit' => 'F',
            'design_temp_max_value' => '212.000000', 'design_temp_max_unit' => 'F',
            'corrosion_allowance_value' => '0.125000', 'corrosion_allowance_unit' => 'in',
        ], $changes);
    }

    private function fresh(PipingClass $class): PipingClass
    {
        return $this->bound(fn () => $class->fresh());
    }

    public function test_fahrenheit_and_inch_inputs_preserve_original_decimals_and_precise_canonical_mirrors_with_audit(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        $class = $this->fresh($class);
        self::assertSame('-40.000000', $class->design_temp_min_value);
        self::assertSame('F', $class->design_temp_min_unit);
        self::assertSame('212.000000', $class->design_temp_max_value);
        self::assertSame('0.125000', $class->corrosion_allowance_value);
        self::assertSame('in', $class->corrosion_allowance_unit);
        self::assertSame(-40.0, $class->design_temp_min_c);
        self::assertSame(100.0, $class->design_temp_max_c);
        self::assertSame(3.175, $class->corrosion_allowance_mm);
        self::assertSame($this->h()['source_text'], $class->source_text);
        $event = $this->events()[0];
        $after = json_decode($event->after_data, true);
        self::assertSame('0.125000', $after['corrosion_allowance_value']);
        self::assertSame('in', $after['corrosion_allowance_unit']);
        self::assertNotNull($event->author_policy_id);
    }

    public function test_keep_does_not_round_trip_or_apply_changed_form_values_and_clear_preserves_history(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        $kept = $this->svc()->saveHeader($this->p, $this->a, $this->measured([
            'version' => 1, 'design_temp_min_mode' => 'keep', 'design_temp_max_mode' => 'keep',
            'corrosion_allowance_mode' => 'keep', 'corrosion_allowance_value' => '999', 'corrosion_allowance_unit' => 'mm',
        ]), $class->id);
        self::assertSame('0.125000', $kept->corrosion_allowance_value);
        self::assertSame(3.175, $kept->corrosion_allowance_mm);
        $cleared = $this->svc()->saveHeader($this->p, $this->a, $this->measured(['version' => 2, 'corrosion_allowance_mode' => 'clear']), $class->id);
        self::assertNull($cleared->corrosion_allowance_value);
        self::assertNull($cleared->corrosion_allowance_unit);
        self::assertNull($cleared->corrosion_allowance_mm);
        $event = $this->events()[2];
        self::assertSame('0.125000', json_decode($event->before_data, true)['corrosion_allowance_value']);
        self::assertNull(json_decode($event->after_data, true)['corrosion_allowance_value']);
    }

    public function test_partial_header_without_measurement_keys_preserves_existing_pairs(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        $input = $this->h(['version' => 1]);
        unset($input['design_temp_min_c'], $input['design_temp_max_c'], $input['corrosion_allowance_mm']);
        $saved = $this->svc()->saveHeader($this->p, $this->a, $input, $class->id);
        self::assertSame('F', $saved->design_temp_max_unit);
        self::assertSame(100.0, $saved->design_temp_max_c);
        self::assertSame('0.125000', $saved->corrosion_allowance_value);
    }

    public function test_absolute_zero_is_accepted_but_values_below_it_and_negative_lengths_are_not_rounded_into_validity(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured(['design_temp_min_value' => '-459.67']));
        self::assertSame(-273.15, $this->fresh($class)->design_temp_min_c);
        foreach ([['design_temp_min_value' => '-459.670001'], ['design_temp_min_value' => '-273.150001', 'design_temp_min_unit' => 'C'], ['corrosion_allowance_value' => '-0.000001', 'corrosion_allowance_unit' => 'mm']] as $bad) {
            $this->refuse(fn () => $this->svc()->saveHeader($this->p, $this->a, $this->measured($bad + ['version' => 1]), $class->id));
        }
        self::assertSame(1, $this->fresh($class)->entry_version);
        self::assertCount(1, $this->events());
    }

    public function test_mixed_temperature_units_compare_in_canonical_space(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured(['design_temp_min_value' => '32', 'design_temp_max_value' => '10', 'design_temp_max_unit' => 'C']));
        self::assertSame(0.0, $class->design_temp_min_c);
        self::assertSame(10.0, $class->design_temp_max_c);
        $this->refuse(fn () => $this->svc()->saveHeader($this->p, $this->a, $this->measured(['version' => 1, 'design_temp_min_value' => '212', 'design_temp_max_value' => '90', 'design_temp_max_unit' => 'C']), $class->id));
        self::assertSame(1, $this->fresh($class)->entry_version);
    }

    public function test_wrong_missing_unknown_and_retired_units_and_malformed_numbers_are_field_errors_without_writes(): void
    {
        $base = '/projects/'.$this->p->code.'/piping/pms?lang=en';
        foreach ([
            ['design_temp_min_unit' => 'psi'], ['design_temp_min_unit' => null], ['design_temp_min_unit' => 'NO_UNIT'],
            ['design_temp_min_unit' => 'K'], ['design_temp_min_unit' => ['F']],
            ['design_temp_min_value' => ['12']], ['design_temp_min_value' => 'NaN'], ['design_temp_min_value' => 'INF'],
            ['design_temp_min_value' => '1e99'], ['design_temp_min_value' => '1/8'], ['design_temp_min_value' => '1.1234567'],
            ['design_temp_min_mode' => 'INVALID'], ['design_temp_min_c' => 0],
        ] as $input) {
            $this->actingAs($this->a)->postJson($base, ['header' => $this->measured($input)])
                ->assertUnprocessable()->assertJsonValidationErrors(['header.design_temp_min_'.(array_key_exists('design_temp_min_unit', $input) ? 'unit' : (array_key_exists('design_temp_min_mode', $input) ? 'mode' : 'value'))]);
        }
        self::assertCount(0, $this->events());
        self::assertSame(0, $this->bound(fn () => PipingClass::where('code', 'QA-150')->count()));
    }

    public function test_conversion_upper_bounds_are_checked_in_canonical_units(): void
    {
        foreach ([['corrosion_allowance_value' => '1', 'corrosion_allowance_unit' => 'm'], ['design_temp_max_value' => '18032', 'design_temp_max_unit' => 'F']] as $bad) {
            $this->refuse(fn () => $this->svc()->saveHeader($this->p, $this->a, $this->measured($bad)));
        }
        self::assertCount(0, $this->events());
    }

    public function test_preferences_default_new_fields_but_never_reinterpret_stored_input_or_mutate_on_read(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        $this->bound(fn () => app(UnitPreferenceService::class)->setProjectPreferences($this->p, $this->o, ['TEMPERATURE' => 'F', 'LENGTH' => 'in']), $this->o);
        $present = fn (?PipingClass $c) => $this->bound(fn () => app(PmsMeasurementService::class)->presentation($this->p, $this->a, $c, 'en'));
        self::assertSame('F', $present(null)['design_temp_min']['input_unit']);
        self::assertSame('in', $present(null)['corrosion_allowance']['input_unit']);
        $this->bound(fn () => app(UnitPreferenceService::class)->setUserPreferences($this->a, ['TEMPERATURE' => 'C', 'LENGTH' => 'mm']));
        self::assertSame('C', $present(null)['design_temp_min']['input_unit']);
        $display = $present($this->fresh($class));
        self::assertSame('F', $display['design_temp_max']['input_unit']);
        self::assertSame('212.000000', $display['design_temp_max']['input_value']);
        self::assertSame('100', $display['design_temp_max']['preferred_value']);
        self::assertSame('3.175', $display['corrosion_allowance']['preferred_value']);
        self::assertSame(1, $this->fresh($class)->entry_version);
        self::assertCount(1, $this->events());
    }

    public function test_bilingual_forms_and_readers_show_original_canonical_and_display_values_without_writable_mirrors(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        foreach (['fa' => 'rtl', 'en' => 'ltr'] as $lang => $dir) {
            $url = '/projects/'.$this->p->code.'/piping/pms/'.$class->id.'?lang='.$lang;
            $this->actingAs($this->a)->get($url)->assertOk()->assertSee('dir="'.$dir.'"', false)
                ->assertSee('name="header[design_temp_min_unit]"', false)
                ->assertDontSee('name="header[design_temp_min_c]"', false)->assertSee('0.125000 in')
                ->assertSee('3.175 mm')->assertDontSee('<script>window.bad=true</script>', false);
            $this->actingAs($this->o)->get($url)->assertOk()->assertSee('0.125000 in')->assertSee('100 C')
                ->assertDontSee('name="header[design_temp_min_unit]"', false);
        }
        self::assertCount(1, $this->events());
    }

    public function test_author_company_stale_and_archive_controls_remain_in_force(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        $url = '/projects/'.$this->p->code.'/piping/pms/'.$class->id.'/header?lang=en';
        $this->actingAs($this->o)->postJson($url, ['header' => $this->measured(['version' => 1])])->assertForbidden();
        $this->actingAs($this->a)->postJson($url, ['header' => $this->measured(['version' => 0])])->assertUnprocessable();
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->p, $this->o, 'Archive fixture'), $this->o);
        $this->actingAs($this->a)->postJson($url, ['header' => $this->measured(['version' => 1])])->assertUnprocessable();
        self::assertSame('0.125000', $this->fresh($class)->corrosion_allowance_value);
        self::assertCount(1, $this->events());
    }

    private function rejectSql(callable $fn): void
    {
        $this->bound(function () use ($fn): void {
            try {
                DB::transaction($fn);
                self::fail('Expected SQL constraint refusal');
            } catch (QueryException $e) {
                self::assertSame('23514', $e->getCode());
            }
        });
    }

    public function test_database_rejects_incomplete_pairs_mirror_only_edits_and_pair_discard_without_recomputation_claim(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        foreach ([['corrosion_allowance_unit' => null], ['corrosion_allowance_mm' => 4],
            ['corrosion_allowance_value' => null, 'corrosion_allowance_unit' => null],
            ['corrosion_allowance_value' => 'NaN'], ['design_temp_min_value' => '-300', 'design_temp_min_unit' => 'C', 'design_temp_min_c' => -300],
        ] as $changes) {
            $this->rejectSql(fn () => DB::table('piping_classes')->where('id', $class->id)->update($changes));
        }
        self::assertSame(3.175, $this->fresh($class)->corrosion_allowance_mm);
        self::assertSame('0.125000', $this->fresh($class)->corrosion_allowance_value);
    }

    private function oldMeasurementSchema(): void
    {
        DB::unprepared('DROP TRIGGER pms_measurement_mirrors_guard ON piping_classes');
        foreach (PmsMeasurementService::FIELDS as $field => $definition) {
            DB::statement("ALTER TABLE piping_classes DROP CONSTRAINT pms_{$field}_bounds, DROP CONSTRAINT pms_{$field}_pair, DROP COLUMN {$field}_value, DROP COLUMN {$field}_unit");
            $type = $field === 'corrosion_allowance' ? 'numeric(5,2)' : 'numeric(6,1)';
            DB::statement('ALTER TABLE piping_classes ALTER COLUMN '.$definition['canonical'].' TYPE '.$type);
        }
    }

    private function migrateMeasurements(): void
    {
        $migration = require database_path('migrations/2026_09_29_235800_add_pms_header_measurements.php');
        DB::transaction(fn () => $migration->up());
    }

    public function test_populated_forward_upgrade_keeps_legacy_numbers_and_archive_without_inventing_source_pairs(): void
    {
        $this->oldMeasurementSchema();
        $class = $this->bound(fn () => PipingClass::create(['project_id' => $this->p->id, 'company_id' => $this->a->company_id, 'code' => 'LEGACY', 'design_temp_min_c' => -20, 'design_temp_max_c' => 200, 'corrosion_allowance_mm' => 3.18]));
        $this->bound(fn () => app(ProjectArchiveService::class)->archive($this->p, $this->o, 'Archive old data'), $this->o);
        $this->migrateMeasurements();
        $upgraded = $this->fresh($class);
        self::assertSame(3.18, $upgraded->corrosion_allowance_mm);
        self::assertSame(-20.0, $upgraded->design_temp_min_c);
        self::assertNull($upgraded->corrosion_allowance_value);
        self::assertNull($upgraded->corrosion_allowance_unit);
        self::assertCount(0, $this->events());
        $this->actingAs($this->a)->get('/projects/'.$this->p->code.'/piping/pms/'.$class->id.'?lang=en')
            ->assertOk()->assertSee('Legacy value: only the canonical value is available');
    }

    public function test_legacy_keep_does_not_turn_displayed_canonical_into_fabricated_original_input(): void
    {
        $class = $this->bound(fn () => PipingClass::create(['project_id' => $this->p->id, 'company_id' => $this->a->company_id, 'code' => 'LEGACY', 'design_temp_max_c' => 200]));
        $input = $this->measured(['version' => 0, 'design_temp_min_mode' => 'keep', 'design_temp_max_mode' => 'keep', 'corrosion_allowance_mode' => 'keep']);
        $saved = $this->svc()->saveHeader($this->p, $this->a, $input, $class->id);
        self::assertNull($saved->design_temp_max_value);
        self::assertNull($saved->design_temp_max_unit);
        self::assertSame(200.0, $saved->design_temp_max_c);
        $saved = $this->svc()->saveHeader($this->p, $this->a, $this->measured(['version' => 1, 'design_temp_max_value' => '392.00']), $class->id);
        self::assertSame('392.00', $saved->design_temp_max_value);
        self::assertSame(200.0, $saved->design_temp_max_c);
    }

    public function test_upgrade_refuses_impossible_legacy_values_instead_of_silently_correcting_them(): void
    {
        $this->oldMeasurementSchema();
        $class = $this->bound(fn () => PipingClass::create(['project_id' => $this->p->id, 'company_id' => $this->a->company_id, 'code' => 'IMPOSSIBLE-LEGACY', 'design_temp_min_c' => -300]));
        try {
            $this->migrateMeasurements();
            self::fail('Expected invalid historical data to stop migration');
        } catch (QueryException $e) {
            self::assertSame('23514', $e->getCode());
        }
        self::assertFalse(Schema::hasColumn('piping_classes', 'design_temp_min_value'));
        self::assertSame(-300.0, $this->fresh($class)->design_temp_min_c);
    }

    public function test_legacy_explicit_unit_api_inputs_are_captured_and_pressure_rating_is_not_converted(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->h(['pressure_rating' => 'Class 150']));
        self::assertSame('-20', $class->design_temp_min_value);
        self::assertSame('C', $class->design_temp_min_unit);
        self::assertSame('3', $class->corrosion_allowance_value);
        self::assertSame('mm', $class->corrosion_allowance_unit);
        self::assertSame('Class 150', $class->pressure_rating);
    }

    public function test_unit_without_explicit_value_cannot_silently_clear_an_existing_measurement(): void
    {
        $class = $this->svc()->saveHeader($this->p, $this->a, $this->measured());
        $input = $this->measured(['version' => 1]);
        unset($input['design_temp_min_value']);
        $this->actingAs($this->a)->postJson('/projects/'.$this->p->code.'/piping/pms/'.$class->id.'/header?lang=en', ['header' => $input])
            ->assertUnprocessable()->assertJsonValidationErrors(['header.design_temp_min_value']);
        self::assertSame('-40.000000', $this->fresh($class)->design_temp_min_value);
        self::assertSame(1, $this->fresh($class)->entry_version);
        self::assertCount(1, $this->events());
    }
}
