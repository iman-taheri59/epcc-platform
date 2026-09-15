<?php

declare(strict_types=1);

namespace App\Support\Reference;

use App\Support\Reference\Exceptions\UnknownScheduleException;
use App\Support\Reference\Exceptions\UnknownSizeException;
use RuntimeException;

/**
 * Read-only access to the engineering reference packs (ADR-007).
 *
 * WHY THIS IS NOT A DATABASE TABLE
 * --------------------------------
 * NPS 6" = DN 150 = OD 168.3 mm is true on every project, in every company, in
 * every year. It is not tenant data. Putting it in a table would mean either
 * duplicating ~500 rows per project or creating the system's first cross-tenant
 * table and then writing a permanent RLS exception for it. The real protection
 * needed here is "nobody can write, including the owner company" - which a file
 * on disk gives absolutely and a permissive RLS policy only pretends to.
 *
 * MEMOISATION
 * -----------
 * Decoded once per process and indexed on first use. The instance is a
 * singleton, so a request that touches 300 ports reads the file once.
 *
 * EVERY LOOKUP THROWS
 * -------------------
 * No method here returns null for a missing size or schedule. See the exception
 * classes for why.
 */
final class PipeReference
{
    /** @var array<string, array<string,mixed>>|null */
    private ?array $packs = null;

    /** @var array<string, PipeSize>|null  keyed by NPS label */
    private ?array $byNps = null;

    /** @var array<int, PipeSize>|null  keyed by DN */
    private ?array $byDn = null;

    public function __construct(private readonly string $refDir)
    {
    }

    // ------------------------------------------------------------ sizes ---

    /** @return list<PipeSize> ordered ascending by DN */
    public function sizes(): array
    {
        $this->indexSizes();

        return array_values($this->byNps ?? []);
    }

    /** @throws UnknownSizeException */
    public function size(string $nps): PipeSize
    {
        $this->indexSizes();
        $key = self::normaliseNps($nps);

        return ($this->byNps[$key] ?? null) ?? throw UnknownSizeException::nps($nps);
    }

    /**
     * The DN way in - what an Iranian or European spec quotes, and the integer
     * key ADR-008's int4range size bands are built on.
     *
     * @throws UnknownSizeException
     */
    public function byDn(int $dn): PipeSize
    {
        $this->indexSizes();

        return ($this->byDn[$dn] ?? null) ?? throw UnknownSizeException::dn($dn);
    }

    public function hasSize(string $nps): bool
    {
        $this->indexSizes();

        return isset($this->byNps[self::normaliseNps($nps)]);
    }

    /**
     * Accept the many ways an engineer writes the same size and return the one
     * canonical label. "1-1/4", "1 1/4", "1.25" and "۱ ۱/۴" are all NPS 1 1/4.
     *
     * Returns null when nothing matches, because this is the one place where a
     * caller (line-number parsing, PCF import) legitimately needs to ask
     * "is this a size at all?" without an exception.
     */
    public function resolveLabel(string $input): ?PipeSize
    {
        $this->indexSizes();
        $key = self::normaliseNps($input);

        if (isset($this->byNps[$key])) {
            return $this->byNps[$key];
        }

        // Decimal form: 2.5 -> 2 1/2
        if (is_numeric($key)) {
            $value = (float) $key;
            foreach ($this->byNps as $size) {
                if (abs($size->npsDecimal - $value) < 0.0001) {
                    return $size;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a bore coming from a PCF file, where UNITS-BORE may be INCH, MM
     * or INCH-SIXTEENTH. The unit must be read from the header and passed in -
     * assuming it is how sizes get silently corrupted on import.
     */
    public function resolveBore(float $bore, string $unitsBore): ?PipeSize
    {
        $this->indexSizes();

        $inches = match (strtoupper(trim($unitsBore))) {
            'INCH' => $bore,
            'INCH-SIXTEENTH' => $bore / 16.0,
            'MM' => null,
            default => throw new RuntimeException("Unsupported UNITS-BORE '{$unitsBore}'."),
        };

        if ($inches !== null) {
            foreach ($this->byNps as $size) {
                if (abs($size->npsDecimal - $inches) < 0.01) {
                    return $size;
                }
            }

            return null;
        }

        // MM bore is an OUTSIDE diameter in PCF, matched with tolerance.
        foreach ($this->byNps as $size) {
            if (abs($size->odMm - $bore) < 1.0) {
                return $size;
            }
        }

        return null;
    }

    // -------------------------------------------------------- schedules ---

    /** @return list<string> the full declared ladder, heaviest last */
    public function schedules(): array
    {
        return array_map(strval(...), $this->pack('pipe-schedules')['schedules']);
    }

    /**
     * Only the schedules that actually exist at this size, in ladder order.
     *
     * @return list<string>
     *
     * @throws UnknownSizeException
     */
    public function schedulesFor(string $nps): array
    {
        $size = $this->size($nps);
        $walls = $this->pack('pipe-schedules')['walls'][$size->nps] ?? [];

        // MUST cast. PHP turns the numeric-string JSON keys "40", "80" into
        // INTEGER array keys, so array_keys() hands back a mixed list of ints
        // and strings. That silently breaks strict in_array() checks in a
        // picklist and is a TypeError the moment a key is passed to a
        // string-typed method such as wall(). Caught by
        // test_no_wall_thickness_closes_the_bore.
        return array_map(strval(...), array_keys($walls));
    }

    /**
     * Wall thickness in millimetres.
     *
     * STD is NOT an alias of SCH 40 above NPS 10, and XS is NOT an alias of
     * SCH 80 above NPS 8 (ADR-007 section 2). This method must never "helpfully"
     * fall back from one to the other.
     *
     * @throws UnknownSizeException|UnknownScheduleException
     */
    public function wall(string $nps, string $schedule): float
    {
        $size = $this->size($nps);
        $schedule = self::normaliseSchedule($schedule);
        $walls = $this->pack('pipe-schedules')['walls'][$size->nps] ?? [];

        if (! isset($walls[$schedule])) {
            throw UnknownScheduleException::forSize(
                $size->nps,
                $schedule,
                array_values(array_keys($walls)),
            );
        }

        return (float) $walls[$schedule];
    }

    public function hasSchedule(string $nps, string $schedule): bool
    {
        if (! $this->hasSize($nps)) {
            return false;
        }

        $walls = $this->pack('pipe-schedules')['walls'][$this->size($nps)->nps] ?? [];

        return isset($walls[self::normaliseSchedule($schedule)]);
    }

    /** Inside diameter in millimetres: OD - 2t. */
    public function insideDiameter(string $nps, string $schedule): float
    {
        return $this->size($nps)->odMm - 2 * $this->wall($nps, $schedule);
    }

    // -------------------------------------------------------- end types ---

    /** @return list<array<string,mixed>> */
    public function endTypes(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->pack('end-types')['endTypes'];
    }

    /** @return array<string,mixed> */
    public function endType(string $code): array
    {
        foreach ($this->endTypes() as $end) {
            if ($end['code'] === strtoupper(trim($code))) {
                return $end;
            }
        }

        throw new RuntimeException("Unknown end type '{$code}'.");
    }

    /**
     * Is a joint between two ports of these end types a weld?
     *
     * This is what decides whether a joint can carry a joint number and
     * contribute dia-inch. A flanged connection is a bolted joint: it gets no
     * number and adds nothing to welded progress (ADR-006 section 4).
     */
    public function isWeldable(string $code): bool
    {
        return (bool) $this->endType($code)['weldable'];
    }

    public function isNumbered(string $code): bool
    {
        return (bool) $this->endType($code)['numbered'];
    }

    // ------------------------------------------------ component templates --

    /** @return list<array<string,mixed>> */
    public function templates(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->pack('component-templates')['templates'];
    }

    /** @return array<string,mixed> */
    public function template(string $code): array
    {
        foreach ($this->templates() as $t) {
            if ($t['code'] === strtoupper(trim($code))) {
                return $t;
            }
        }

        throw new RuntimeException("Unknown component template '{$code}'.");
    }

    /** @return list<string> the universal codes a project may NOT shadow (ADR-010 section 2) */
    public function universalTemplateCodes(): array
    {
        return array_map(static fn (array $t): string => (string) $t['code'], $this->templates());
    }

    /**
     * How many ports a component of this type has.
     *
     * The user asked how the system knows a tee has three ports. This is the
     * answer: it is declared by the template, never typed by the user
     * (ADR-009 section 2).
     */
    public function portCount(string $templateCode): int
    {
        return count($this->template($templateCode)['ports']);
    }

    // -------------------------------------------------------- provenance --

    /** @return array<string,string> refId => "version (source)" */
    public function provenance(): array
    {
        $out = [];
        foreach (['pipe-sizes', 'pipe-schedules', 'end-types', 'component-templates'] as $id) {
            $p = $this->pack($id);
            $out[$id] = $p['version'].' ('.$p['source'].')';
        }

        return $out;
    }

    // ----------------------------------------------------------- internals -

    /** @return array<string,mixed> */
    private function pack(string $refId): array
    {
        if (isset($this->packs[$refId])) {
            return $this->packs[$refId];
        }

        $path = $this->refDir.'/'.$refId.'.ref.json';
        if (! is_file($path)) {
            throw new RuntimeException("Reference pack not found: {$refId} (looked in {$path})");
        }

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $this->packs[$refId] = $decoded;
    }

    private function indexSizes(): void
    {
        if ($this->byNps !== null) {
            return;
        }

        $this->byNps = [];
        $this->byDn = [];

        foreach ($this->pack('pipe-sizes')['sizes'] as $row) {
            $size = PipeSize::fromArray($row);
            $this->byNps[$size->nps] = $size;
            $this->byDn[$size->dn] = $size;
        }
    }

    /**
     * Fold the ways a size gets written into the canonical label.
     *
     * Persian digits are converted because the UI is bilingual and a Farsi
     * keyboard produces ۱ ۱/۴ for what the pack stores as "1 1/4".
     */
    private static function normaliseNps(string $nps): string
    {
        $nps = trim($nps);
        $nps = strtr($nps, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '"' => '', '”' => '', '″' => '',
        ]);
        $nps = str_replace('-', ' ', $nps);

        return (string) preg_replace('/\s+/', ' ', trim($nps));
    }

    private static function normaliseSchedule(string $schedule): string
    {
        return strtoupper(trim($schedule));
    }
}
