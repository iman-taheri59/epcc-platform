<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

/**
 * Parser for the Piping Component File (PCF) - ISOGEN's own input format.
 *
 * WHY THIS FORMAT. The 3D model itself cannot be read: NWD/NWF/NWC are
 * proprietary, Windows-only and undocumented (ADR-005 §1.1). PCF is the way
 * out - plain text, line-oriented, and emitted by Plant 3D, CADWorx, AVEVA,
 * SmartPlant, Creo and 3DEXPERIENCE alike, so supporting it is not a bet on one
 * vendor. It carries exactly what construction needs: every component, its
 * bore, its end points, its item code and its spec.
 *
 * STRUCTURE. Indentation is the only nesting: a line starting in column 1
 * opens a block, indented lines are that block's attributes.
 *
 *     PIPE
 *         END-POINT 0.0 0.0 0.0 6.0
 *         END-POINT 1000.0 0.0 0.0 6.0
 *         ITEM-CODE PIPE-CS-A106B
 *
 * THIS CLASS IS PURE. No database, no models, no validation against project
 * data - it turns text into a structure and nothing else. That separation is
 * deliberate: parsing bugs and import bugs have completely different causes,
 * and a parser that needs a tenant context cannot be tested on a fixture file.
 *
 * WHAT IT DOES NOT DO. It does not judge whether a component is sensible, does
 * not resolve bores to NPS (that needs the reference pack and the header's
 * UNITS-BORE), and does not decide what to do about anything unrecognised. It
 * reports; the importer decides.
 */
final class PcfParser
{
    /**
     * Keywords that are file-level metadata rather than components.
     *
     * Anything NOT in this list and not indented is treated as a component
     * block. That is the right default: the format has no closed component
     * vocabulary, vendors add their own, and a parser that only understood a
     * fixed list would silently drop whatever it had not met before.
     *
     * @var list<string>
     */
    private const HEADER_KEYWORDS = [
        'ISOGEN-FILES', 'UNITS-BORE', 'UNITS-CO-ORDS', 'UNITS-BOLT-DIA',
        'UNITS-BOLT-LENGTH', 'UNITS-WEIGHT', 'UNITS-WEIGHT-LENGTH',
        'PIPELINE-REFERENCE', 'PIPING-SPEC', 'AREA', 'PROJECT-IDENTIFIER',
        'REVISION', 'DATE-DMY', 'JOB-NUMBER', 'BATCH-NUMBER', 'LINE-NUMBER',
        'ATTRIBUTE1', 'ATTRIBUTE2', 'ATTRIBUTE3', 'ATTRIBUTE4', 'ATTRIBUTE5',
        'START-CO-ORDS', 'FINISH-CO-ORDS', 'MATERIALS', 'MISC-COMPONENT-INFO',
        'PIPELINE-HEADER',
    ];

    /**
     * Keypoint keywords, i.e. the lines that define a component's ports.
     *
     * A TEE-SET-ON really does emit END-POINT, END-POINT and BRANCH1-POINT -
     * three keypoints for a three-way fitting. This is why PCF beats a flat
     * MTO: the connectivity is in the file, not inferred from coordinates
     * afterwards.
     *
     * @var list<string>
     */
    private const KEYPOINTS = [
        'END-POINT', 'BRANCH1-POINT', 'BRANCH2-POINT', 'BRANCH3-POINT',
        'CENTRE-POINT', 'CO-ORDS', 'PORT-POINT', 'TAP-POINT',
    ];

    /** Keypoints that are a real connection rather than geometry. */
    private const CONNECTING_KEYPOINTS = [
        'END-POINT', 'BRANCH1-POINT', 'BRANCH2-POINT', 'BRANCH3-POINT', 'PORT-POINT',
    ];

    public function parse(string $text): PcfFile
    {
        $header = [];
        $components = [];
        $warnings = [];

        /** @var array<string,mixed>|null $current */
        $current = null;

        // True while we are inside the pipeline header block. Real files
        // INDENT the header's own fields under PIPELINE-REFERENCE:
        //
        //     PIPELINE-REFERENCE 6-P-A1-...
        //         PIPING-SPEC      A1
        //         REVISION         B
        //
        // Without this the spec and the revision are read as orphans and the
        // file looks like it has no spec at all - which is worse than an
        // error, because the import then succeeds with a blank spec.
        $inHeaderBlock = false;
        $lineNo = 0;

        foreach (preg_split('/\R/', $text) ?: [] as $raw) {
            $lineNo++;

            // A PCF may arrive with a UTF-8 BOM from a Windows export; left in
            // place it corrupts the very first keyword, which is usually
            // ISOGEN-FILES, and the file then looks headerless.
            if ($lineNo === 1) {
                $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
            }

            if (trim($raw) === '' || str_starts_with(ltrim($raw), '!')) {
                continue; // blank, or a comment
            }

            $indented = $raw !== ltrim($raw);
            $tokens = preg_split('/\s+/', trim($raw)) ?: [];
            $keyword = strtoupper((string) array_shift($tokens));
            $rest = implode(' ', $tokens);

            if (! $indented) {
                if (in_array($keyword, self::HEADER_KEYWORDS, true)) {
                    // A component block ends when the next unindented line
                    // starts - including a header keyword, which some exporters
                    // emit mid-file (MATERIALS especially).
                    if ($current !== null) {
                        $components[] = $this->finishComponent($current);
                        $current = null;
                    }

                    $header[$keyword] = $rest;
                    $inHeaderBlock = true;

                    continue;
                }

                if ($current !== null) {
                    $components[] = $this->finishComponent($current);
                }

                $inHeaderBlock = false;

                $current = [
                    'type' => $keyword,
                    'line' => $lineNo,
                    'keypoints' => [],
                    'attributes' => [],
                ];

                continue;
            }

            if ($current === null && $inHeaderBlock) {
                // An indented field of the pipeline header. Flattened into the
                // same header map, because nothing downstream cares whether
                // the exporter indented it or not.
                $header[$keyword] = $rest;

                continue;
            }

            if ($current === null) {
                // Indented data before any block. Reported rather than
                // discarded: it usually means the file was truncated or
                // re-indented by an editor, and silence would make the
                // resulting short import look like a modelling problem.
                $warnings[] = new PcfWarning($lineNo, 'orphan_attribute', $keyword);

                continue;
            }

            if (in_array($keyword, self::KEYPOINTS, true)) {
                $current['keypoints'][] = $this->parseKeypoint($keyword, $tokens, $lineNo);

                continue;
            }

            // Repeated attributes are kept as a list. BOLT and several vendor
            // extensions legitimately repeat, and last-one-wins would quietly
            // lose bolts from the take-off.
            if (array_key_exists($keyword, $current['attributes'])) {
                $existing = $current['attributes'][$keyword];
                $current['attributes'][$keyword] = is_array($existing)
                    ? [...$existing, $rest]
                    : [$existing, $rest];
            } else {
                $current['attributes'][$keyword] = $rest;
            }
        }

        if ($current !== null) {
            $components[] = $this->finishComponent($current);
        }

        return new PcfFile($header, $components, $warnings);
    }

    /**
     * Parse one keypoint line: `END-POINT x y z bore [endtype]`.
     *
     * The bore is OPTIONAL in the wild. Exporters omit it on components that
     * inherit it, and a parser that demanded five tokens would reject real
     * files; the importer decides what a missing bore means.
     *
     * @param  list<string>  $tokens
     */
    private function parseKeypoint(string $keyword, array $tokens, int $lineNo): PcfKeypoint
    {
        $numeric = [];
        $endType = null;

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $numeric[] = (float) $token;

                continue;
            }

            // The first non-numeric token after the coordinates is the end
            // type (BW, SW, FL...). Later ones are modifiers such as MALE,
            // FEMALE or INSULATION-SINGLE, which we keep out of the end type.
            $endType ??= strtoupper($token);
        }

        return new PcfKeypoint(
            kind: $keyword,
            x: $numeric[0] ?? null,
            y: $numeric[1] ?? null,
            z: $numeric[2] ?? null,
            bore: $numeric[3] ?? null,
            endType: $endType,
            sourceLine: $lineNo,
            connecting: in_array($keyword, self::CONNECTING_KEYPOINTS, true),
        );
    }

    /** @param array<string,mixed> $raw */
    private function finishComponent(array $raw): PcfComponent
    {
        /** @var array<string,mixed> $attributes */
        $attributes = $raw['attributes'];

        /** @var list<PcfKeypoint> $keypoints */
        $keypoints = $raw['keypoints'];

        return new PcfComponent(
            type: (string) $raw['type'],
            keypoints: $keypoints,
            attributes: $attributes,
            sourceLine: (int) $raw['line'],
        );
    }
}
