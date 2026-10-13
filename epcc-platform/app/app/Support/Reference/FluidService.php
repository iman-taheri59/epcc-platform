<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * ASME B31.3-2024 fluid services, and the examination each one requires.
 *
 * A CONDITION IS NOT A SERVICE
 * ----------------------------
 * The piping class header has carried a `fluid_service_category` since step 4,
 * with five values taken from industry habit: NORMAL, CATEGORY_D, CATEGORY_M,
 * HIGH_PRESSURE and SEVERE_CYCLIC. Reading clause 300.2 shows two faults.
 *
 * `SEVERE_CYCLIC` is **not a fluid service**. B31.3 defines "severe cyclic
 * conditions" separately, as conditions the owner or designer designates on
 * specific components or joints to better resist fatigue. It sits *alongside*
 * the service: a Category M line can be under severe cyclic conditions, and so
 * can a Normal one. One enum could not say that.
 *
 * And two real services were missing entirely — **Elevated Temperature**
 * (300.2(c)) and **High Purity** (300.2(e)).
 *
 * ONLY CATEGORY D CAN BE CHECKED
 * ------------------------------
 * It is the one service B31.3 defines with numbers: nonflammable, nontoxic,
 * not damaging to tissue, design gauge pressure not over 1 035 kPa, design
 * temperature not over 186 °C, fluid temperature not below −29 °C. Every other
 * service is defined by judgement — *the owner determines*, *the engineering
 * design specifies*. So Category D is the one this class can contradict, and
 * the others it can only record.
 *
 * THE EXTENT IS A MINIMUM
 * -----------------------
 * Clause 341.4.1: examination is "to the extent specified herein **or to any
 * greater extent specified in the engineering design**". A project that
 * demands more is not in conflict with the Code, so a finding here never fires
 * on a stricter requirement.
 */
final class FluidService
{
    private const REF_ID = 'fluid-service';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /**
     * The six services B31.3 defines. SEVERE_CYCLIC is deliberately absent.
     *
     * @return list<string>
     */
    public function services(): array
    {
        return array_map(
            static fn (array $s): string => $s['code'],
            $this->pack()['fluidServices'],
        );
    }

    /** @return list<string> */
    public function conditions(): array
    {
        return array_map(
            static fn (array $c): string => $c['code'],
            $this->pack()['conditions'],
        );
    }

    public function isService(string $code): bool
    {
        return in_array(strtoupper(trim($code)), $this->services(), true);
    }

    public function isCondition(string $code): bool
    {
        return in_array(strtoupper(trim($code)), $this->conditions(), true);
    }

    /** @return array<string,mixed>|null */
    public function get(string $code): ?array
    {
        $code = strtoupper(trim($code));
        foreach ($this->pack()['fluidServices'] as $s) {
            if ($s['code'] === $code) {
                return $s;
            }
        }

        return null;
    }

    /**
     * Does a class declared Category D actually meet the definition?
     *
     * Returns the list of bounds it breaks, or [] when it is consistent. Null
     * when the service is not Category D — nothing else in B31.3 has numbers
     * to check, and inventing some would be the opposite of reading the Code.
     *
     * @return list<array{bound:string,limit:float,actual:float,unit:string}>|null
     */
    public function categoryDViolations(
        string $service,
        ?float $designTemperatureC,
        ?float $designGaugePressureKpa,
        ?float $minFluidTemperatureC = null,
    ): ?array {
        if (strtoupper(trim($service)) !== 'CATEGORY_D') {
            return null;
        }

        $b = $this->get('CATEGORY_D')['bounds'];
        $out = [];

        if ($designGaugePressureKpa !== null
            && $designGaugePressureKpa > (float) $b['maxDesignGaugePressureKpa']) {
            $out[] = ['bound' => 'maxDesignGaugePressure',
                'limit' => (float) $b['maxDesignGaugePressureKpa'],
                'actual' => $designGaugePressureKpa, 'unit' => 'kPa'];
        }
        if ($designTemperatureC !== null
            && $designTemperatureC > (float) $b['maxDesignTemperatureC']) {
            $out[] = ['bound' => 'maxDesignTemperature',
                'limit' => (float) $b['maxDesignTemperatureC'],
                'actual' => $designTemperatureC, 'unit' => 'C'];
        }
        if ($minFluidTemperatureC !== null
            && $minFluidTemperatureC < (float) $b['minFluidTemperatureC']) {
            $out[] = ['bound' => 'minFluidTemperature',
                'limit' => (float) $b['minFluidTemperatureC'],
                'actual' => $minFluidTemperatureC, 'unit' => 'C'];
        }

        return $out;
    }

    /**
     * The examination B31.3 requires, as ITP rows waiting to be written.
     *
     * Severe cyclic conditions are passed separately because they are a
     * separate axis: they ADD requirements to whatever the fluid service
     * already demands, and 341.4.3 is the strictest clause in the set.
     *
     * @return list<array<string,mixed>>
     */
    public function examinationFor(string $service, bool $severeCyclic = false): array
    {
        $out = [];
        foreach ($this->pack()['examinationExtent'] as $e) {
            $applies = $e['appliesTo'] === strtoupper(trim($service))
                || ($severeCyclic && $e['appliesTo'] === 'SEVERE_CYCLIC');
            if (! $applies) {
                continue;
            }
            foreach ($e['requirements'] as $r) {
                $out[] = $r + ['clause' => $e['clause'], 'appliesTo' => $e['appliesTo']];
            }
        }

        return $out;
    }

    /**
     * Is a percentage the Code would accept for this service?
     *
     * The extent is a MINIMUM, so more is always fine and this only ever
     * objects to less. Null when the Code states no percentage — Category D is
     * visual only, and a percentage there is a borrowed figure.
     */
    public function minimumRadiographyPercent(string $service, bool $severeCyclic = false): ?int
    {
        $best = null;
        foreach ($this->examinationFor($service, $severeCyclic) as $r) {
            if (! isset($r['extentPercent']) || $r['extentPercent'] === null) {
                continue;
            }
            if (! str_contains(strtolower((string) $r['what']), 'radiograph')) {
                continue;
            }
            $best = max($best ?? 0, (int) $r['extentPercent']);
        }

        return $best;
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['source'].')';
    }

    /** @return array<string,mixed> */
    private function pack(): array
    {
        if ($this->pack !== null) {
            return $this->pack;
        }

        $path = rtrim($this->refDir, '/').'/'.self::REF_ID.'.ref.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Reference pack not readable: {$path}");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Reference pack is not valid JSON: {$path}");
        }

        return $this->pack = $decoded;
    }
}
