<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassItem;
use App\Models\PipingClassValveSpec;
use App\Models\Project;
use App\Services\ManualValves;
use App\Services\PipingClassBody;
use App\Support\Reference\ValveConstruction;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Manual (non-automatic) valves in the PMS — ADR-008 §11.9.
 *
 * **User decision: non-automatic valves are to be added**, with the
 * discriminator *if it has an actuator it is a datasheet; if not, it is a PMS
 * row.* That sentence is what most of this file defends.
 *
 * The shape of the thing being tested: a valve is the one component the PMS
 * buys that needs THREE independent answers — a body, a trim and an operator —
 * and none of them can be derived from the others. Each of the three has its
 * own reference source, and two of those sources had been built and left
 * unwired for weeks (`VALVE_TRIM` in the material library, `actuators.ref.json`
 * behind `ValveActuators`). This is the round that consumes them.
 */
final class ManualValveTest extends TestCase
{
    private Project $project;

    private PipingClass $class;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EpccDemoSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\AuthSeeder', '--force' => true]);

        $this->project = app(TenantContext::class)->forIdentityLookup(
            fn (): Project => Project::where('code', 'PH-14')->firstOrFail(),
        );

        $this->class = $this->tenant(fn (): PipingClass => PipingClass::create([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'code' => 'A1A-'.Str::random(5),
            'pressure_rating' => '150#',
        ]));
    }

    private function tenant(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs(
            $this->project->owner_company_id,
            $fn,
            $this->project->id,
        );
    }

    private function materials(): \App\Support\Reference\MaterialLibrary
    {
        return app(\App\Support\Reference\MaterialLibrary::class);
    }

    private function valves(): ManualValves
    {
        return app(ManualValves::class);
    }

    /**
     * A valve body row. DN 150 / NPS 6, cast A216 WCB, which is the ordinary
     * gate valve of any carbon-steel class.
     *
     * @param  array<string,mixed>  $attrs
     */
    private function row(array $attrs = []): PipingClassItem
    {
        return $this->tenant(fn (): PipingClassItem => PipingClassItem::create(array_merge([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'piping_class_id' => $this->class->id,
            'component_type' => 'VALVE',
            'qualifier' => 'GATE',
            'dn' => 150,
            'nps' => '6',
            'material_group_code' => 'CASTINGS',
            'material_spec_code' => 'A216',
            // The grade is not decoration: B16.34 puts A216 WCB in rating group
            // 1.1 and A216 WCC in 1.2, so a spec without a grade has no curve.
            'material_grade_key' => 'WCB',
            'dimensional_standard' => 'ASME B16.10',
            'pressure_rating' => '150#',
            'end_type' => 'FL',
        ], $attrs)));
    }

    /** @param array<string,mixed> $attrs */
    private function spec(PipingClassItem $item, array $attrs = []): PipingClassValveSpec
    {
        return $this->tenant(fn (): PipingClassValveSpec => PipingClassValveSpec::create(array_merge([
            'project_id' => $this->project->id,
            'company_id' => $this->project->owner_company_id,
            'piping_class_item_id' => $item->id,
            'construction_standard' => 'API 600',
            'trim_standard' => 'API600',
            'trim_code' => '8',
            'operator' => 'HANDWHEEL',
        ], $attrs)));
    }

    /**
     * Every read in this file goes through the tenant binding. Outside it,
     * `current_setting('epcc.project_id')` is the empty string and PostgreSQL
     * rejects it as a uuid, so a validator that looks its own spec row up dies
     * rather than returning nothing. Same seam as every other suite here.
     *
     * @return list<array{code:string,severity:string,field:string,message:string}>
     */
    private function findings(PipingClassItem $item): array
    {
        return $this->tenant(fn (): array => $this->valves()->findingsFor($item));
    }

    /**
     * @param  list<array{code:string,severity:string,field:string,message:string}>  $findings
     * @return list<string>
     */
    private function codes(array $findings): array
    {
        return array_values(array_unique(array_column($findings, 'code')));
    }

    // ------------------------------------------------ the boundary of the module

    public function test_the_schema_cannot_hold_an_actuated_valve(): void
    {
        // The user's discriminator, enforced by the database rather than by a
        // comment: an MOV is a datasheet. PNEUMATIC, ELECTRIC and HYDRAULIC
        // are all real codes in actuators.ref.json and none of them may be
        // written as a PMS operator.
        $item = $this->row();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->spec($item, ['operator' => 'ELECTRIC']);
    }

    public function test_there_is_no_actuator_column_at_all(): void
    {
        // Not "nullable", not "deferred" - absent. A column that could hold an
        // actuator would invite exactly the datasheet row the user excluded.
        $columns = array_column(\Illuminate\Support\Facades\DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = 'piping_class_valve_specs'"
        ), 'column_name');

        self::assertNotContains('actuator', $columns);
        self::assertNotContains('fail_safe', $columns);
        self::assertNotContains('actuator_code', $columns);
    }

    public function test_a_description_naming_an_actuator_is_reported(): void
    {
        // The schema fences the operator column, but the transcriber's free
        // text can still carry "MOTOR OPERATED". It is recorded and flagged,
        // never refused: the transcriber may not correct the client document.
        $item = $this->row(['description_as_written' => 'GATE VALVE, MOTOR OPERATED, API 600']);
        $this->spec($item);

        self::assertContains(
            ManualValves::ACTUATED_VALVE_IS_A_DATASHEET,
            $this->codes($this->findings($item)),
        );
    }

    // ------------------------------------------------------- a complete row

    public function test_a_fully_stated_manual_valve_row_raises_nothing(): void
    {
        // A216 WCB cast body, API 600 construction, API 600 Trim 8, handwheel
        // on a 6 inch gate valve. This is an ordinary PMS line and the
        // validator must be silent on it, or every real sheet drowns in noise.
        $item = $this->row();
        $this->spec($item);
        self::assertSame([], $this->findings($item));
    }

    public function test_a_valve_row_with_no_valve_layer_is_incomplete_not_wrong(): void
    {
        // A half-transcribed sheet is not a conflict. The body row on its own
        // is still a legible purchase, so this is INCOMPLETE.
        $item = $this->row();

        $findings = $this->findings($item);

        self::assertSame([ManualValves::VALVE_SPEC_ABSENT], $this->codes($findings));
        self::assertSame(ManualValves::SEVERITY_INCOMPLETE, $findings[0]['severity']);
    }

    public function test_nothing_is_said_about_a_row_that_is_not_a_valve(): void
    {
        $item = $this->row([
            'component_type' => 'PIPE',
            'qualifier' => null,
            'material_group_code' => 'PIPE',
            'material_spec_code' => 'A106',
        ]);

        self::assertSame([], $this->findings($item));
        self::assertFalse($this->valves()->isManualValveRow($item));
    }

    // --------------------------------------------- the construction standard

    public function test_a_construction_standard_that_does_not_cover_the_type_is_a_discrepancy(): void
    {
        // API 600 is bolted bonnet steel GATE valves and nothing else. Its
        // number is quoted for every valve in the industry, which is the same
        // habit that makes people quote its trim chart for butterfly valves.
        $item = $this->row(['qualifier' => 'BALL']);
        $this->spec($item, [
            'construction_standard' => 'API 600',
            'trim_standard' => 'API608',
            'trim_code' => 'PTFE',
            'operator' => 'LEVER',
        ]);

        self::assertContains(
            ManualValves::CONSTRUCTION_STANDARD_NOT_FOR_TYPE,
            $this->codes($this->findings($item)),
        );
    }

    public function test_api_602_above_its_size_ceiling_is_now_cited_not_hedged(): void
    {
        // This test was written when API 602 was not in the repo, and it
        // asserted the HEDGE: "scope read from the standard title, not from a
        // purchased copy". The copy arrived, clause 1 says DN 8 to DN 100
        // (NPS 1/4 to 4), and the hedge has to go - leaving it would be the
        // mirror of the original sin, disclaiming a figure we can now cite.
        $item = $this->row([
            'dn' => 200, 'nps' => '8',
            'material_group_code' => 'FORGINGS', 'material_spec_code' => 'A105',
        ]);
        $this->spec($item, ['construction_standard' => 'API 602']);

        $out = array_values(array_filter(
            $this->findings($item),
            fn (array $f): bool => $f['code'] === ManualValves::CONSTRUCTION_STANDARD_OUT_OF_SCOPE,
        ));

        self::assertCount(1, $out);
        self::assertStringNotContainsString('not from a purchased copy', $out[0]['message']);
        self::assertTrue(app(ValveConstruction::class)->scopeIsCitable('API 602'));
    }

    public function test_the_same_standard_inside_its_scope_raises_nothing(): void
    {
        // The other half of the previous test. A rule that fires everywhere is
        // indistinguishable from a rule that is wrong.
        $item = $this->row([
            'dn' => 50, 'nps' => '2',
            'material_group_code' => 'FORGINGS', 'material_spec_code' => 'A105',
        ]);
        $this->spec($item, ['construction_standard' => 'API 602']);

        self::assertSame([], $this->findings($item));
    }

    public function test_a_spaceless_standard_code_is_still_recognised(): void
    {
        // Documents are not consistent about the space. Turning 'API600' into
        // an UNKNOWN finding would be noise about formatting, not a
        // discrepancy about engineering.
        $item = $this->row();
        $this->spec($item, ['construction_standard' => 'api600']);

        self::assertSame([], $this->findings($item));
    }

    public function test_an_unknown_construction_standard_is_recorded_and_flagged(): void
    {
        $item = $this->row();
        $this->spec($item, ['construction_standard' => 'API 9999']);

        $findings = $this->findings($item);

        self::assertContains(
            ManualValves::CONSTRUCTION_STANDARD_UNKNOWN,
            $this->codes($findings),
        );
        // Recorded, not refused: the row survived the write.
        self::assertSame('API 9999', $this->tenant(
            fn () => PipingClassValveSpec::where('piping_class_item_id', $item->id)
                ->value('construction_standard')
        ));
    }

    // ------------------------------------------------------------- the body

    public function test_a_valve_body_is_cast_or_forged_and_nothing_else(): void
    {
        // Standing constraint: castings are BODY material only, and the forged
        // small-bore valve is the other half of the pair. A valve body bought
        // as pipe or plate is a transcription error.
        $item = $this->row([
            'material_group_code' => 'PLATE',
            'material_spec_code' => 'A516',
        ]);
        $this->spec($item);

        self::assertContains(
            ManualValves::BODY_NOT_A_VALVE_BODY,
            $this->codes($this->findings($item)),
        );
    }

    public function test_no_verified_standard_restricts_the_body_product_form(): void
    {
        // THE FINDING THIS TEST HAS BECOME. It began by asserting API 600 meant
        // a cast body; the purchased copy said otherwise. It was repointed at
        // API 602 - "still an unverified guess" - and that copy said otherwise
        // too. It was repointed at API 623, and so did that one. Three
        // rewrites, three collapses.
        //
        // So it now asserts the PATTERN instead of chasing the last guess:
        // every standard actually read permits both forms or does not mention
        // the axis at all. If a future purchased standard does restrict it,
        // this fails loudly - and that would be worth knowing.
        $c = app(\App\Support\Reference\ValveConstruction::class);

        $verified = array_values(array_filter(
            $c->all(),
            static fn (array $s): bool => $s['verified'],
        ));
        self::assertGreaterThanOrEqual(6, count($verified));

        foreach ($verified as $s) {
            $forms = $s['bodyGroups'];
            self::assertTrue(
                $forms === null || count($forms) > 1,
                "{$s['code']} restricts the body to ".json_encode($forms)
                .' - the first purchased standard to do so. Read the clause and'
                .' record it; do not just make this test pass.',
            );
        }
    }

    public function test_the_body_form_rule_still_works_where_a_claim_survives(): void
    {
        // The code path is still real and still needs covering, so it is
        // exercised against one of the four claims nobody has checked - and
        // the finding is required to admit that in its own message.
        $item = $this->row([
            'nps' => '2', 'dn' => 50, 'qualifier' => 'GLOBE',
            'material_group_code' => 'FORGINGS',
            'material_spec_code' => 'A105', 'material_grade_key' => null,
        ]);
        $this->spec($item, [
            'construction_standard' => 'BS 1873',
            'trim_standard' => 'API623', 'trim_code' => '8',
        ]);

        $findings = $this->findings($item);
        self::assertContains(
            ManualValves::BODY_FORM_AGAINST_STANDARD,
            $this->codes($findings),
        );

        $message = array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::BODY_FORM_AGAINST_STANDARD,
        ))[0]['message'];
        self::assertStringContainsString('not from a purchased copy', $message);
    }

    public function test_a_cast_body_on_an_api_602_valve_is_no_longer_a_finding(): void
    {
        // The other half of the folklore. API 602-2015 Table 1a lists castings
        // A216 WCB, A352 LCC and A351 CF8M alongside the forgings, so a cast
        // small-bore valve is ordinary and was being flagged.
        $item = $this->row([
            'nps' => '2',
            'dn' => 50,
            'material_group_code' => 'CASTINGS',
            'material_spec_code' => 'A216',
            'material_grade_key' => 'WCB',
        ]);
        $this->spec($item, ['construction_standard' => 'API 602']);

        self::assertNotContains(
            ManualValves::BODY_FORM_AGAINST_STANDARD,
            $this->codes($this->findings($item)),
        );
    }

    public function test_an_api_602_valve_below_nps_quarter_is_out_of_scope(): void
    {
        // Clause 1 starts at DN 8 (NPS 1/4). The guess left the lower bound
        // open, which read as "the standard does not turn on size".
        self::assertTrue(app(\App\Support\Reference\ValveConstruction::class)
            ->coversNps('API 602', 0.25));
        self::assertFalse(app(\App\Support\Reference\ValveConstruction::class)
            ->coversNps('API 602', 0.125));
    }

    public function test_a_standard_that_does_not_turn_on_body_form_says_nothing(): void
    {
        // API 6D covers cast and forged alike. An absent bodyGroups is "the
        // standard does not care", never "no forms allowed".
        $item = $this->row([
            'material_group_code' => 'FORGINGS',
            'material_spec_code' => 'A105',
        ]);
        $this->spec($item, ['construction_standard' => 'API 6D']);

        self::assertNotContains(
            ManualValves::BODY_FORM_AGAINST_STANDARD,
            $this->codes($this->findings($item)),
        );
    }

    // ------------------------------------------------------------- the trim

    public function test_a_gate_trim_on_a_butterfly_valve_is_caught(): void
    {
        // The trap the trim group was built to stop: API 600's chart is quoted
        // for every valve in the industry, and Trim 8 names a backseat a
        // butterfly valve does not have.
        $item = $this->row(['qualifier' => 'BUTTERFLY']);
        $this->spec($item, [
            'construction_standard' => 'API 609',
            'trim_standard' => 'API600',
            'trim_code' => '8',
            'operator' => 'GEAR',
        ]);

        $findings = $this->findings($item);

        self::assertContains(ManualValves::TRIM_NOT_FOR_VALVE_TYPE, $this->codes($findings));
        // The message must name the chart that DOES apply, or the reader has
        // to go and look it up.
        self::assertStringContainsString('API609', array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::TRIM_NOT_FOR_VALVE_TYPE,
        ))[0]['message']);
    }

    public function test_a_trim_the_library_does_not_hold_is_flagged(): void
    {
        $item = $this->row();
        $this->spec($item, ['trim_standard' => 'API600', 'trim_code' => '99']);

        self::assertContains(
            ManualValves::TRIM_UNKNOWN,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_trim_code_cannot_be_stored_without_its_standard(): void
    {
        // 'Trim 8' means nothing until you know whose chart it is: both API 600
        // and API 623 have an entry numbered 8 and they are different valves.
        $item = $this->row();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->spec($item, ['trim_standard' => null, 'trim_code' => '8']);
    }

    public function test_a_valve_type_with_no_trim_chart_quoting_a_trim_is_flagged(): void
    {
        // NEEDLE and DIAPHRAGM have no chart in the material library and no
        // construction standard in the new pack. Two packs agreeing on a gap
        // is worth more than either one filling it.
        $item = $this->row(['qualifier' => 'NEEDLE']);
        $this->spec($item, [
            'construction_standard' => null,
            'trim_standard' => 'API600',
            'trim_code' => '8',
        ]);

        self::assertContains(
            ManualValves::TRIM_NOT_CHARTED,
            $this->codes($this->findings($item)),
        );
    }

    public function test_an_absent_trim_is_incomplete(): void
    {
        $item = $this->row();
        $this->spec($item, ['trim_standard' => null, 'trim_code' => null]);

        $findings = $this->findings($item);
        $trim = array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::TRIM_ABSENT,
        ));

        self::assertCount(1, $trim);
        self::assertSame(ManualValves::SEVERITY_INCOMPLETE, $trim[0]['severity']);
    }

    // --------------------------------------------------------- the operator

    public function test_a_lever_cannot_drive_a_gate_valve(): void
    {
        // Travel, from actuators.ref.json: a gate valve is MULTI_TURN and a
        // lever is QUARTER_TURN. This is mechanical, not a preference.
        $item = $this->row();
        $this->spec($item, ['operator' => 'LEVER']);

        $findings = $this->findings($item);

        self::assertContains(ManualValves::OPERATOR_CANNOT_DRIVE, $this->codes($findings));
        // The message must say what CAN drive it. A refusal without an
        // alternative sends the transcriber back to the catalogue.
        self::assertStringContainsString('HANDWHEEL', array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::OPERATOR_CANNOT_DRIVE,
        ))[0]['message']);
    }

    public function test_a_handwheel_cannot_drive_a_ball_valve(): void
    {
        // The mirror case. A quarter-turn valve takes a lever or a gearbox.
        // B16.10 offers a Class 150 ball valve long or short, so a row that
        // wants silence has to say which - as a real PMS line does. Since the
        // API 608 round it must also say FLOATING or TRUNNION: a soft seat is
        // rated differently on the two designs, so the row has no envelope
        // without it.
        $item = $this->row([
            'qualifier' => 'BALL',
            'description_as_written' => 'BALL VALVE, FLOATING, LONG PATTERN, FULL BORE',
        ]);
        $this->spec($item, [
            'construction_standard' => 'API 608',
            'trim_standard' => 'API608',
            'trim_code' => 'PTFE',
            'operator' => 'HANDWHEEL',
        ]);

        self::assertContains(
            ManualValves::OPERATOR_CANNOT_DRIVE,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_gear_operator_drives_either_travel(): void
    {
        // A gearbox is the one manual operator on both travels, which is why
        // it is the answer to every large valve.
        // B16.10 offers a Class 150 ball valve long or short, so a row that
        // wants silence has to say which - as a real PMS line does. Since the
        // API 608 round it must also say FLOATING or TRUNNION: a soft seat is
        // rated differently on the two designs, so the row has no envelope
        // without it.
        $item = $this->row([
            'qualifier' => 'BALL',
            'description_as_written' => 'BALL VALVE, FLOATING, LONG PATTERN, FULL BORE',
        ]);
        $this->spec($item, [
            'construction_standard' => 'API 608',
            'trim_standard' => 'API608',
            'trim_code' => 'PTFE',
            'operator' => 'GEAR',
        ]);

        self::assertSame([], $this->findings($item));
    }

    public function test_a_check_valve_takes_no_operator(): void
    {
        // It is opened by the flow. The pack answers this by holding no travel
        // for CHECK, so the absence falls out of the data rather than being a
        // special case in code.
        $item = $this->row(['qualifier' => 'CHECK']);
        $this->spec($item, [
            'construction_standard' => 'BS 1868',
            'trim_standard' => 'API594',
            'trim_code' => '13CR',
            'operator' => null,
        ]);

        $codes = $this->codes($this->findings($item));

        self::assertNotContains(ManualValves::OPERATOR_ABSENT, $codes);
        self::assertNotContains(ManualValves::OPERATOR_CANNOT_DRIVE, $codes);
    }

    public function test_a_check_valve_given_an_operator_is_a_discrepancy(): void
    {
        $item = $this->row(['qualifier' => 'CHECK']);
        $this->spec($item, [
            'construction_standard' => 'BS 1868',
            'trim_standard' => 'API594',
            'trim_code' => '13CR',
            'operator' => 'HANDWHEEL',
        ]);

        self::assertContains(
            ManualValves::OPERATOR_NOT_APPLICABLE,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_chainwheel_is_exempt_from_the_travel_test(): void
    {
        // A chainwheel is not a way of making torque, it is a way of REACHING
        // a valve mounted out of arm's length. It hangs off whatever operator
        // is underneath, so it makes no travel claim of its own.
        $item = $this->row();
        $this->spec($item, ['operator' => 'CHAINWHEEL']);

        self::assertSame([], $this->findings($item));
    }

    public function test_an_absent_operator_on_a_hand_worked_valve_is_incomplete(): void
    {
        $item = $this->row();
        $this->spec($item, ['operator' => null]);

        $findings = $this->findings($item);
        $op = array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::OPERATOR_ABSENT,
        ));

        self::assertCount(1, $op);
        self::assertSame(ManualValves::SEVERITY_INCOMPLETE, $op[0]['severity']);
    }

    // ------------------------------------ the class judged against itself

    public function test_a_bigger_valve_with_a_lighter_operator_is_caught(): void
    {
        // No threshold is hardcoded anywhere - "gearbox above NPS 8" is
        // project specification and differs everywhere. This compares the
        // class with itself: if the 6 inch gate valve is geared, the 12 inch
        // one cannot be a bare handwheel.
        $small = $this->row(['dn' => 150, 'nps' => '6']);
        $this->spec($small, ['operator' => 'GEAR']);

        $large = $this->row(['dn' => 300, 'nps' => '12']);
        $this->spec($large, ['operator' => 'HANDWHEEL']);

        $out = $this->tenant(fn (): array => $this->valves()->operatorConsistency($this->class));

        self::assertCount(1, $out);
        self::assertSame(ManualValves::OPERATOR_LIGHTER_AT_LARGER_SIZE, $out[0]['code']);
        self::assertSame((string) $large->id, $out[0]['item']);
    }

    public function test_effort_rising_with_size_is_correct(): void
    {
        // The ordinary sheet: handwheel small, gearbox large. The rule must be
        // silent here or it is a rule about the transcriber, not the valve.
        $small = $this->row(['dn' => 150, 'nps' => '6']);
        $this->spec($small, ['operator' => 'HANDWHEEL']);

        $large = $this->row(['dn' => 300, 'nps' => '12']);
        $this->spec($large, ['operator' => 'GEAR']);

        self::assertSame([], $this->tenant(
            fn (): array => $this->valves()->operatorConsistency($this->class)
        ));
    }

    public function test_two_valve_types_are_compared_separately(): void
    {
        // A geared 6 inch gate valve says nothing about a levered 8 inch ball
        // valve: different travel, different torque, different comparison.
        $gate = $this->row(['dn' => 150, 'nps' => '6']);
        $this->spec($gate, ['operator' => 'GEAR']);

        $ball = $this->row([
            'dn' => 200, 'nps' => '8', 'qualifier' => 'BALL',
        ]);
        $this->spec($ball, [
            'construction_standard' => 'API 608',
            'trim_standard' => 'API608',
            'trim_code' => 'PTFE',
            'operator' => 'LEVER',
        ]);

        self::assertSame([], $this->tenant(
            fn (): array => $this->valves()->operatorConsistency($this->class)
        ));
    }

    // ------------------------------------------------ the seam into the body

    public function test_the_body_reports_valve_findings_as_its_own(): void
    {
        // Everything that reads the body - isConsumable, the future
        // transcription screen, MTO - must see valve findings without knowing
        // that valves are a special case.
        $item = $this->row();
        $this->spec($item, ['operator' => 'LEVER']);

        $codes = $this->codes($this->tenant(fn (): array => app(PipingClassBody::class)->findingsForItem($item)));

        self::assertContains(ManualValves::OPERATOR_CANNOT_DRIVE, $codes);
    }

    public function test_a_class_with_a_contradictory_operator_ladder_is_not_consumable(): void
    {
        $small = $this->row(['dn' => 150, 'nps' => '6']);
        $this->spec($small, ['operator' => 'GEAR']);

        $large = $this->row(['dn' => 300, 'nps' => '12']);
        $this->spec($large, ['operator' => 'HANDWHEEL']);

        self::assertFalse($this->tenant(
            fn (): bool => app(PipingClassBody::class)->isConsumable($this->class)
        ));
    }

    // ------------------------------------- the rating, from the valve standard

    public function test_a_class_400_valve_is_caught(): void
    {
        // The most common specification error there is. ASME B16.5 rates a
        // Class 400 FLANGE; ASME B16.34 does not rate a Class 400 valve at all.
        // Until B16.34 was transcribed the platform could not tell, because it
        // only held flange tables.
        $item = $this->row(['pressure_rating' => '400#']);
        $this->spec($item);

        $findings = $this->findings($item);

        self::assertContains(ManualValves::RATING_NOT_A_VALVE_CLASS, $this->codes($findings));
        self::assertStringContainsString('B16.34', array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::RATING_NOT_A_VALVE_CLASS,
        ))[0]['message']);
    }

    public function test_class_4500_is_accepted_though_no_flange_standard_has_it(): void
    {
        $item = $this->row(['pressure_rating' => '4500#']);
        $this->spec($item);

        self::assertNotContains(
            ManualValves::RATING_NOT_A_VALVE_CLASS,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_row_with_no_pressure_class_is_incomplete(): void
    {
        $item = $this->row(['pressure_rating' => null]);
        $this->spec($item);

        $findings = $this->findings($item);
        $r = array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::RATING_ABSENT,
        ));

        self::assertCount(1, $r);
        self::assertSame(ManualValves::SEVERITY_INCOMPLETE, $r[0]['severity']);
    }

    public function test_a_body_material_b1634_does_not_list_is_reported(): void
    {
        // B16.34 names the materials a valve body may be made of. A spec absent
        // from all 50 groups has no rating curve, so the valve has no envelope.
        $item = $this->row([
            'material_group_code' => 'CASTINGS',
            'material_spec_code' => 'A126',
        ]);
        $this->spec($item);

        self::assertContains(
            ManualValves::BODY_NOT_IN_B1634,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_design_temperature_past_the_end_of_the_curve_is_caught(): void
    {
        // Group 1.1 (A216 WCB) stops at 538 C. A class designed to 600 C cannot
        // use this body material in ANY pressure class, and extrapolating the
        // curve is how a valve gets bought for a service it cannot hold.
        $this->tenant(fn () => $this->class->update(['design_temp_max_c' => 600]));

        $item = $this->row();
        $this->spec($item);

        self::assertContains(
            ManualValves::DESIGN_TEMPERATURE_ABOVE_THE_CURVE,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_material_note_ceiling_is_surfaced_next_to_the_rating(): void
    {
        // "Not to be used over 371 C" sits under a table that keeps printing
        // numbers above 371 C. A522 ... A672 Gr. C70 carries exactly that note
        // in group 1.1, and a reader who sees only the grid over-rates it.
        $this->tenant(fn () => $this->class->update(['design_temp_max_c' => 450]));

        $item = $this->row([
            'material_group_code' => 'PIPE',
            'material_spec_code' => 'A672',
            'material_grade_key' => 'C70',
        ]);
        $this->spec($item);

        self::assertContains(
            ManualValves::MATERIAL_NOTE_LIMITS_TEMPERATURE,
            $this->codes($this->findings($item)),
        );
    }

    public function test_an_ordinary_class_and_temperature_raise_nothing(): void
    {
        // A216 WCB, Class 150, designed to 200 C. The commonest valve line in
        // any carbon steel class, and the validator must stay silent on it.
        $this->tenant(fn () => $this->class->update(['design_temp_max_c' => 200]));

        $item = $this->row();
        $this->spec($item);

        self::assertSame([], $this->findings($item));
    }

    // -------- ISO 15761: a second pressure system, and the last collapse

    public function test_the_fifth_and_final_product_form_claim_collapses(): void
    {
        // ISO 15761 was the last verified entry still claiming one product
        // form - FORGINGS, on the reasoning that it is the compact forged
        // valve. Clause 5.4.4.2: "Body end flanges and bonnet flanges shall be
        // CAST OR FORGED integral with the body." Five for five.
        $c = app(\App\Support\Reference\ValveConstruction::class);

        self::assertNull($c->bodyGroups('ISO 15761'));
        self::assertTrue($c->scopeIsCitable('ISO 15761'));

        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'ISO 15761');
        self::assertSame(['FORGINGS'], $entry['correctedFromGuess']['bodyGroups']['was']);
    }

    public function test_iso_15761_rates_valves_in_a_system_the_platform_does_not_have(): void
    {
        // It rates the same valve in ASME Class AND in PN - 16, 25, 40, 63,
        // 100, 250, 400. Every pack here keys pressure on an ASME class
        // number, so a European sheet writing PN 40 has nowhere to go. And
        // the mapping is not one to one: clause 1 says Class 800 "has no
        // equivalent PN designation".
        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'ISO 15761');

        self::assertSame(['16', '25', '40', '63', '100', '250', '400'], $entry['pnDesignations']);
        self::assertContains('800', $entry['pressureClasses']);
        self::assertStringContainsString(
            'no equivalent PN designation',
            $entry['pnDesignationsNote']['en'],
        );

        // And nothing in the rating packs knows PN.
        self::assertFalse(app(\App\Support\Reference\ValvePtRatings::class)
            ->tabulatesPressureClass('40'));
    }

    public function test_two_standards_disagree_about_free_machining_13cr(): void
    {
        // API 600 Table 8 note (b) PROHIBITS free-machining 13Cr outright.
        // ISO 15761 clause 6.1.2 permits it when the purchaser specifies it,
        // under the trim number plus 100. Same material, same valve family,
        // two answers - which is why a PMS has to say which document governs.
        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'ISO 15761');

        self::assertStringContainsString(
            'API 600 simply PROHIBITS',
            $entry['trimVocabulary']['freeMachiningRule'],
        );
        self::assertSame('CN', $entry['trimVocabulary']['system']);
        self::assertSame('API602', $entry['trimVocabulary']['sameAs']);
    }

    // ---------------- API 6D: a specification, and a type we do not have

    public function test_api_6d_covers_a_valve_type_nothing_else_here_knows(): void
    {
        // The 25th edition added AXIAL valves. No component template qualifier,
        // no actuator travel, no trim chart and no face-to-face row outside
        // 6D's own Annex C knows the type. Declared rather than quietly added:
        // widening the VALVE template changes the PMS body, the MTO and every
        // dropdown that reads it, and that is a decision to take on purpose.
        $c = app(\App\Support\Reference\ValveConstruction::class);

        self::assertContains('AXIAL', $c->get('API 6D')['valveTypes']);
        self::assertSame([], $c->forValveType('AXIAL') === [] ? [] : []);

        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'API 6D');
        self::assertSame(['AXIAL'], $entry['valveTypesBeyondTemplate']['types']);
    }

    public function test_class_400_is_deleted_by_a_third_standard(): void
    {
        // ASME B16.34 never tabulated it, API 602 omits it, and API 6D's 25th
        // edition removed it outright - clause 1 NOTE: "ASME Class 400 has
        // been removed from this specification." Three independent documents
        // now say a Class 400 VALVE is not a thing, where a Class 400 FLANGE
        // is ordinary.
        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'API 6D');

        self::assertSame(['400'], $entry['classesExplicitlyRemoved']['classes']);
        self::assertNotContains('400', $entry['pressureClasses']);

        // The rating pack has said so all along.
        self::assertFalse(app(\App\Support\Reference\ValvePtRatings::class)
            ->tabulatesPressureClass('400'));
        // And the flange pack still rates the flange.
        self::assertNotNull(app(\App\Support\Reference\FlangePtRatings::class)
            ->ratingAt('B16.5', '1.1', '400', 38.0));
    }

    public function test_api_6d_is_a_specification_and_carries_its_own_dimensions(): void
    {
        // API 600 covers a valve type; API 6D covers design, manufacture, QC,
        // testing and documentation in one document - and its Annex C
        // face-to-face tables are NORMATIVE, so naming it on a PMS row picks a
        // dimension source as well as a quality regime.
        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'API 6D');

        self::assertSame('SPECIFICATION', $entry['documentKind']);
        self::assertStringContainsString('NORMATIVE', $entry['faceToFaceSource']);
        self::assertContains('HYDROGEN_GAS', $entry['coversService']);
    }

    // ------------ API 599: not every standard numbers its trim

    public function test_api_599_numbers_no_trims_at_all(): void
    {
        // API 600, 623 and 594 each print a numbered trim table. API 599
        // prints NONE - clause 6 gives the shell from ASME B16.34 or a ductile
        // iron from B16.42 and leaves seat, sleeve and plug facing to the
        // purchase order. The two codes the library held for plug valves,
        // PTFE-SLV and MTL, were a vocabulary for a chart that does not exist.
        self::assertNull($this->materials()->trimStandardFor('PLUG'));
        self::assertSame([], $this->materials()->trimsFor('PLUG', true));
    }

    public function test_a_plug_valve_is_not_asked_for_a_trim_number(): void
    {
        // Demanding one asks for something the standard has not got. A row
        // with no trim is complete for a plug valve and incomplete for a gate.
        $plug = $this->row(['qualifier' => 'PLUG', 'nps' => '2', 'dn' => 50]);
        $this->spec($plug, [
            'construction_standard' => 'API 599',
            'trim_standard' => null, 'trim_code' => null, 'operator' => 'LEVER',
        ]);
        self::assertNotContains(ManualValves::TRIM_ABSENT, $this->codes($this->findings($plug)));

        $gate = $this->row(['qualifier' => 'GATE']);
        $this->spec($gate, [
            'construction_standard' => 'API 600',
            'trim_standard' => null, 'trim_code' => null,
        ]);
        self::assertContains(ManualValves::TRIM_ABSENT, $this->codes($this->findings($gate)));
    }

    public function test_api_599_supplies_the_third_variant_axis_api_598_needed(): void
    {
        // Note (f) of API 598 Table 1: "for lubricated plug valves, the
        // high-pressure closure test is mandatory and the low-pressure closure
        // test is optional." Until now that was prose. API 599 clause 1.2
        // names the two: lubricated and non-lubricated.
        self::assertSame(
            ['LUBRICATED', 'NON_LUBRICATED'],
            app(\App\Support\Reference\ValveConstruction::class)->designVariants('API 599'),
        );

        $notes = app(\App\Support\Reference\ValveTesting::class)->notes();
        self::assertStringContainsString('lubricated plug valves', $notes['f']);
    }

    public function test_three_way_plug_valves_are_outside_api_599(): void
    {
        // Clause 1.2 NOTE. Worth holding because THREE_WAY_VALVE is on the
        // deferred list, and this says which standard will NOT cover it.
        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'API 599');

        self::assertStringContainsString('Three-way and four-way', $entry['excludes']);
        self::assertStringContainsString('Tandem', $entry['excludes']);
    }

    // -------------- API 594: two types, two dimension sources

    public function test_the_check_valve_trim_chart_was_invented_and_is_now_read(): void
    {
        // The spec held FOUR codes - 13CR, STL, 316, MONEL - that appear in no
        // standard. API 594 numbers its trims like every other API valve
        // standard: Table 4 gives twelve, including 5A and the bronze AA.
        $codes = array_column($this->materials()->trimsFor('CHECK', true), 'code');

        self::assertCount(12, $codes);
        self::assertSame(
            ['1', '2', '5', '5A', '8', '9', '10', '12', '13', '14', '16', 'AA'],
            $codes,
        );
        foreach (['13CR', 'STL', 'MONEL'] as $invented) {
            self::assertNotContains($invented, $codes, "{$invented} was never a trim number");
        }
    }

    public function test_a_check_valve_has_a_hinge_pin_and_no_stem(): void
    {
        // Table 4's columns are Seat Surface and Hinge Pin. A check valve is
        // opened by the flow, so it has no stem and no backseat - two parts,
        // not the four a gate valve chart would impose.
        self::assertSame(
            ['seatingSurface', 'hingePin'],
            $this->materials()->trimPartsFor('CHECK'),
        );
    }

    public function test_api_594_states_no_seat_hardness_at_all(): void
    {
        // API 600 and API 623 both give a hardness column; API 594 does not -
        // the words hardness, HB and Brinell appear nowhere in it. Borrowing a
        // figure from the gate chart would be an inspection criterion nobody
        // wrote, so the absence is declared instead.
        self::assertNull($this->materials()->minSeatHardness('5', 'API594'));
        self::assertSame(350, $this->materials()->minSeatHardness('5', 'API600'));
    }

    public function test_the_two_check_valve_types_take_their_length_from_different_standards(): void
    {
        // THE SHARP ONE. API 594 clause 1: a Type A check valve is dimensioned
        // by API 594 Tables 2 and 3; a Type B by ASME B16.10. The platform
        // holds B16.10 and NOT API 594 Tables 2/3, so a Type A valve currently
        // resolves to a Type B length. Recorded as a known gap rather than
        // left to be discovered when a spool will not close.
        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'API 594');

        $a = collect($entry['variantScopes'])->firstWhere('variant', 'TYPE_A');
        $b = collect($entry['variantScopes'])->firstWhere('variant', 'TYPE_B');

        self::assertSame('API 594 Tables 2 and 3', $a['faceToFaceSource']);
        self::assertSame('ASME B16.10', $b['faceToFaceSource']);
        self::assertStringContainsString(
            'Type A check valve currently resolves to a Type B length',
            $entry['tablesNotTranscribed']['reason'],
        );
    }

    public function test_check_valve_classes_come_from_three_flange_standards(): void
    {
        // Classes 125 and 250 are GRAY IRON classes from ASME B16.1; 150/300
        // for ductile iron come from B16.42; only the steel classes are
        // B16.34. A consumer that resolves every class through B16.34 finds no
        // curve for 125 or 250 - and must not invent one.
        $entry = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'API 594');

        self::assertSame(['125', '250'], $entry['pressureClassSources']['B16.1']);
        self::assertNotContains('125', $entry['pressureClassSources']['B16.34']);

        // And B16.34 genuinely has nothing for them.
        self::assertFalse(app(\App\Support\Reference\ValvePtRatings::class)
            ->tabulatesPressureClass('125'));
    }

    // ---------------- API 623: a substitution table belongs to its standard

    public function test_the_globe_trim_chart_is_no_longer_nine_of_twenty_four(): void
    {
        // The API 623 spec held NINE trims off an industry chart where the
        // standard's Table 8 has TWENTY-FOUR - the API 600 set without 5B and
        // without the 20/20A/20B/20C/21 nickel family. Fifteen perfectly
        // ordinary globe trims could not be recorded.
        $codes = array_column($this->materials()->trimsFor('GLOBE', true), 'code');

        self::assertCount(24, $codes);
        foreach (['5A', '8A', '19', '19A', '19B', '19C'] as $missing) {
            self::assertContains($missing, $codes, "globe trim {$missing} is missing");
        }
        self::assertNotContains('5B', $codes);
        self::assertNotContains('21', $codes);
    }

    public function test_trim_1_is_obsolete_in_the_globe_standard_too(): void
    {
        // API 623-2021 Table 8 prints "TRIM NUMBER 1 IS OBSOLETE" across the
        // row, exactly as API 600 does.
        self::assertTrue($this->materials()->trimIsObsolete('API623', '1'));
        self::assertNotContains('1', array_column($this->materials()->trimsFor('GLOBE'), 'code'));
    }

    public function test_a_substitution_table_belongs_to_its_standard_not_to_the_trim(): void
    {
        // THE PAIR THAT PROVES IT. Trim 8 and trim 2 exist in both charts, and
        // the two standards substitute them differently: API 600 Table 9 lets
        // trim 8 be supplied as trim 5 and says nothing about trim 2; API 623
        // Table 9 drops that and adds 2 to 10. A trim number does not carry
        // its own alternatives - its standard does.
        self::assertSame(['5'], $this->materials()->trimAlternatives('API600', '8'));
        self::assertSame([], $this->materials()->trimAlternatives('API623', '8'));

        self::assertSame([], $this->materials()->trimAlternatives('API600', '2'));
        self::assertSame(['10'], $this->materials()->trimAlternatives('API623', '2'));

        // And where they agree, they agree.
        self::assertSame(['12', '16'], $this->materials()->trimAlternatives('API600', '10'));
        self::assertSame(['12', '16'], $this->materials()->trimAlternatives('API623', '10'));
    }

    public function test_the_globe_trim_materials_are_inherited_and_say_so(): void
    {
        // The codes and the substitutions were read from API 623. The MATERIAL
        // behind each code was carried over from the verified API 600 chart
        // and the two tables have not been compared cell by cell, so it stays
        // unverified. Claiming otherwise would be a citation nobody made.
        $lib = $this->materials();

        self::assertSame([], $lib->verifiedProperties('VALVE_TRIM', 'API623', '8'));
        self::assertNotSame([], $lib->verifiedProperties('VALVE_TRIM', 'API600', '8'));
    }

    public function test_api_623_restricts_nothing_where_api_603_restricts_everything(): void
    {
        // The contrast is the point. Both are the same valve family; API 623
        // Table 7 takes the body from B16.34 Groups 1, 2 AND 3, while API 603
        // Table 4 allows only 2 and 3. That limit is what the word
        // "corrosion-resistant" in the API 603 title actually buys.
        $c = app(\App\Support\Reference\ValveConstruction::class);

        self::assertSame(['1', '2', '3'], $c->bodyMaterialGroups('API 623'));
        self::assertSame(['2', '3'], $c->bodyMaterialGroups('API 603'));
        self::assertTrue($c->scopeIsCitable('API 623'));
    }

    // ------------------- API 609: the valve with no ASME class

    public function test_api_609_supplies_the_other_half_of_the_api_598_vocabulary(): void
    {
        // API 598 note (h) says "Butterfly Category A and Category B
        // definition according to API 609". Both halves of the gap that round
        // opened are now closed from the construction standards: API 608 for
        // the ball designs, API 609 for the butterfly categories.
        $construction = app(\App\Support\Reference\ValveConstruction::class)
            ->designVariants('API 609');
        $testing = app(\App\Support\Reference\ValveTesting::class)
            ->variantsFor('BUTTERFLY');

        self::assertSame(['CATEGORY_A', 'CATEGORY_B'], $construction);
        self::assertSame($construction, $testing);
    }

    public function test_api_609_is_the_widest_material_scope_and_claims_no_product_form(): void
    {
        // Clause 1.1 covers gray iron, ductile iron, bronze, steel,
        // nickel-based and special alloys. Unlike API 600, 602 and 603 - whose
        // single-product-form claims all collapsed on reading - this entry
        // never claimed one, so nothing was corrected here. Recorded because
        // the tempting story was "a fourth collapse", and it would have been
        // a win invented rather than found.
        $c = app(\App\Support\Reference\ValveConstruction::class);

        self::assertNull($c->bodyGroups('API 609'));
        self::assertTrue($c->scopeIsCitable('API 609'));
    }

    // ------------------- API 608: a valve is not its shell

    public function test_a_soft_seated_ball_valve_is_rated_by_its_seat(): void
    {
        // THE BIGGEST OVER-STATEMENT THIS PLATFORM COULD MAKE. API 608 clause
        // 4.1: the valve rating is the LESSER of the shell rating and the seat
        // rating. A Class 600 group 1.1 shell is 102 bar at 38 C; the same
        // valve with a PTFE seat above DN 100 is 19.7 bar. Until API 608 was
        // read, the platform reported 102.
        $this->tenant(fn () => $this->class->update(['design_temp_max_c' => 38]));

        $item = $this->row([
            'qualifier' => 'BALL',
            'nps' => '6', 'dn' => 150,
            'pressure_rating' => '600#',
            'description_as_written' => 'BALL VALVE, FLOATING, SOFT SEATED, LONG PATTERN',
        ]);
        $this->spec($item, [
            'construction_standard' => 'API 608',
            'trim_standard' => 'API608', 'trim_code' => 'PTFE',
            'operator' => 'LEVER',
        ]);

        $findings = $this->findings($item);
        self::assertContains(ManualValves::SEAT_GOVERNS_THE_RATING, $this->codes($findings));

        $message = array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::SEAT_GOVERNS_THE_RATING,
        ))[0]['message'];
        self::assertStringContainsString('19.7', $message);
        self::assertStringContainsString('102', $message);
    }

    public function test_a_small_soft_seated_ball_valve_is_not_flagged(): void
    {
        // At DN 50 the PTFE seat holds 69 bar. Against a Class 300 shell
        // (51 bar) the SHELL still governs, so there is nothing to report -
        // the finding fires on the gap, not on the presence of a soft seat.
        $this->tenant(fn () => $this->class->update(['design_temp_max_c' => 38]));

        $item = $this->row([
            'qualifier' => 'BALL',
            'nps' => '2', 'dn' => 50,
            'pressure_rating' => '300#',
            'description_as_written' => 'BALL VALVE, FLOATING, FULL BORE',
        ]);
        $this->spec($item, [
            'construction_standard' => 'API 608',
            'trim_standard' => 'API608', 'trim_code' => 'PTFE',
            'operator' => 'LEVER',
        ]);

        self::assertNotContains(
            ManualValves::SEAT_GOVERNS_THE_RATING,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_ball_valve_that_does_not_say_floating_or_trunnion_is_incomplete(): void
    {
        // The gap API 598 opened and API 608 names. A soft seat is rated
        // differently on the two designs, so the row has no envelope until it
        // says which - and API 598 cannot pick its tests either.
        $item = $this->row([
            'qualifier' => 'BALL',
            'nps' => '6', 'dn' => 150,
            'pressure_rating' => '600#',
            'description_as_written' => 'BALL VALVE, SOFT SEATED',
        ]);
        $this->spec($item, [
            'construction_standard' => 'API 608',
            'trim_standard' => 'API608', 'trim_code' => 'PTFE',
            'operator' => 'LEVER',
        ]);

        $findings = $this->findings($item);
        self::assertContains(ManualValves::BALL_DESIGN_NOT_STATED, $this->codes($findings));

        $message = array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::BALL_DESIGN_NOT_STATED,
        ))[0]['message'];
        self::assertStringContainsString('FLOATING', $message);
        self::assertStringContainsString('TRUNNION_MOUNTED', $message);
    }

    public function test_api_608_supplies_the_vocabulary_api_598_was_missing(): void
    {
        // The arc closing. ValveTesting refuses a bare BALL with
        // VARIANT_REQUIRED and names FLOATING and TRUNNION_MOUNTED; API 608
        // clause 1.4 is where those two names come from, and the construction
        // pack now carries them.
        $construction = app(\App\Support\Reference\ValveConstruction::class)
            ->designVariants('API 608');
        $testing = app(\App\Support\Reference\ValveTesting::class)
            ->variantsFor('BALL');

        self::assertSame(['FLOATING', 'TRUNNION_MOUNTED'], $construction);
        self::assertSame($construction, $testing);
    }

    public function test_api_608_scope_depends_on_the_end_type(): void
    {
        // Clause 1.1 and 1.2: flanged and butt-welding run NPS 1/2 to 24,
        // socket-welding and threaded stop at NPS 2; and flanged stops at
        // Class 600 while the other three reach Class 800. One npsMin/npsMax
        // pair cannot say that.
        $c = app(\App\Support\Reference\ValveConstruction::class);
        $entry = $c->get('API 608');

        self::assertTrue($c->scopeIsCitable('API 608'));
        self::assertTrue($c->coversNps('API 608', 24.0));
        self::assertFalse($c->coversNps('API 608', 36.0));

        $scopes = collect(json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/valve-construction.ref.json'),
        ), true)['standards'])->firstWhere('code', 'API 608')['endTypeScopes'];

        $flanged = collect($scopes)->firstWhere('endType', 'FLANGED');
        $threaded = collect($scopes)->firstWhere('endType', 'THREADED');

        self::assertSame(24, $flanged['npsMax']);
        self::assertSame(2, $threaded['npsMax']);
        self::assertNotContains('800', $flanged['pressureClasses']);
        self::assertContains('800', $threaded['pressureClasses']);
    }

    // ------------------------ API 603: the constraint that is not product form

    public function test_a_carbon_steel_body_is_outside_api_603(): void
    {
        // THE SHARPER CHECK. API 603 does not care whether the body was cast
        // or forged - clause 7.3 says "cast or forged" like the other two.
        // Table 4 restricts it to ASME B16.34 Group 2 and Group 3, which is
        // what "corrosion-resistant" in its title means. A216 WCB resolves to
        // group 1.1, so it is outside API 603 however it was made.
        $item = $this->row([
            'qualifier' => 'GATE',
            'material_group_code' => 'CASTINGS',
            'material_spec_code' => 'A216',
            'material_grade_key' => 'WCB',
        ]);
        $this->spec($item, ['construction_standard' => 'API 603']);

        $findings = $this->findings($item);
        self::assertContains(
            ManualValves::BODY_MATERIAL_GROUP_AGAINST_STANDARD,
            $this->codes($findings),
        );

        $message = array_values(array_filter(
            $findings,
            fn (array $f): bool => $f['code'] === ManualValves::BODY_MATERIAL_GROUP_AGAINST_STANDARD,
        ))[0]['message'];
        self::assertStringContainsString('1.1', $message);
        self::assertStringContainsString('group 2 and 3', strtolower($message));
    }

    public function test_a_stainless_body_is_ordinary_under_api_603(): void
    {
        // A351 CF8M is group 2.2 - exactly what the standard is for.
        $item = $this->row([
            'qualifier' => 'GATE',
            'material_group_code' => 'CASTINGS',
            'material_spec_code' => 'A351',
            'material_grade_key' => 'CF8M',
        ]);
        $this->spec($item, ['construction_standard' => 'API 603']);

        $codes = $this->codes($this->findings($item));
        self::assertNotContains(ManualValves::BODY_MATERIAL_GROUP_AGAINST_STANDARD, $codes);
        self::assertNotContains(ManualValves::BODY_FORM_AGAINST_STANDARD, $codes);
    }

    public function test_api_603_stops_at_nps_24_where_api_600_runs_to_42(): void
    {
        // The two standards are the same valve cut for different materials,
        // and the corrosion-resistant cut is the smaller one - classes 150 to
        // 600 only, NPS 24 ceiling.
        $c = app(\App\Support\Reference\ValveConstruction::class);

        self::assertTrue($c->coversNps('API 603', 24.0));
        self::assertFalse($c->coversNps('API 603', 36.0));
        self::assertTrue($c->coversNps('API 600', 36.0));
        self::assertTrue($c->scopeIsCitable('API 603'));
    }

    public function test_api_603_names_the_test_standard_it_requires(): void
    {
        // Clause 7.2: "Each valve shall be pressure tested as specified in API
        // 598." The construction pack and the testing pack meet here.
        self::assertSame(
            'API 598',
            app(\App\Support\Reference\ValveConstruction::class)->testedTo('API 603'),
        );
    }

    // ------------------------------- the trim chart, now from Table 8 itself

    public function test_the_gate_trim_chart_is_the_standards_and_not_the_industrys(): void
    {
        // The chart this library grew from listed 20 trims, copied off vendor
        // sites. Table 8 of API 600-2021 has THIRTY - the whole nickel-alloy
        // family 19/19A/19B/19C/20/20A/20B/20C/21 and trim 5B were simply
        // absent, so nine perfectly ordinary selections could not be recorded.
        $codes = array_column($this->materials()->trimsFor('GATE', true), 'code');

        self::assertCount(30, $codes);
        foreach (['5B', '19', '19A', '19B', '19C', '20', '20A', '20B', '20C', '21'] as $new) {
            self::assertContains($new, $codes, "trim {$new} is missing");
        }
    }

    public function test_trim_1_is_withdrawn_and_is_not_offered(): void
    {
        // Table 8 prints "TRIM NUMBER 1 IS OBSOLETE" across the row. Until the
        // standard was read, the platform offered it as an ordinary choice.
        self::assertTrue($this->materials()->trimIsObsolete('API600', '1'));

        self::assertNotContains('1', array_column($this->materials()->trimsFor('GATE'), 'code'));
        self::assertContains('1', array_column($this->materials()->trimsFor('GATE', true), 'code'));
    }

    public function test_a_withdrawn_trim_is_reported_as_withdrawn_not_as_unknown(): void
    {
        // A sheet transcribed from an older revision will say Trim 1. Holding
        // it is what lets the platform say "the standard dropped this" instead
        // of "we have never heard of it" - a different instruction entirely.
        $item = $this->row();
        $this->spec($item, ['trim_standard' => 'API600', 'trim_code' => '1']);

        $codes = $this->codes($this->findings($item));

        self::assertContains(ManualValves::TRIM_WITHDRAWN, $codes);
        self::assertNotContains(ManualValves::TRIM_UNKNOWN, $codes);
    }

    public function test_table_9_says_what_may_be_supplied_instead(): void
    {
        // Substitution runs ONE way: specify trim 10 and the manufacturer may
        // supply 12 or 16. It does not follow that specifying 12 permits 10.
        self::assertSame(['12', '16'], $this->materials()->trimAlternatives('API600', '10'));
        self::assertSame(['5'], $this->materials()->trimAlternatives('API600', '8'));
        self::assertSame([], $this->materials()->trimAlternatives('API600', '16'));
    }

    public function test_a_nickel_alloy_trim_now_resolves(): void
    {
        // Trim 19A - Alloy 625 - is a real selection on any sour or seawater
        // service and could not be recorded at all before this round.
        $item = $this->row();
        $this->spec($item, ['trim_standard' => 'API600', 'trim_code' => '19A']);

        $codes = $this->codes($this->findings($item));

        self::assertNotContains(ManualValves::TRIM_UNKNOWN, $codes);
        self::assertNotContains(ManualValves::TRIM_NOT_CHARTED, $codes);
    }

    // --------------------------------- API 600 arrives and corrects two guesses

    public function test_a_forged_body_on_an_api_600_gate_valve_is_no_longer_a_finding(): void
    {
        // THE FALSE FINDING THIS ROUND KILLED. The unverified guess said API 600
        // meant a CAST body and API 602 was its forged small-bore counterpart.
        // Table D.1 of API 600-2021 gives the Group 1.1 body as "C-Si A105 or
        // A216-WCB" - a forging and a casting in one row - and 7.3 speaks of
        // "the shell of a cast or forged... valve". Every legitimate A105
        // forged gate valve was being flagged.
        $item = $this->row([
            'material_group_code' => 'FORGINGS',
            'material_spec_code' => 'A105',
            'material_grade_key' => null,
        ]);
        $this->spec($item, ['construction_standard' => 'API 600']);

        self::assertNotContains(
            ManualValves::BODY_FORM_AGAINST_STANDARD,
            $this->codes($this->findings($item)),
        );
    }

    public function test_an_nps_1_gate_valve_is_inside_api_600(): void
    {
        // The other corrected guess: we had API 600 starting at NPS 2 on the
        // reasoning that small bore falls to API 602. Clause 1 lists DN 25
        // upward - NPS 1 - so every NPS 1 to 1 1/2 API 600 valve was being put
        // out of scope.
        $item = $this->row(['nps' => '1', 'dn' => 25, 'end_type' => 'BW']);
        $this->spec($item, ['construction_standard' => 'API 600']);

        self::assertNotContains(
            ManualValves::CONSTRUCTION_STANDARD_OUT_OF_SCOPE,
            $this->codes($this->findings($item)),
        );
    }

    public function test_an_nps_48_gate_valve_is_outside_api_600(): void
    {
        // And the third: the guess left the upper bound open. API 600 stops at
        // DN 1050 (NPS 42), so an NPS 48 gate valve is genuinely out of scope -
        // a finding the platform could not previously make at all.
        $item = $this->row(['nps' => '48', 'dn' => 1200, 'end_type' => 'BW']);
        $this->spec($item, ['construction_standard' => 'API 600']);

        self::assertContains(
            ManualValves::CONSTRUCTION_STANDARD_OUT_OF_SCOPE,
            $this->codes($this->findings($item)),
        );
    }

    public function test_the_api_600_scope_message_no_longer_disclaims_itself(): void
    {
        // Out-of-scope messages used to end "scope read from the standard
        // title, not from a purchased copy". API 600 is now held, so that
        // sentence must be gone from ITS findings - and still present on the
        // twelve standards nobody has bought.
        $item = $this->row(['nps' => '48', 'dn' => 1200, 'end_type' => 'BW']);
        $this->spec($item, ['construction_standard' => 'API 600']);

        $message = array_values(array_filter(
            $this->findings($item),
            fn (array $f): bool => $f['code'] === ManualValves::CONSTRUCTION_STANDARD_OUT_OF_SCOPE,
        ))[0]['message'];

        self::assertStringNotContainsString('not from a purchased copy', $message);
    }

    // ------------------------------- the length, from the dimensional standard

    public function test_a_valve_with_two_patterns_and_no_choice_is_incomplete(): void
    {
        // ASME B16.10 offers a Class 600 gate valve at 559 mm long pattern or
        // 457 mm short. A PMS row that names neither has not specified the
        // valve, and nothing downstream can tell - both figures are valid.
        $item = $this->row([
            'pressure_rating' => '600#', 'nps' => '6', 'dn' => 150,
            'end_type' => 'BW',
        ]);
        $this->spec($item, ['construction_standard' => 'API 600']);

        $findings = $this->findings($item);

        self::assertContains(ManualValves::PATTERN_NOT_STATED, $this->codes($findings));

        $f = array_values(array_filter(
            $findings,
            fn (array $x): bool => $x['code'] === ManualValves::PATTERN_NOT_STATED,
        ))[0];
        self::assertSame(ManualValves::SEVERITY_INCOMPLETE, $f['severity']);
        self::assertStringContainsString('559', $f['message']);
        self::assertStringContainsString('457', $f['message']);
    }

    public function test_a_class_with_a_rating_but_no_length_is_reported(): void
    {
        // Class 4500 is rated by B16.34 and dimensioned by nothing. A valve can
        // be perfectly rateable and still not be drawable, and the two packs
        // together are what make that sayable.
        $item = $this->row(['pressure_rating' => '4500#']);
        $this->spec($item);

        self::assertContains(
            ManualValves::NO_TABULATED_LENGTH,
            $this->codes($this->findings($item)),
        );
    }

    public function test_citing_b1610_for_a_valve_it_does_not_cover_is_a_discrepancy(): void
    {
        // A needle valve has no B16.10 length. Saying so only matters when the
        // row claims the standard for itself, which this one does.
        $item = $this->row([
            'qualifier' => 'NEEDLE',
            'dimensional_standard' => 'ASME B16.10',
        ]);
        $this->spec($item, ['construction_standard' => null, 'trim_standard' => null,
            'trim_code' => null]);

        self::assertContains(
            ManualValves::DIMENSIONAL_STANDARD_DOES_NOT_COVER_IT,
            $this->codes($this->findings($item)),
        );
    }

    public function test_a_size_outside_the_standard_is_reported(): void
    {
        $item = $this->row(['nps' => '40', 'dn' => 1000, 'pressure_rating' => '600#']);
        $this->spec($item, ['construction_standard' => 'API 600']);

        self::assertContains(
            ManualValves::NO_TABULATED_LENGTH,
            $this->codes($this->findings($item)),
        );
    }

    // ------------------------------------------------------- the dropdowns

    public function test_the_screen_is_offered_only_workable_combinations(): void
    {
        // Filtering at the source is the only honest way to populate three
        // dropdowns: an unfiltered list offers a lever for a 12 inch gate
        // valve and API 600 for a butterfly.
        $choices = $this->valves()->choicesFor('GATE');

        $standards = array_column($choices['constructionStandards'], 'code');
        self::assertContains('API 600', $standards);
        self::assertNotContains('API 609', $standards);

        foreach ($choices['trims'] as $t) {
            self::assertContains('GATE', $t['valveTypes']);
        }

        // CHAINWHEEL rides on top of either, so it is offered for both
        // travels. LEVER is not: a gate valve is multi-turn.
        $operators = array_column($choices['operators'], 'code');
        self::assertSame(['HANDWHEEL', 'GEAR', 'CHAINWHEEL'], $operators);
        self::assertNotContains('LEVER', $operators);
    }

    public function test_the_size_narrows_the_standards_offered(): void
    {
        // API 602 disappears above NPS 4. API 600 runs NPS 1 to 42 - VERIFIED
        // from clause 1 of the 2021 edition, which corrected our guess that it
        // began at NPS 2 and never ended. At NPS 1 the two standards overlap,
        // and that overlap is real: the project chooses.
        $small = array_column($this->valves()->choicesFor('GATE', 1.0)['constructionStandards'], 'code');
        $mid = array_column($this->valves()->choicesFor('GATE', 8.0)['constructionStandards'], 'code');
        $huge = array_column($this->valves()->choicesFor('GATE', 48.0)['constructionStandards'], 'code');

        self::assertContains('API 602', $small);
        self::assertContains('API 600', $small);

        self::assertContains('API 600', $mid);
        self::assertNotContains('API 602', $mid);

        self::assertNotContains('API 600', $huge);
    }

    public function test_a_valve_type_with_no_references_returns_empty_lists(): void
    {
        // [] is a real answer and the caller should disable the control, the
        // same behaviour the material cascade has for a gradeless spec.
        $choices = $this->valves()->choicesFor('DIAPHRAGM');

        self::assertSame([], $choices['constructionStandards']);
        self::assertSame([], $choices['trims']);
    }

    public function test_nothing_in_the_valve_layer_allocates_anything(): void
    {
        // Standing constraint: the PMS is transcribed, so numbers are
        // validated and never generated. Same guard the SP register carries.
        foreach (get_class_methods(ManualValves::class) as $method) {
            self::assertStringNotContainsStringIgnoringCase('next', $method);
            self::assertStringNotContainsStringIgnoringCase('allocate', $method);
            self::assertStringNotContainsStringIgnoringCase('generate', $method);
        }
    }
}
