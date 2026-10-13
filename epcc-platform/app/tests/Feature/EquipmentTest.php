<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Component;
use App\Models\Equipment;
use App\Models\EquipmentNozzle;
use App\Models\Facility;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\EquipmentService;
use App\Services\JointService;
use App\Services\PipingLineService;
use App\Support\Piping\PortCompatibility;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-010 section 3 / phase 3b — equipment, nozzles and the tag engine.
 *
 * Three things this file exists to prove:
 *
 *   1. TAGS ARE SUPPLIED AND VALIDATED, NEVER INVENTED (the user's ruling).
 *      A malformed tag, a tag that contradicts the form, and a duplicate are
 *      all refused - with a sentence, not a constraint code.
 *   2. THE UNIT IS PART OF THE TAG. 11-P-101A and 21-P-101A coexist; a second
 *      11-P-101A does not. This is the user's stated failure mode - the wrong
 *      pump getting hydrotested.
 *   3. A NOZZLE IS JUST A PORT OWNER. A pipe-to-nozzle joint uses the same
 *      joints table, unchanged - and the 150#-against-300# error is caught at
 *      model-entry rather than at bolt-up.
 */
final class EquipmentTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);
    }

    private function svc(): EquipmentService
    {
        return app(EquipmentService::class);
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
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


    /** @param array<string,mixed> $extra */
    private function register(array $extra = []): Equipment
    {
        return $this->asOwner(fn () => $this->svc()->register($this->project(), $this->owner(), array_merge([
            'equipment_type' => 'P',
            'unit_code' => '11',
            'tag_number' => '11-P-101',
        ], $extra)));
    }








    // ------------------------------------------------- supplied tags --

    public function test_a_well_formed_tag_is_accepted(): void
    {

        $pump = $this->register(['tag_number' => '11-P-101A']);

        $this->assertSame('11-P-101A', $pump->tag_number);
        $this->assertSame(101, $pump->tag_sequence);
        $this->assertSame('A', $pump->tag_suffix);
    }

    public function test_a_tag_must_be_supplied(): void
    {

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->register($this->project(), $this->owner(), [
            'equipment_type' => 'P', 'unit_code' => '11',
        ]));
    }

    public function test_a_malformed_tag_is_refused(): void
    {

        $this->expectException(ValidationException::class);

        $this->register(['tag_number' => 'PUMP 101']);
    }

    public function test_a_tag_whose_unit_contradicts_the_form_is_refused(): void
    {
        // One of the two is wrong, and guessing which would file the pump in
        // the wrong unit's register.

        $this->expectException(ValidationException::class);

        $this->register(['unit_code' => '11', 'tag_number' => '21-P-101']);
    }

    public function test_a_tag_whose_type_contradicts_the_form_is_refused(): void
    {

        $this->expectException(ValidationException::class);

        $this->register(['equipment_type' => 'P', 'tag_number' => '11-V-101']);
    }

    public function test_an_unregistered_type_inside_the_tag_is_refused(): void
    {
        // A closed vocabulary is what makes a typo a refusal rather than a new
        // equipment class nobody meant to create.

        $this->expectException(ValidationException::class);

        $this->register(['equipment_type' => 'ZZ', 'tag_number' => '11-ZZ-101']);
    }


    // ----------------------------------------------- uniqueness and units --

    public function test_the_same_tag_cannot_repeat_in_one_project(): void
    {
        $this->register(['tag_number' => '11-P-101A']);

        $this->expectException(ValidationException::class);

        $this->register(['tag_number' => '11-P-101A']);
    }

    public function test_a_tag_freed_by_deletion_may_be_reused_deliberately(): void
    {
        // The reason allocation was withdrawn. A counter reading max() over
        // surviving rows would have handed 11-P-102 back on its own; here
        // reuse is possible only because a human typed it again, which is a
        // decision somebody can be asked about.
        $this->register(['tag_number' => '11-P-102']);

        $this->asOwner(fn () => Equipment::where('tag_number', '11-P-102')->firstOrFail()->delete());

        $this->assertSame('11-P-102', $this->register(['tag_number' => '11-P-102'])->tag_number);
    }

    public function test_the_same_sequence_may_repeat_in_another_unit(): void
    {
        // THE USER'S POINT. Unit 11 and unit 21 may both hold a P-101A; it is
        // the full tag that is unique, and that is exactly why the unit code
        // has to be inside it.

        $this->register(['unit_code' => '11', 'tag_number' => '11-P-101A']);
        $this->register(['unit_code' => '21', 'tag_number' => '21-P-101A']);

        $this->assertSame(2, $this->asOwner(fn () => Equipment::count()));
    }

    public function test_equipment_needs_a_unit_code(): void
    {

        $this->expectException(ValidationException::class);

        // Deliberately supply a BLANK unit rather than none: with the guard
        // removed, resolveUnit returns '' and the tag/unit comparison then
        // passes vacuously, so only a direct attack on the guard proves it is
        // there. (Found by mutant M95.)
        $this->asOwner(fn () => $this->svc()->register($this->project(), $this->owner(), [
            'equipment_type' => 'P', 'tag_number' => '-P-101', 'unit_code' => '   ',
        ]));
    }

    public function test_an_area_supplies_the_unit_code(): void
    {
        // "Area IS the Unit" - when an area is given, its unit_code is
        // authoritative and nobody retypes it.

        $area = $this->asOwner(fn () => Area::where('code', 'AR-22')->firstOrFail());
        $pump = $this->register(['area_id' => $area->id, 'unit_code' => null, 'tag_number' => 'U-2200-P-101']);

        $this->assertSame('U-2200', $pump->unit_code);
        $this->assertSame('U-2200-P-101', $pump->tag_number);
    }

    public function test_an_area_from_another_project_is_refused(): void
    {

        $this->expectException(ValidationException::class);

        $foreign = $this->tenant->forIdentityLookup(
            fn () => Area::whereNotIn('project_id', [$this->project()->id])->first()
        );

        // The fixture may not have a foreign area; a random uuid exercises the
        // same guard without depending on that.
        $this->register(['area_id' => $foreign->id ?? '00000000-0000-4000-8000-000000000000']);
    }

    // ------------------------------------------------------------ nozzles --

    public function test_a_nozzle_gets_a_port_without_being_asked(): void
    {
        // The same rule as components: a nozzle with no port is invisible to
        // the joint machinery, so nothing in the API can produce one.

        $nozzle = $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);

            return $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-1', 'nominal_size' => '6', 'rating' => '150#', 'facing' => 'RF',
            ]);
        });

        $this->assertNotNull($nozzle->port);
        $this->assertSame(Port::OWNER_NOZZLE, $nozzle->port->owner_kind);
        $this->assertNull($nozzle->port->component_id);
        $this->assertSame('150#', $nozzle->port->rating);
    }

    public function test_the_nozzles_rating_reaches_its_port(): void
    {
        // PortCompatibility reads PORTS. A rating stored only on the nozzle row
        // would never be compared, and the 150#-against-300# error would sail
        // through while the nozzle schedule looked correct. (Mutant M97.)
        $port = $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);

            return $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-1', 'nominal_size' => '6', 'rating' => '300#', 'facing' => 'RTJ',
            ])->port;
        });

        $this->assertSame('300#', $port->rating);
        $this->assertSame('RTJ', $port->facing);
    }

    public function test_two_nozzles_on_one_vessel_cannot_share_a_tag(): void
    {

        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);

            $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);
            $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '4']);
        });
    }

    public function test_every_vessel_may_have_its_own_n1(): void
    {

        $count = $this->asOwner(function () {
            foreach ([101, 102] as $n) {
                $eq = $this->svc()->register($this->project(), $this->owner(), [
                    'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => "11-V-{$n}",
                ]);
                $this->svc()->addNozzle($eq, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);
            }

            return EquipmentNozzle::where('tag', 'N-1')->count();
        });

        $this->assertSame(2, $count);
    }

    public function test_the_nozzle_schedule_flags_unconnected_process_nozzles(): void
    {
        // The classic deliverable, and the reason is_spare exists: an
        // unconnected spare is fine, an unconnected process nozzle before
        // hydrotest is the error worth finding.

        $schedule = $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);

            $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);
            $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-9', 'nominal_size' => '2', 'is_spare' => true,
            ]);

            return $this->svc()->nozzleSchedule($vessel->fresh());
        });

        $byTag = collect($schedule)->keyBy('tag');

        $this->assertTrue($byTag['N-1']['outstanding']);
        $this->assertFalse($byTag['N-9']['outstanding']);
    }

    public function test_an_unknown_nozzle_type_is_refused(): void
    {

        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);

            $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nozzle_type' => 'SPIGOT']);
        });
    }

    // ------------------------------------------- the joint to the vessel --

    /** A line with one flange on it, ready to meet a nozzle. */
    private function lineWithFlange(): array
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->asOwner(function () use ($project, $owner) {
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            // create() is withdrawn: numbers come from the engineering list.
            $line = $lines->register(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                '6-P-A1-PH-14-SS-2201-001',
                ['nominal_size' => '6', 'service_code' => 'P', 'piping_class_id' => $class->id],
            );

            $flange = app(ComponentService::class)->place($line, $owner, 'FLANGE');

            return [$line, $flange];
        });
    }

    public function test_a_pipe_can_be_joined_to_a_vessel_nozzle(): void
    {
        // THE POINT OF ADR-010 SECTION 3.1: the joints table needed no change.

        [$line, $flange] = $this->lineWithFlange();

        $joint = $this->asOwner(function () use ($flange) {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);

            $nozzle = $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-1', 'nominal_size' => '6', 'end_type' => 'FL', 'rating' => '150#', 'facing' => 'RF',
            ]);

            $flangeFace = $flange->ports->firstWhere('end_type', 'FL');

            return app(JointService::class)->join($flangeFace, $nozzle->port, $this->owner());
        });

        $this->assertSame('FLANGED', $joint->joint_type);
        $this->assertSame($line->id, $joint->piping_line_id);
    }

    public function test_the_joint_to_a_nozzle_belongs_to_the_pipes_line(): void
    {
        // A nozzle has no line of its own. The weld appears on the LINE's
        // isometric and in its dia-inch, not the vessel's.

        [$line, $flange] = $this->lineWithFlange();

        $joint = $this->asOwner(function () use ($flange) {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $nozzle = $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-1', 'nominal_size' => '6', 'end_type' => 'FL', 'rating' => '150#',
            ]);

            return app(JointService::class)->join(
                $flange->ports->firstWhere('end_type', 'FL'), $nozzle->port, $this->owner()
            );
        });

        $this->assertSame($line->id, $joint->piping_line_id);
        $this->assertSame('FIELD', $joint->shop_field);
    }

    public function test_a_rating_mismatch_at_a_nozzle_is_refused(): void
    {
        // The expensive one: 6in 150# flange against a 6in 300# nozzle passes
        // bore, end type and schedule, and is only found at bolt-up with the
        // crane hired (ADR-010 section 3.4).

        [, $flange] = $this->lineWithFlange();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($flange) {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $nozzle = $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-1', 'nominal_size' => '6', 'end_type' => 'FL', 'rating' => '300#',
            ]);

            Port::where('id', $flange->ports->firstWhere('end_type', 'FL')->id)->update(['rating' => '150#']);

            app(JointService::class)->join(
                Port::find($flange->ports->firstWhere('end_type', 'FL')->id), $nozzle->port, $this->owner()
            );
        });
    }

    public function test_equivalent_rating_spellings_do_not_refuse(): void
    {
        // '150#', '150' and 'CL150' are one rating. Refusing on spelling would
        // teach people to ignore the check.
        $compat = app(PortCompatibility::class);

        $a = new Port(['nominal_size' => '6', 'nominal_size_decimal' => 6.0, 'end_type' => 'FL', 'rating' => '150#']);
        $b = new Port(['nominal_size' => '6', 'nominal_size_decimal' => 6.0, 'end_type' => 'FL', 'rating' => 'CL150']);

        $this->assertFalse($compat->evaluate($a, $b)->isInvalid());
    }

    public function test_a_facing_mismatch_warns_rather_than_refuses(): void
    {
        // RF-to-FF is bad practice but sometimes deliberate with a full-face
        // gasket on cast iron. The engineer must SEE it, not be blocked.
        $compat = app(PortCompatibility::class);

        $a = new Port(['nominal_size' => '6', 'nominal_size_decimal' => 6.0, 'end_type' => 'FL', 'rating' => '150#', 'facing' => 'RF']);
        $b = new Port(['nominal_size' => '6', 'nominal_size_decimal' => 6.0, 'end_type' => 'FL', 'rating' => '150#', 'facing' => 'FF']);

        $verdict = $compat->evaluate($a, $b);

        $this->assertTrue($verdict->isWarning());
        $this->assertTrue($verdict->has('facing_mismatch'));
    }

    public function test_two_nozzles_cannot_be_joined_to_each_other(): void
    {
        // Two vessels bolted together with no spool between them is not
        // something the model should invent a line for.

        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $ports = [];

            foreach ([101, 102] as $n) {
                $eq = $this->svc()->register($this->project(), $this->owner(), [
                    'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => "11-V-{$n}",
                ]);
                $ports[] = $this->svc()->addNozzle($eq, $this->owner(), [
                    'tag' => 'N-1', 'nominal_size' => '6', 'end_type' => 'FL', 'rating' => '150#',
                ])->port;
            }

            app(JointService::class)->join($ports[0], $ports[1], $this->owner());
        });
    }

    public function test_deleting_equipment_removes_its_nozzles_and_their_ports(): void
    {

        [$nozzles, $ports] = $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $nozzle = $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);
            $portId = $nozzle->port->id;

            $vessel->delete();

            return [
                EquipmentNozzle::where('equipment_id', $vessel->id)->count(),
                Port::where('id', $portId)->count(),
            ];
        });

        $this->assertSame(0, $nozzles);
        $this->assertSame(0, $ports);
    }

    // ------------------------------------------- withdrawn auto-numbering --

    public function test_a_project_does_not_allocate_line_numbers_by_default(): void
    {
        // The rule that came out of this phase: a counter reading max() over
        // surviving rows hands a deleted line's number to the next one, and a
        // number that has reached an isometric or a PO must never come back.
        // New projects therefore supply their numbers. (Mutant M103.)
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => app(PipingLineService::class)->create(
            Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
            $this->owner(),
            ['nominal_size' => '6', 'service_code' => 'P'],
        ));
    }

    public function test_a_supplied_line_number_is_always_accepted(): void
    {
        // The replacement path. It parses what it can and stores the line
        // either way: an EPCC project always has legacy or vendor-package tags
        // that predate the scheme, and they must be visible AS non-conforming
        // rather than refused.
        $line = $this->asOwner(fn () => app(PipingLineService::class)->register(
            Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
            $this->owner(),
            'VENDOR-PKG-7781',
        ));

        $this->assertSame('VENDOR-PKG-7781', $line->line_number);
        $this->assertNull($line->sequence);
    }

    // ---------------------------------------------------------------- RLS --

    public function test_equipment_is_hidden_from_a_non_participant(): void
    {
        $this->register();

        $arvand = \App\Models\Company::where('code', 'EPC-ARVAND')->firstOrFail();

        $visible = $this->tenant->runAs($arvand->id, fn () => Equipment::count(), $this->project()->id);

        $this->assertSame(0, $visible);
    }
}
