<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * Which actuators may drive which valves, and what "fail safe" actually means.
 *
 * WHY THIS IS NOT IN THE MATERIAL LIBRARY
 * ---------------------------------------
 * An actuator is bought as an assembly with a vendor model number. It has no
 * ASTM specification, no grade and no product form, so forcing it into the
 * four-step material cascade would repeat exactly the mistake that first put
 * valve trim there: a dropdown offering "Bettis G-series" where "A216 WCB"
 * belongs. It is reached by its own selector, alongside the body material and
 * the trim - a valve now needs three independent answers, not one.
 *
 * TRAVEL IS THE LOAD-BEARING FACT
 * -------------------------------
 * An actuator drives a valve only if their TRAVEL matches. A ball, butterfly
 * or plug valve turns 90 degrees; a gate or globe valve needs many turns of a
 * rising stem. That is mechanical, not a preference - a quarter-turn actuator
 * physically cannot open a gate valve - so `forValveType()` is the only honest
 * way to populate a dropdown. An unfiltered list would offer a lever for a
 * 12 inch gate valve.
 *
 * FAIL-SAFE IS THE TRAP
 * ---------------------
 * Only an actuator that STORES ENERGY drives the valve anywhere when power is
 * lost. A spring return does. A double-acting pneumatic actuator and an
 * electric MOV do not: they stay exactly where they were, and datasheets call
 * that "fail safe" all the time. That is how a safety case ends up resting on
 * a valve that will never move. So:
 *
 *   - `supportsFailSafe()` is true for the spring return and nothing else.
 *   - FC and FO are chosen PER VALVE from the process (shutting the fuel gas
 *     is safe; shutting the cooling water is not), never defaulted from the
 *     hardware, which is why a spring return has no `defaultFailSafe`.
 *   - Everything else is FAIL_LAST, and FAIL_LAST is not a safety function.
 *
 * NOTHING HERE IS VERIFIED
 * ------------------------
 * Every row carries verified=false, like the bolting and material packs. These
 * are practice, not transcriptions from a purchased standard, and no screen
 * offers them yet - the reference lands first, the check follows.
 */
final class ValveActuators
{
    private const REF_ID = 'actuators';

    /** The valve holds its last position. Correct for manual and for anything without a spring. */
    public const FAIL_LAST = 'FAIL_LAST';

    public const FAIL_CLOSED = 'FAIL_CLOSED';

    public const FAIL_OPEN = 'FAIL_OPEN';

    /** Hand operated: a handwheel, lever, gearbox or chainwheel. */
    public const POWER_MANUAL = 'MANUAL';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /**
     * Every actuator the pack holds.
     *
     * Rarely what a screen wants: an actuator is only valid for valves whose
     * travel it matches. Use `forValveType()`.
     *
     * @return list<array{code:string,name:array{en:string,fa:string},power:string,travels:list<string>,defaultFailSafe:string|null,supportsFailSafe:bool,supportsModulating:bool}>
     */
    public function all(): array
    {
        return array_map(static fn (array $a): array => [
            'code' => $a['code'],
            'name' => $a['name'],
            'power' => $a['power'],
            'travels' => $a['travels'],
            'defaultFailSafe' => $a['defaultFailSafe'],
            'supportsFailSafe' => (bool) $a['supportsFailSafe'],
            'supportsModulating' => (bool) $a['supportsModulating'],
        ], $this->pack()['actuators']);
    }

    /**
     * THE DROPDOWN. The actuators that can actually drive this valve type.
     *
     * Returns [] for a valve type no travel claims, which is a real answer and
     * not an error: the caller should disable the control rather than render
     * an empty list, exactly as the material cascade does for a gradeless spec.
     *
     * @return list<array{code:string,name:array{en:string,fa:string},power:string,travels:list<string>,defaultFailSafe:string|null,supportsFailSafe:bool,supportsModulating:bool}>
     */
    public function forValveType(string $valveType): array
    {
        $travel = $this->travelOf($valveType);
        if ($travel === null) {
            return [];
        }

        return array_values(array_filter(
            $this->all(),
            static fn (array $a): bool => in_array($travel, $a['travels'], true),
        ));
    }

    /** How this valve type travels, or null if the pack does not cover it. */
    public function travelOf(string $valveType): ?string
    {
        foreach ($this->pack()['travels'] as $t) {
            if (in_array($valveType, $t['valveTypes'], true)) {
                return $t['code'];
            }
        }

        return null;
    }

    /** Can this actuator physically drive this valve type? */
    public function canDrive(string $actuatorCode, string $valveType): bool
    {
        $travel = $this->travelOf($valveType);
        if ($travel === null) {
            return false;
        }

        $actuator = $this->actuator($actuatorCode);

        return $actuator !== null && in_array($travel, $actuator['travels'], true);
    }

    /**
     * Only a spring return stores the energy to move a valve with the power
     * off. Everything else stays put.
     */
    public function supportsFailSafe(string $actuatorCode): bool
    {
        return (bool) ($this->actuator($actuatorCode)['supportsFailSafe'] ?? false);
    }

    /**
     * The fail states this actuator may legitimately be given.
     *
     * A spring return may be FC or FO - the process decides which, and the
     * pack deliberately holds no default. Everything else may only be
     * FAIL_LAST, and offering it FC would be a lie about the hardware.
     *
     * @return list<string>
     */
    public function failSafeOptions(string $actuatorCode): array
    {
        $actuator = $this->actuator($actuatorCode);
        if ($actuator === null) {
            return [];
        }

        return $actuator['supportsFailSafe']
            ? [self::FAIL_CLOSED, self::FAIL_OPEN]
            : [self::FAIL_LAST];
    }

    /**
     * Is this a legal combination of actuator, valve type and fail state?
     *
     * The single call a future MTO screen should make before storing a row.
     * Returns a list of reason codes; empty means acceptable.
     *
     * @return list<string> TRAVEL_MISMATCH | NO_FAIL_SAFE | UNKNOWN_ACTUATOR | UNKNOWN_VALVE_TYPE | NOT_MODULATING
     */
    public function objections(
        string $actuatorCode,
        string $valveType,
        ?string $failSafe = null,
        bool $modulating = false,
    ): array {
        $out = [];

        $actuator = $this->actuator($actuatorCode);
        if ($actuator === null) {
            return ['UNKNOWN_ACTUATOR'];
        }

        if ($this->travelOf($valveType) === null) {
            return ['UNKNOWN_VALVE_TYPE'];
        }

        if (! $this->canDrive($actuatorCode, $valveType)) {
            $out[] = 'TRAVEL_MISMATCH';
        }

        if ($failSafe !== null
            && ! in_array($failSafe, $this->failSafeOptions($actuatorCode), true)) {
            $out[] = 'NO_FAIL_SAFE';
        }

        if ($modulating && ! $actuator['supportsModulating']) {
            $out[] = 'NOT_MODULATING';
        }

        return $out;
    }

    /**
     * THE MANUAL HALF — what a PMS row may say.
     *
     * ADR-008 §11.9 sets the discriminator: if a valve has an actuator it is a
     * datasheet; if it does not, it is a PMS row. So the PMS needs exactly the
     * hand-operated entries of this pack, and needs them filtered by travel as
     * well — a lever cannot open a gate valve any more than a quarter-turn
     * pneumatic can.
     *
     * Returning [] for CHECK is the right answer and not an empty dropdown to
     * apologise for: a check valve is opened by the flow and has no operator.
     *
     * @return list<array{code:string,name:array{en:string,fa:string},power:string,travels:list<string>,defaultFailSafe:string|null,supportsFailSafe:bool,supportsModulating:bool}>
     */
    public function manualOperatorsFor(string $valveType): array
    {
        return array_values(array_filter(
            $this->forValveType($valveType),
            static fn (array $a): bool => $a['power'] === self::POWER_MANUAL,
        ));
    }

    /** Every hand-operated entry, unfiltered. Rarely what a screen wants. */
    public function manualOperators(): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $a): bool => $a['power'] === self::POWER_MANUAL,
        ));
    }

    /**
     * Is this code a hand-operated one? False for an unknown code AND for a
     * powered one, which is the distinction the PMS boundary rests on: an
     * ELECTRIC row is not a typo, it is a valve that belongs in another
     * register entirely.
     */
    public function isManual(string $code): bool
    {
        $a = $this->actuator($code);

        return $a !== null && $a['power'] === self::POWER_MANUAL;
    }

    /** @return list<array{code:string,name:array{en:string,fa:string}}> */
    public function failSafeModes(): array
    {
        return array_map(static fn (array $f): array => [
            'code' => $f['code'], 'name' => $f['name'],
        ], $this->pack()['failSafeModes']);
    }

    /** @return list<array{code:string,name:array{en:string,fa:string}}> */
    public function controlModes(): array
    {
        return array_map(static fn (array $c): array => [
            'code' => $c['code'], 'name' => $c['name'],
        ], $this->pack()['controlModes']);
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['source'].')';
    }

    // ---------------------------------------------------------- internals --

    /** @return array<string,mixed>|null */
    private function actuator(string $code): ?array
    {
        foreach ($this->all() as $a) {
            if ($a['code'] === $code) {
                return $a;
            }
        }

        return null;
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/').'/'.self::REF_ID.'.ref.json';
        if (! is_file($path)) {
            throw new RuntimeException('Actuator pack not found (looked in '.$path.')');
        }

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $this->pack = $decoded;
    }
}
