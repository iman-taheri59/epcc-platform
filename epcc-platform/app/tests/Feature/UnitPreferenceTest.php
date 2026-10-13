<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\PipingLineService;
use App\Services\ProjectArchiveService;
use App\Services\UnitPreferenceService;
use App\Support\LineReleaseState;
use App\Support\TenantContext;
use App\Support\Units\Quantity;
use App\Support\Units\UnitRegistry;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit preferences and the canonical mirror columns (ADR-014).
 *
 * The behaviour that matters: two people looking at the same line see the same
 * physical quantity written in their own units, the stored number never
 * changes because someone changed a display setting, and a comparison across
 * mixed units gives the physically correct answer.
 */
final class UnitPreferenceTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);

        // The auto-numbering path is withdrawn in production (a counter
        // over surviving rows reissues a deleted line's number). These
        // tests exercise the numbering ENGINE, so they opt in explicitly.
        $this->allowAutoLineNumbers(\App\Models\Project::where('code', 'PH-14')->firstOrFail());
    }

    private function prefs(): UnitPreferenceService
    {
        return app(UnitPreferenceService::class);
    }

    private function project(string $code = 'PH-14'): Project
    {
        return Project::where('code', $code)->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    /** @param array<string, mixed> $extra */
    private function makeLine(array $extra = []): PipingLine
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->asOwner(function () use ($project, $owner, $extra) {
            $svc = app(PipingLineService::class);
            $svc->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);
            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );
            $sub = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();

            return $svc->create($sub, $owner, array_merge([
                'nominal_size' => '8',
                'service_code' => 'P',
                'piping_class_id' => $class->id,
            ], $extra));
        });
    }

    // ------------------------------------------------ resolution order --

    public function test_without_any_preference_the_default_display_unit_is_used(): void
    {
        // The floor - and it is the default DISPLAY unit, not the canonical
        // one. Pressure is stored in kPa but read in bar; falling back to kPa
        // would show every unconfigured project a unit that is not even on the
        // menu.
        $this->assertSame('bar', $this->prefs()->displayUnit(Quantity::PRESSURE));
        $this->assertSame('C', $this->prefs()->displayUnit(Quantity::TEMPERATURE));
    }

    public function test_the_fallback_unit_is_always_one_the_user_could_have_picked(): void
    {
        // Retiring a unit is a pack edit, so it is possible to retire the very
        // unit the screen falls back to. That would render a dropdown whose
        // current value is missing from its own list.
        $registry = app(UnitRegistry::class);

        foreach ([Quantity::PRESSURE, Quantity::TEMPERATURE] as $quantity) {
            $fallback = $this->prefs()->displayUnit($quantity);
            $offered = array_column($registry->selectableUnitsFor($quantity), 'code');

            $this->assertContains($fallback, $offered, "{$quantity} falls back to an unpickable unit");
        }
    }

    public function test_the_project_default_overrides_the_fallback(): void
    {
        $project = $this->project();

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences(
            $project,
            $this->owner(),
            [Quantity::PRESSURE => 'psi'],
        ));

        $this->assertSame('psi', $this->asOwner(
            fn () => $this->prefs()->displayUnit(Quantity::PRESSURE, $project),
        ));
    }

    public function test_a_user_preference_overrides_the_project(): void
    {
        // The scenario the user described: an international contractor reads
        // psi off the same rows the employer reads in barg.
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences($project, $owner, [
            Quantity::PRESSURE => 'bar',
        ]));

        $contractor = $this->userByEmail('rezaei@pars.example');
        $saved = $this->prefs()->setUserPreferences($contractor, [Quantity::PRESSURE => 'psi']);

        // Use the returned model, not ->fresh(): `users` carries RLS and a
        // bare re-read in a test binds no tenant, so fresh() yields null.
        $this->assertSame('psi', $this->asOwner(
            fn () => $this->prefs()->displayUnit(Quantity::PRESSURE, $project, $saved),
        ));
        $this->assertSame('bar', $this->asOwner(
            fn () => $this->prefs()->displayUnit(Quantity::PRESSURE, $project, $owner),
        ));
    }

    public function test_clearing_a_user_preference_returns_to_inheriting(): void
    {
        // Null and empty must stay distinguishable: without this a user could
        // never undo a choice, only replace it.
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences($project, $owner, [
            Quantity::PRESSURE => 'bar',
        ]));

        $user = $this->userByEmail('rezaei@pars.example');
        $this->prefs()->setUserPreferences($user, [Quantity::PRESSURE => 'psi']);
        $cleared = $this->prefs()->setUserPreferences($user, [Quantity::PRESSURE => '']);

        $this->assertNull($cleared->unit_preferences);
        $this->assertSame('bar', $this->asOwner(
            fn () => $this->prefs()->displayUnit(Quantity::PRESSURE, $project, $cleared),
        ));
    }

    public function test_a_preference_for_one_quantity_does_not_affect_another(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences($project, $owner, [
            Quantity::PRESSURE => 'psi',
        ]));

        $this->assertSame('psi', $this->asOwner(fn () => $this->prefs()->displayUnit(Quantity::PRESSURE, $project)));
        $this->assertSame('C', $this->asOwner(fn () => $this->prefs()->displayUnit(Quantity::TEMPERATURE, $project)));
    }

    // ------------------------------------------------------ validation --

    public function test_a_unit_from_the_wrong_quantity_is_refused(): void
    {
        // Choosing psi for TEMPERATURE would render every temperature as a
        // pressure. Checking that the unit exists is not enough - the pairing
        // has to be checked.
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences(
            $this->project(),
            $this->owner(),
            [Quantity::TEMPERATURE => 'psi'],
        ));
    }

    public function test_an_unknown_unit_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences(
            $this->project(),
            $this->owner(),
            [Quantity::PRESSURE => 'furlongs'],
        ));
    }

    public function test_an_unknown_quantity_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences(
            $this->project(),
            $this->owner(),
            ['PRESURE' => 'barg'],
        ));
    }

    public function test_only_the_employer_sets_project_defaults(): void
    {
        $contractor = $this->userByEmail('rezaei@pars.example');

        $this->expectException(ValidationException::class);

        $this->tenant->runAs(
            $contractor->company_id,
            fn () => $this->prefs()->setProjectPreferences($this->project(), $contractor, [
                Quantity::PRESSURE => 'psi',
            ]),
            $this->project()->id,
        );
    }

    public function test_a_user_may_set_their_own_units_on_an_archived_project(): void
    {
        // Deliberately not gated: this changes nothing about the data, only
        // how one person reads it. Blocking it would protect nothing.
        $project = $this->project();
        $owner = $this->owner();

        app(ProjectArchiveService::class)->archive($project, $owner, 'Closed out');

        try {
            $user = $this->prefs()->setUserPreferences($owner, [Quantity::PRESSURE => 'psi']);

            $this->assertSame(['PRESSURE' => 'psi'], $user->unit_preferences);
        } finally {
            app(ProjectArchiveService::class)->reopen($project->fresh(), $owner);
        }
    }

    public function test_project_defaults_are_refused_on_an_archived_project(): void
    {
        // The counterpart: this one IS project data.
        $project = $this->project();
        $owner = $this->owner();

        app(ProjectArchiveService::class)->archive($project, $owner, 'Closed out');

        try {
            $this->expectException(ValidationException::class);
            $this->asOwner(fn () => $this->prefs()->setProjectPreferences(
                $project->fresh(),
                $owner,
                [Quantity::PRESSURE => 'psi'],
            ));
        } finally {
            app(ProjectArchiveService::class)->reopen($project->fresh(), $owner);
        }
    }

    // --------------------------------------------- the canonical mirror --

    public function test_a_line_stores_both_the_entered_and_canonical_values(): void
    {
        $line = $this->makeLine([
            'design_pressure' => 150,
            'design_pressure_unit' => 'psi',
            'design_temperature' => 200,
            'design_temperature_unit' => 'F',
        ]);

        $this->assertEqualsWithDelta(150.0, (float) $line->design_pressure, 1e-6, 'the entered value is untouched');
        $this->assertSame('psi', $line->design_pressure_unit);
        $this->assertEqualsWithDelta(1034.2136, (float) $line->design_pressure_kpa, 1e-3);

        $this->assertEqualsWithDelta(200.0, (float) $line->design_temperature, 1e-6);
        $this->assertEqualsWithDelta(93.3333, (float) $line->design_temperature_c, 1e-3);
    }

    public function test_changing_a_display_preference_does_not_touch_stored_values(): void
    {
        // The property the whole design exists to guarantee. A display setting
        // must never rewrite engineering data.
        $line = $this->makeLine([
            'design_pressure' => 150,
            'design_pressure_unit' => 'psi',
        ]);

        // Re-read from the database, not from the in-memory model: the
        // canonical column is decimal(16,6) and the freshly computed value in
        // memory has not been through that rounding yet. Comparing a stored
        // read against a stored read is what "unchanged" actually means here.
        $before = $this->asOwner(fn () => PipingLine::findOrFail($line->id))
            ->only(['design_pressure', 'design_pressure_unit', 'design_pressure_kpa']);

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences($this->project(), $this->owner(), [
            Quantity::PRESSURE => 'bar',
        ]));

        $after = $this->asOwner(fn () => PipingLine::findOrFail($line->id));

        $this->assertSame(
            $before,
            $after->only(['design_pressure', 'design_pressure_unit', 'design_pressure_kpa']),
            'a display setting must never rewrite engineering data',
        );
    }

    public function test_the_canonical_column_rounds_to_its_declared_precision(): void
    {
        // Documented rather than hidden. 150 psi is 1034.2135935 kPa exactly,
        // but the column is decimal(16,6), so it stores 1034.213594 - a
        // rounding of 5e-7 kPa.
        //
        // That is why the ENTERED value is the source of truth and this column
        // is only ever used for comparison and sorting. If a report ever
        // printed this number instead of the entered one, it would be showing
        // a figure that appears on no document.
        $line = $this->makeLine([
            'design_pressure' => 150,
            'design_pressure_unit' => 'psi',
        ]);

        $stored = $this->asOwner(fn () => PipingLine::findOrFail($line->id));

        $this->assertSame(150.0, (float) $stored->design_pressure, 'the entered value is exact');
        $this->assertEqualsWithDelta(1034.2135935, (float) $stored->design_pressure_kpa, 1e-5);
        $this->assertNotSame(1034.2135935, (float) $stored->design_pressure_kpa, 'and the mirror is rounded');
    }

    public function test_lines_in_different_units_compare_correctly(): void
    {
        // The reason the canonical column exists: 100 psi < 10 barg is only
        // answerable in a common unit, and it has to be answerable in SQL.
        $this->makeLine(['design_pressure' => 100, 'design_pressure_unit' => 'psi']);
        $this->makeLine(['design_pressure' => 10, 'design_pressure_unit' => 'barg']);
        $this->makeLine(['design_pressure' => 5, 'design_pressure_unit' => 'barg']);

        $above = $this->asOwner(fn () => PipingLine::query()
            ->where('design_pressure_kpa', '>', 690.0)
            ->orderBy('design_pressure_kpa')
            ->pluck('design_pressure_unit')
            ->all());

        // 100 psi = 689.5 kPa is BELOW the threshold; 5 barg = 500 also.
        // Only 10 barg = 1000 kPa qualifies.
        $this->assertSame(['barg'], $above);
    }

    public function test_a_value_without_a_unit_gets_no_canonical(): void
    {
        // Guessing the unit is exactly how a psi number ends up in a barg
        // column. No unit, no derived value.
        $line = $this->makeLine(['design_pressure' => 150]);

        $this->assertNull($line->design_pressure_kpa);
    }

    public function test_clearing_a_value_clears_its_canonical(): void
    {
        // A stale canonical outliving the number it describes would keep a
        // deleted pressure alive in every roll-up.
        $line = $this->makeLine([
            'design_pressure' => 150,
            'design_pressure_unit' => 'psi',
        ]);

        $this->assertNotNull($line->design_pressure_kpa);

        // Mutation M35: passing design_pressure_kpa => null here proved
        // nothing - the test was clearing the mirror itself. Go through the
        // service with only the entered pair and let it derive the null.
        $updated = $this->asOwner(fn () => app(PipingLineService::class)->updateDesign(
            $line,
            $this->owner(),
            ['design_pressure' => null, 'design_pressure_unit' => null],
        ));

        $this->assertNull($updated->design_pressure, 'the entered value is gone');
        $this->assertNull($updated->design_pressure_kpa, 'so the mirror must be gone too');
    }

    public function test_updating_a_value_recomputes_its_canonical(): void
    {
        // The other half: a changed pressure must not leave yesterday's
        // canonical behind, or every threshold query silently uses the old
        // number.
        $line = $this->makeLine(['design_pressure' => 150, 'design_pressure_unit' => 'psi']);

        $updated = $this->asOwner(fn () => app(PipingLineService::class)->updateDesign(
            $line,
            $this->owner(),
            ['design_pressure' => 10, 'design_pressure_unit' => 'barg'],
        ));

        $this->assertEqualsWithDelta(1000.0, (float) $updated->design_pressure_kpa, 1e-6);
    }

    public function test_design_cannot_be_edited_on_an_archived_project(): void
    {
        // Mutation M38: updateDesign is a write path, so it needs the same
        // archive gate as every other one. Nothing else covered it.
        $line = $this->makeLine(['design_pressure' => 150, 'design_pressure_unit' => 'psi']);
        $project = $this->project();
        $owner = $this->owner();

        app(ProjectArchiveService::class)->archive($project, $owner, 'Closed out');

        try {
            $this->expectException(ValidationException::class);
            $this->asOwner(fn () => app(PipingLineService::class)->updateDesign(
                $line,
                $owner,
                ['design_pressure' => 300, 'design_pressure_unit' => 'psi'],
            ));
        } finally {
            app(ProjectArchiveService::class)->reopen($project->fresh(), $owner);
        }
    }

    public function test_a_released_line_cannot_have_its_design_edited(): void
    {
        // Changing the design pressure of a line issued for construction is a
        // revision, not an edit.
        $line = $this->makeLine(['design_pressure' => 150, 'design_pressure_unit' => 'psi']);

        // RELEASED carries a CHECK demanding AFC provenance, so the shortcut
        // has to supply it - the constraint is doing its job.
        $this->asOwner(function () use ($line) {
            $line->forceFill([
                'release_state' => LineReleaseState::RELEASED,
                'afc_confirmed_at' => now(),
                'afc_confirmed_by' => $this->owner()->id,
                'released_at' => now(),
                'released_by' => $this->owner()->id,
            ])->save();
        });

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => app(PipingLineService::class)->updateDesign(
            $line->fresh(),
            $this->owner(),
            ['design_pressure' => 300, 'design_pressure_unit' => 'psi'],
        ));
    }

    public function test_an_unknown_unit_on_a_line_is_a_validation_error(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeLine(['design_pressure' => 150, 'design_pressure_unit' => 'furlongs']);
    }

    public function test_a_temperature_below_absolute_zero_is_refused_on_a_line(): void
    {
        $this->expectException(ValidationException::class);

        $this->makeLine(['design_temperature' => -400, 'design_temperature_unit' => 'C']);
    }

    // ------------------------------------------------- the DB backstop --

    public function test_the_database_refuses_a_canonical_without_its_unit(): void
    {
        // The service refuses first with a readable message; this proves the
        // constraint is there for a future write path that forgets to ask.
        $line = $this->makeLine();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('piping_lines')
            ->where('id', $line->id)
            ->update(['design_pressure_kpa' => 1000.0, 'design_pressure_unit' => null]));
    }

    public function test_the_database_refuses_a_temperature_below_absolute_zero(): void
    {
        $line = $this->makeLine();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('piping_lines')
            ->where('id', $line->id)
            ->update(['design_temperature_c' => -300.0, 'design_temperature_unit' => 'C']));
    }

    // ------------------------------------------------------------- HTTP --

    public function test_the_register_renders_design_conditions_in_the_users_unit(): void
    {
        $this->makeLine(['design_pressure' => 150, 'design_pressure_unit' => 'psi']);

        $owner = $this->owner();
        $this->prefs()->setUserPreferences($owner, [Quantity::PRESSURE => 'bar']);

        $html = $this->actingAs($owner->fresh() ?? $owner)
            ->get('/projects/PH-14/piping?lang=en')
            ->assertOk()
            ->getContent();

        // 150 psi = 10.34 bar, and the entered figure stays visible beside it.
        $this->assertStringContainsString('10.34 bar', $html);
        $this->assertStringContainsString('150 psi', $html);
    }

    public function test_posting_unit_preferences_saves_and_redirects(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/projects/PH-14/piping/units?lang=en', [
                'scope' => 'user',
                'units' => [Quantity::PRESSURE => 'psi'],
            ])
            ->assertRedirect();

        $this->assertSame(
            'psi',
            $this->asOwner(fn () => $this->prefs()->displayUnit(Quantity::PRESSURE, $this->project(), $owner->fresh() ?? $owner)),
        );
    }

    public function test_posting_a_mismatched_unit_returns_an_error_not_a_crash(): void
    {
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post('/projects/PH-14/piping/units?lang=en', [
                'scope' => 'user',
                'units' => [Quantity::TEMPERATURE => 'psi'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('units');
    }

    public function test_creating_a_line_with_a_bad_unit_is_a_form_error(): void
    {
        // The whole point of catching ValidationException in the controller:
        // a typo in a unit must come back as a field error, not a 500.
        $project = $this->project();
        $owner = $this->owner();

        // The project pattern includes a class token, so the post has to
        // carry one or the numbering engine refuses before units are reached.
        $class = $this->asOwner(function () use ($project) {
            app(PipingLineService::class)->configFor($project)->update(['services' => ['P']]);

            return PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );
        });

        $sub = $this->asOwner(fn () => Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail());

        $this->actingAs($owner)
            ->post('/projects/PH-14/piping?lang=en', [
                'subsystem_id' => $sub->id,
                'nominal_size' => '8',
                'service_code' => 'P',
                'piping_class_id' => $class->id,
                'design_pressure' => 150,
                'design_pressure_unit' => 'furlongs',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('design_pressure_unit');
    }

    // ------------------------------------------------ the narrowed menu --

    public function test_the_menu_offers_only_bar_and_psi_for_pressure(): void
    {
        // User decision, 2026-09-17.
        $codes = array_column(app(UnitRegistry::class)->selectableUnitsFor(Quantity::PRESSURE), 'code');

        $this->assertEqualsCanonicalizing(['bar', 'psi'], $codes);
    }

    public function test_the_menu_offers_only_celsius_and_fahrenheit(): void
    {
        $codes = array_column(app(UnitRegistry::class)->selectableUnitsFor(Quantity::TEMPERATURE), 'code');

        $this->assertEqualsCanonicalizing(['C', 'F'], $codes);
    }

    public function test_a_retired_unit_cannot_be_chosen(): void
    {
        // barg is a real unit the engine converts perfectly well. It is simply
        // not on the menu any more.
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->prefs()->setProjectPreferences(
            $this->project(),
            $this->owner(),
            [Quantity::PRESSURE => 'barg'],
        ));
    }

    public function test_kelvin_cannot_be_chosen(): void
    {
        $this->expectException(ValidationException::class);

        $this->prefs()->setUserPreferences(
            $this->userByEmail('rezaei@pars.example'),
            [Quantity::TEMPERATURE => 'K'],
        );
    }

    public function test_refusing_a_retired_unit_names_the_ones_that_work(): void
    {
        // "Unknown unit" would be a lie - the engine knows kPa. The message has
        // to distinguish "does not exist" from "not on this menu", and say what
        // to pick instead.
        try {
            $this->prefs()->setUserPreferences(
                $this->userByEmail('rezaei@pars.example'),
                [Quantity::PRESSURE => 'kPa'],
            );
            $this->fail('kPa should not be selectable');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['units'] ?? []);

            $this->assertStringContainsString('kPa', $message);
            $this->assertStringContainsString('bar', $message);
            $this->assertStringContainsString('psi', $message);
        }
    }

    public function test_a_retired_unit_still_converts(): void
    {
        // The whole reason for retiring via a flag instead of deleting the unit:
        // values already stored in kPa, or arriving from an import in kg/cm2,
        // must stay readable forever.
        $registry = app(UnitRegistry::class);

        $this->assertEqualsWithDelta(1000.0, $registry->convert(10, 'bar', 'kPa'), 1e-9);
        $this->assertEqualsWithDelta(98.0665, $registry->convert(1, 'kg/cm2', 'kPa'), 1e-9);
        $this->assertEqualsWithDelta(-273.15, $registry->convert(0, 'K', 'C'), 1e-9);
    }

    public function test_a_line_stored_in_a_retired_unit_still_displays(): void
    {
        // An imported line whose unit is no longer on the menu must render, not
        // blow up. This is the realistic case: the pack was narrowed AFTER data
        // existed.
        $line = $this->makeLine([
            'design_pressure' => 10,
            'design_pressure_unit' => 'barg',
        ]);

        $this->assertEqualsWithDelta(1000.0, (float) $line->design_pressure_kpa, 1e-6);

        $html = $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping?lang=en')
            ->assertOk()
            ->getContent();

        // Shown in the reader's unit (bar), with the entered figure preserved.
        $this->assertStringContainsString('10 barg', $html);
    }

    public function test_the_creation_form_offers_only_the_agreed_units(): void
    {
        $html = $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping?lang=en')
            ->assertOk()
            ->getContent();

        // Isolate the design-pressure select so other markup cannot mask a
        // stray option.
        preg_match('/<select name="design_pressure_unit".*?<\/select>/s', $html, $m);
        $this->assertNotEmpty($m, 'the design pressure unit select is missing');

        preg_match_all('/value="([^"]+)"/', $m[0], $opts);
        $this->assertEqualsCanonicalizing(['bar', 'psi'], $opts[1]);
    }
}
