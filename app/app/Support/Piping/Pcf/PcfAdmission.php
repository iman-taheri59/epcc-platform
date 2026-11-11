<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

use App\Support\Piping\BranchConnectionType;
use InvalidArgumentException;

/** Bounded, read-only intake for PCF text; does not authorize model writes. */
final class PcfAdmission
{
    public const MAX_BYTES = 5_000_000;

    public const MAX_LINES = 100_000;

    public const MAX_COMPONENTS = 20_000;

    public function __construct(private readonly PcfParser $parser) {}

    public function parse(string $text): PcfFile
    {
        if (strlen($text) > self::MAX_BYTES || str_contains($text, "\0")) {
            throw new InvalidArgumentException('PCF content is too large or contains a NUL byte.');
        }
        if (substr_count($text, "\n") + 1 > self::MAX_LINES) {
            throw new InvalidArgumentException('PCF line limit exceeded.');
        }

        // The source parser uses last-header-wins. Conflicting unit declarations
        // must not silently reinterpret coordinates or bore.
        foreach (['UNITS-BORE', 'UNITS-CO-ORDS'] as $keyword) {
            if (preg_match_all('/^\s*'.preg_quote($keyword, '/').'\s+/mi', $text) > 1) {
                throw new InvalidArgumentException('Duplicate PCF unit declaration.');
            }
        }

        $file = $this->parser->parse($text);
        if (count($file->components) > self::MAX_COMPONENTS) {
            throw new InvalidArgumentException('PCF component limit exceeded.');
        }
        if ($file->lineNumber() === null) {
            throw new InvalidArgumentException('PCF line reference is required.');
        }
        if (! in_array(strtoupper((string) $file->unitsBore()), ['MM', 'INCH', 'INCH-SIXTEENTH'], true)) {
            throw new InvalidArgumentException('Explicit supported PCF bore unit is required.');
        }
        if (! in_array(strtoupper((string) $file->header('UNITS-CO-ORDS')), ['MM', 'MM-HUNDREDTHS', 'INCH', 'INCH-SIXTEENTH'], true)) {
            throw new InvalidArgumentException('Explicit supported PCF coordinate unit is required.');
        }
        foreach ($file->components as $component) {
            foreach ($component->keypoints as $point) {
                foreach ([$point->x, $point->y, $point->z, $point->bore] as $number) {
                    if ($number !== null && ! is_finite($number)) {
                        throw new InvalidArgumentException('PCF keypoint contains a non-finite number.');
                    }
                }
                if ($point->bore !== null && $point->bore <= 0) {
                    throw new InvalidArgumentException('PCF keypoint bore must be positive.');
                }
            }
        }

        return $this->withUnrepresentable($file);
    }

    /**
     * Report PCF connections that this read-only boundary cannot apply to the
     * model. It must never silently reshape a branch into an ordinary run.
     *
     * Standard TAP-CONNECTIONs are normalized by PcfFile in both forms: an
     * attribute within the host component, or a separate entry whose
     * UNIQUE-COMPONENT-IDENTIFIER names the host. Legacy TAP-POINT is not a
     * PCF keyword and is reported as unsupported rather than treated as a tap.
     */
    private function withUnrepresentable(PcfFile $file): PcfFile
    {
        $extra = [];
        $line = $file->lineNumber();
        $hostsByUci = [];

        foreach ($file->materialComponents() as $component) {
            $uci = $component->attribute('UNIQUE-COMPONENT-IDENTIFIER');

            if ($uci !== null) {
                $hostsByUci[strtolower($uci)][] = $component;
            }
        }

        foreach ($file->components as $component) {
            $own = $component->attribute('PIPELINE-REFERENCE');

            if ($own !== null && $line !== null && strcasecmp($own, $line) !== 0) {
                $extra[] = new PcfWarning(
                    $component->sourceLine,
                    'cross_line_component',
                    $own,
                );
            }

            $branch = PcfBranchClassification::fromComponent($component);

            if (($branch['connection_type'] ?? null) === BranchConnectionType::P2P) {
                $skey = $component->attribute('SKEY');
                $extra[] = new PcfWarning(
                    $component->sourceLine,
                    'branch_on_header_wall',
                    $component->type.($skey === null ? '' : ' '.$skey),
                );
            } elseif (PcfBranchClassification::isBranchLikeType($component->type)) {
                // Generic OLET, multi-leg CROSS-* and a TEE type without a
                // discriminating SKEY must not pass as ordinary components.
                $skey = $component->attribute('SKEY');
                $extra[] = new PcfWarning(
                    $component->sourceLine,
                    'branch_type_unresolved',
                    $component->type.($skey === null ? '' : ' '.$skey),
                );
            }

            if (strtoupper($component->type) === 'TAP-POINT' || $component->hasAttribute('TAP-POINT')) {
                $extra[] = new PcfWarning(
                    $component->sourceLine,
                    'unsupported_tap_point',
                    'TAP-POINT is not a PCF keyword; use TAP-CONNECTION.',
                );
            }
        }

        foreach ($file->tapConnections() as $tap) {
            $hostUci = $tap->hostUniqueComponentIdentifier;
            $detail = $hostUci ?? $tap->hostComponent?->type ?? '';

            $extra[] = new PcfWarning($tap->sourceLine, 'tap_connection', $detail);

            if (! $tap->hasLocation()) {
                $extra[] = new PcfWarning(
                    $tap->sourceLine,
                    'tap_connection_missing_coordinates',
                    $detail,
                );
            }

            if (! $tap->separateEntry) {
                continue; // association with the containing component is explicit
            }

            if ($hostUci === null) {
                $extra[] = new PcfWarning(
                    $tap->sourceLine,
                    'tap_host_reference_missing',
                    'A separate TAP-CONNECTION requires the host UCI.',
                );

                continue;
            }

            $matches = $hostsByUci[strtolower($hostUci)] ?? [];

            if ($matches === []) {
                $extra[] = new PcfWarning($tap->sourceLine, 'tap_host_not_found', $hostUci);
            } elseif (count($matches) > 1) {
                $extra[] = new PcfWarning($tap->sourceLine, 'tap_host_ambiguous', $hostUci);
            }
        }

        if ($extra === []) {
            return $file;
        }

        return new PcfFile(
            $file->header,
            $file->components,
            [...$file->warnings, ...$extra],
        );
    }
}
