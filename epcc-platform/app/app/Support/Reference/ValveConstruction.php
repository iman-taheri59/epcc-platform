<?php

declare(strict_types=1);

namespace App\Support\Reference;

use RuntimeException;

/**
 * How a manual valve is BUILT — API 600, API 602, API 6D, BS 1868 and their
 * siblings. ADR-008 §11.9.
 *
 * WHY THIS IS A SEPARATE PACK
 * ---------------------------
 * A PMS valve line carries two standards that are constantly confused:
 *
 *   ASME B16.10  — face to face. A DIMENSION. It goes in the body row's
 *                  `dimensional_standard`, alongside B36.10M for pipe and
 *                  B16.5 for flanges.
 *   API 600      — bonnet, stem, backseat, wall thickness, testing. A
 *                  CONSTRUCTION standard. It says what the valve *is*.
 *
 * Writing API 600 in the dimensional column loses the face-to-face claim and
 * writing B16.10 in the construction column loses everything else, so they are
 * two fields fed by two sources.
 *
 * WHAT IT ANSWERS, AND WHAT IT REFUSES TO
 * ---------------------------------------
 * It answers "is this construction standard capable of covering this valve
 * type at this size" — API 600 does not do ball valves, API 602 stops at NPS 4.
 * It does not choose: a gate valve may honestly be API 600, API 603, API 6D,
 * BS 1414 or ISO 15761, and which one is a project decision this platform has
 * no business making.
 *
 * NOTHING HERE IS VERIFIED
 * ------------------------
 * None of these standards is in the repo. Every scope figure comes from the
 * standard's title and industry usage, so every row carries `verified: false`,
 * and `scopeIsCitable()` exists so a caller can ask before presenting a
 * finding as a standard's requirement. This is the allowable-stress precedent:
 * hold the value, report it, do not cite it.
 */
final class ValveConstruction
{
    private const REF_ID = 'valve-construction';

    /** @var array<string,mixed>|null */
    private ?array $pack = null;

    public function __construct(private readonly string $refDir)
    {
    }

    /**
     * Every construction standard the pack holds.
     *
     * @return list<array{code:string,title:array{en:string,fa:string},valveTypes:list<string>,bodyGroups:list<string>|null,npsMin:float|null,npsMax:float|null,verified:bool}>
     */
    public function all(): array
    {
        return array_map(static fn (array $s): array => [
            'code' => $s['code'],
            'title' => $s['title'],
            'valveTypes' => $s['valveTypes'],
            'bodyGroups' => $s['bodyGroups'],
            'npsMin' => $s['npsMin'] === null ? null : (float) $s['npsMin'],
            'npsMax' => $s['npsMax'] === null ? null : (float) $s['npsMax'],
            'verified' => (bool) $s['verified'],
        ], $this->pack()['standards']);
    }

    /**
     * THE DROPDOWN. Standards that cover this valve type.
     *
     * [] is a real answer: a needle or diaphragm valve has no construction
     * standard here, exactly as it has no trim chart in the material library.
     * Two packs agreeing on a gap is worth more than either filling it.
     *
     * @return list<array<string,mixed>>
     */
    public function forValveType(string $valveType): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $s): bool => in_array($valveType, $s['valveTypes'], true),
        ));
    }

    /**
     * The same list narrowed to one size, which is what actually makes API 602
     * disappear at NPS 6 and API 600 disappear at NPS 48.
     *
     * @return list<array<string,mixed>>
     */
    public function forValveTypeAtSize(string $valveType, float $nps): array
    {
        return array_values(array_filter(
            $this->forValveType($valveType),
            fn (array $s): bool => $this->coversSize($s, $nps),
        ));
    }

    /** @return array<string,mixed>|null */
    public function get(string $code): ?array
    {
        foreach ($this->all() as $s) {
            if ($s['code'] === $this->normalise($code)) {
                return $s;
            }
        }

        return null;
    }

    public function isKnown(string $code): bool
    {
        return $this->get($code) !== null;
    }

    /** Does this standard cover this valve type at all? */
    public function covers(string $code, string $valveType): bool
    {
        $s = $this->get($code);

        return $s !== null && in_array($valveType, $s['valveTypes'], true);
    }

    /**
     * Is this size inside the standard's scope? True when the standard states
     * no bound — an absent bound means "the standard does not turn on size",
     * never "no sizes".
     */
    public function coversNps(string $code, float $nps): bool
    {
        $s = $this->get($code);

        return $s !== null && $this->coversSize($s, $nps);
    }

    /**
     * May a finding built on this standard's SIZE SCOPE be stated as the
     * standard's own requirement?
     *
     * Written when the answer was no for every row, with the flag read rather
     * than assumed "so that the day a copy of API 600 is bought, one data
     * change makes the findings citable and no code changes". That day came:
     * API 600-2021 is held and true here; the other twelve are still guesses.
     */
    public function scopeIsCitable(string $code): bool
    {
        $s = $this->get($code);

        return $s !== null && $s['verified'] === true;
    }

    /**
     * Which product forms this standard's body is made in, or null where the
     * standard does not turn on it.
     *
     * This method used to carry the sentence "API 600 is a cast body, API 602
     * is its forged small-bore counterpart". **That was wrong.** Table D.1 of
     * API 600-2021 gives the Group 1.1 body as "C-Si A105 or A216-WCB" - a
     * forging and a casting in the same row - and 7.3 speaks of "the shell of
     * a cast or forged... valve". The guess was raising
     * BODY_FORM_AGAINST_STANDARD on every legitimate forged gate valve. It is
     * left recorded in the pack under correctedFromGuess rather than deleted.
     *
     * @return list<string>|null
     */
    public function bodyGroups(string $code): ?array
    {
        return $this->get($code)['bodyGroups'] ?? null;
    }

    /**
     * How good the copy behind this entry is: NOT_HELD, TEXT, or
     * SCANNED_IMAGE.
     *
     * Owning a standard is not the same as being able to read it. API 600-2021
     * is a 109 dpi scan: the prose OCRs cleanly, so the scope is verified, and
     * the numeric tables do not, so none of them is transcribed. A consumer
     * that wants a wall thickness needs to know the difference.
     */
    public function sourceQuality(string $code): string
    {
        return $this->raw($code)['sourceQuality'] ?? 'NOT_HELD';
    }

    /**
     * What a held-but-unreadable copy could not give, and what would fix it.
     *
     * @return array<string,mixed>|null
     */
    public function tablesNotTranscribed(string $code): ?array
    {
        return $this->raw($code)['tablesNotTranscribed'] ?? null;
    }

    /**
     * The guesses this entry corrected when a real copy arrived. The return on
     * the verified:false discipline, stated in data: it shows which findings
     * the platform was raising wrongly, and which fields they came from.
     *
     * @return array<string,mixed>
     */
    public function correctedFromGuess(string $code): array
    {
        return $this->raw($code)['correctedFromGuess'] ?? [];
    }

    /**
     * The ASME B16.34 MATERIAL GROUPS this standard's body may be made from,
     * or null where it does not turn on them.
     *
     * A sharper question than `bodyGroups()`, and for some standards the only
     * one that means anything. Three standards have now been read and none
     * restricts the product form - API 600, 602 and 603 all say "cast or
     * forged". API 603 restricts something else: Table 4 gives the body as
     * "As selected from ASME B16.34, Group 2 and Group 3", which is what
     * "corrosion-resistant" in its title actually means. A Group 1 carbon
     * steel body is outside API 603 however it was made, and that is now
     * checkable because valve-pt-ratings holds all 50 groups.
     *
     * @return list<string>|null
     */
    public function bodyMaterialGroups(string $code): ?array
    {
        return $this->raw($code)['bodyMaterialGroups'] ?? null;
    }

    /**
     * The design variants this standard recognises, or null.
     *
     * API 608 clause 1.4 covers "floating (seat-supported) ball ... and
     * trunnion ball valve designs". Those are the two names API 598 asks for
     * before it will say which tests a ball valve takes - the construction
     * pack supplying the vocabulary the testing pack was missing.
     *
     * @return list<string>|null
     */
    public function designVariants(string $code): ?array
    {
        return $this->raw($code)['designVariants'] ?? null;
    }

    /** The test standard this construction standard mandates, if it names one. */
    public function testedTo(string $code): ?string
    {
        return $this->raw($code)['testedTo'] ?? null;
    }

    public function provenance(): string
    {
        $p = $this->pack();

        return $p['version'].' ('.$p['source'].')';
    }

    // ---------------------------------------------------------- internals --

    /**
     * The entry as the pack holds it, not as `all()` projects it.
     *
     * `all()` deliberately narrows every standard to one fixed key set - that
     * projection is a contract the dropdowns rely on - so a field added to the
     * pack is invisible through it. Anything reading a newer field has to come
     * through here, or it silently reads a default forever.
     *
     * @return array<string,mixed>
     */
    private function raw(string $code): array
    {
        foreach ($this->pack()['standards'] as $s) {
            if ($s['code'] === $this->normalise($code)) {
                return $s;
            }
        }

        return [];
    }

    /** @param array<string,mixed> $s */
    private function coversSize(array $s, float $nps): bool
    {
        if ($s['npsMin'] !== null && $nps < $s['npsMin']) {
            return false;
        }

        return ! ($s['npsMax'] !== null && $nps > $s['npsMax']);
    }

    /**
     * 'API600', 'api 600' and 'API  600' all mean API 600. A transcriber types
     * what the document shows and documents are not consistent about the
     * space — refusing to recognise 'API600' would turn a formatting
     * difference into an UNKNOWN finding, which is noise, not a discrepancy.
     */
    private function normalise(string $code): string
    {
        $c = strtoupper(trim(preg_replace('/\s+/', ' ', $code) ?? $code));

        return (string) preg_replace('/^(API|BS|ISO)\s?(\d)/', '$1 $2', $c);
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
