<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\ValveActuators;
use Tests\TestCase;

/**
 * Valve actuators: which one can drive which valve, and what fail-safe means.
 *
 * Reference data only - no database, no tenant binding, and no screen offers
 * these yet. The tests are about the SHAPE of the selection and about the two
 * traps in it: travel matching, and the word "fail safe".
 */
final class ValveActuatorTest extends TestCase
{
    private function act(): ValveActuators
    {
        return app(ValveActuators::class);
    }

    // ------------------------------------------------------------ travel

    public function test_a_quarter_turn_actuator_cannot_drive_a_gate_valve(): void
    {
        // The mechanical fact the pack exists to carry. A gate valve's stem
        // travels through many revolutions; a lever turns 90 degrees and
        // stops. This is not a preference a project can override.
        $act = $this->act();

        self::assertFalse($act->canDrive('LEVER', 'GATE'));
        self::assertFalse($act->canDrive('HANDWHEEL', 'BALL'));

        self::assertTrue($act->canDrive('HANDWHEEL', 'GATE'));
        self::assertTrue($act->canDrive('LEVER', 'BALL'));
    }

    public function test_the_dropdown_offers_only_what_fits(): void
    {
        $act = $this->act();

        $forBall = array_column($act->forValveType('BALL'), 'code');
        self::assertContains('LEVER', $forBall);
        self::assertContains('GEAR', $forBall);
        self::assertNotContains('HANDWHEEL', $forBall, 'A ball valve does not take a handwheel.');

        $forGate = array_column($act->forValveType('GATE'), 'code');
        self::assertContains('HANDWHEEL', $forGate);
        self::assertContains('ELECTRIC', $forGate);
        self::assertNotContains('LEVER', $forGate);
        self::assertNotContains('GAS_OVER_OIL', $forGate, 'Quarter-turn only.');
    }

    public function test_a_gearbox_fits_both_travels(): void
    {
        // A worm gearbox is made in quarter-turn and multi-turn forms and is
        // the usual answer to "this valve is too big to turn by hand".
        $act = $this->act();

        self::assertTrue($act->canDrive('GEAR', 'GATE'));
        self::assertTrue($act->canDrive('GEAR', 'BUTTERFLY'));
    }

    public function test_travel_is_exclusive(): void
    {
        $act = $this->act();

        self::assertSame('MULTI_TURN', $act->travelOf('GATE'));
        self::assertSame('MULTI_TURN', $act->travelOf('GLOBE'));
        self::assertSame('QUARTER_TURN', $act->travelOf('BALL'));
        self::assertSame('QUARTER_TURN', $act->travelOf('BUTTERFLY'));
        self::assertSame('QUARTER_TURN', $act->travelOf('PLUG'));
    }

    public function test_an_uncovered_valve_type_returns_nothing_rather_than_guessing(): void
    {
        // Null and [] are real answers. A caller must disable the control, not
        // render an empty dropdown - the same rule as a gradeless spec.
        $act = $this->act();

        self::assertNull($act->travelOf('CHECK'));
        self::assertSame([], $act->forValveType('CHECK'));
        self::assertFalse($act->canDrive('LEVER', 'CHECK'));
    }

    public function test_a_check_valve_has_no_actuator_at_all(): void
    {
        // It is opened by the flow and shut by backflow. There is nothing to
        // drive, which is also why its trim has no stem.
        self::assertSame([], $this->act()->forValveType('CHECK'));
    }

    // --------------------------------------------------------- fail safe

    public function test_only_a_spring_return_has_a_fail_safe(): void
    {
        // THE TRAP. A double-acting pneumatic actuator and an electric MOV
        // both stay exactly where they are when the power goes - datasheets
        // call it "fail safe" anyway, and a safety case gets built on a valve
        // that will never move.
        $act = $this->act();

        self::assertTrue($act->supportsFailSafe('PNEUMATIC_SPRING'));

        foreach (['PNEUMATIC_DOUBLE', 'ELECTRIC', 'HYDRAULIC', 'GAS_OVER_OIL'] as $code) {
            self::assertFalse(
                $act->supportsFailSafe($code),
                "{$code} stores no energy and cannot drive the valve anywhere.",
            );
        }
    }

    public function test_a_manual_actuator_has_no_fail_safe(): void
    {
        $act = $this->act();

        foreach (['HANDWHEEL', 'LEVER', 'GEAR'] as $code) {
            self::assertFalse($act->supportsFailSafe($code));
            self::assertSame(
                [ValveActuators::FAIL_LAST],
                $act->failSafeOptions($code),
                'A handwheel has no power to lose.',
            );
        }
    }

    public function test_fail_closed_and_fail_open_are_a_process_decision(): void
    {
        // Shutting the fuel gas is safe; shutting the cooling water is not.
        // The hardware cannot know which, so a spring return carries no
        // default and the pack refuses to invent one.
        $act = $this->act();

        self::assertSame(
            [ValveActuators::FAIL_CLOSED, ValveActuators::FAIL_OPEN],
            $act->failSafeOptions('PNEUMATIC_SPRING'),
        );

        $spring = null;
        foreach ($act->all() as $a) {
            if ($a['code'] === 'PNEUMATIC_SPRING') {
                $spring = $a;
            }
        }

        self::assertNotNull($spring);
        self::assertNull($spring['defaultFailSafe']);
    }

    public function test_everything_without_a_spring_defaults_to_fail_last(): void
    {
        foreach ($this->act()->all() as $a) {
            if ($a['supportsFailSafe']) {
                continue;
            }

            self::assertSame(
                ValveActuators::FAIL_LAST,
                $a['defaultFailSafe'],
                "{$a['code']} holds its position; that is the honest default.",
            );
        }
    }

    // -------------------------------------------------------- objections

    public function test_objections_is_the_one_call_a_screen_makes(): void
    {
        $act = $this->act();

        // A perfectly ordinary automated ball valve.
        self::assertSame([], $act->objections('PNEUMATIC_SPRING', 'BALL', 'FAIL_CLOSED'));

        // A lever on a gate valve: mechanically impossible.
        self::assertSame(['TRAVEL_MISMATCH'], $act->objections('LEVER', 'GATE'));

        // An MOV told to fail closed: it will not.
        self::assertSame(['NO_FAIL_SAFE'], $act->objections('ELECTRIC', 'GATE', 'FAIL_CLOSED'));

        // A handwheel asked to modulate.
        self::assertSame(
            ['NOT_MODULATING'],
            $act->objections('HANDWHEEL', 'GATE', 'FAIL_LAST', true),
        );
    }

    public function test_objections_can_report_more_than_one_fault(): void
    {
        // A lever on a gate valve, told to fail closed, while modulating.
        $out = $this->act()->objections('LEVER', 'GATE', 'FAIL_CLOSED', true);

        self::assertContains('TRAVEL_MISMATCH', $out);
        self::assertContains('NO_FAIL_SAFE', $out);
        self::assertContains('NOT_MODULATING', $out);
    }

    public function test_an_unknown_actuator_is_refused_not_ignored(): void
    {
        $act = $this->act();

        self::assertSame(['UNKNOWN_ACTUATOR'], $act->objections('ROTORK_IQ3', 'GATE'));
        self::assertSame(['UNKNOWN_VALVE_TYPE'], $act->objections('ELECTRIC', 'PINCH'));
        self::assertSame([], $act->failSafeOptions('ROTORK_IQ3'));
    }

    public function test_a_manual_actuator_cannot_modulate(): void
    {
        foreach ($this->act()->all() as $a) {
            if ($a['power'] !== 'MANUAL') {
                continue;
            }

            self::assertFalse(
                $a['supportsModulating'],
                "Nobody stands at a {$a['code']} holding a setpoint.",
            );
        }
    }

    // ------------------------------------------------------------- shape

    public function test_every_actuator_is_bilingual_and_unverified(): void
    {
        $act = $this->act();
        $checked = 0;

        foreach ($act->all() as $a) {
            self::assertNotSame('', $a['name']['en']);
            self::assertNotSame('', $a['name']['fa']);
            self::assertNotSame(
                $a['name']['en'],
                $a['name']['fa'],
                "{$a['code']} has an untranslated Farsi name.",
            );
            $checked++;
        }

        self::assertGreaterThanOrEqual(8, $checked);
    }

    public function test_the_fail_and_control_vocabularies_are_closed(): void
    {
        $act = $this->act();

        self::assertSame(
            ['FAIL_CLOSED', 'FAIL_OPEN', 'FAIL_LAST'],
            array_column($act->failSafeModes(), 'code'),
        );
        self::assertSame(
            ['ON_OFF', 'MODULATING'],
            array_column($act->controlModes(), 'code'),
        );
    }

    public function test_an_actuator_is_not_a_material(): void
    {
        // It has no ASTM specification, no grade and no product form. Putting
        // it in the cascade would repeat the mistake that first put valve trim
        // there - a dropdown offering a vendor assembly where a steel belongs.
        $lib = app(\App\Support\Reference\MaterialLibrary::class);

        foreach ($lib->groups() as $group) {
            self::assertNotSame('ACTUATOR', $group['code']);
        }

        self::assertNotEmpty($this->act()->provenance());
    }
}
