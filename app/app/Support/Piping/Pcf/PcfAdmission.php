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
            if (preg_match_all('/^\\s*'.preg_quote($keyword, '/').'\\s+/mi', $text) > 1) {
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
     * Report what the file says that the joint model cannot hold.
     *
     * This layer writes nothing, so it can never break the model. What it can
     * do — and what it did before this existed — is say nothing while the
     * design quietly becomes something it is not. A PCF that ties into another
     * line, or lands a branch on a header wall, used to import with zero
     * warnings and be treated as an ordinary run of the file's own line. That
     * is worse than a refusal: a refused file sends someone to look, a
     * silently reshaped one does not.
     *
     * Both shapes of pipe-to-pipe branch are caught, because PCF writes them
     * two different ways: a fitting-less branch carries a TAP-POINT, while the
     * set-on and set-in variants are whole components whose centre sits on the
     * header. The classification already knows which types those are, so it is
     * reused rather than a second list of type names that would drift from it.
     */
    private function withUnrepresentable(PcfFile $file): PcfFile
    {
        $extra = [];
        $line = $file->lineNumber();

        foreach ($file->components as $component) {
            $own = $component->attribute('PIPELINE-REFERENCE');

            if ($own !== null && $line !== null && strcasecmp($own, $line) !== 0) {
                $extra[] = new PcfWarning(
                    $component->sourceLine,
                    'cross_line_component',
                    $own,
                );
            }

            $branch = PcfBranchClassification::fromType($component->type);

            if (($branch['connection_type'] ?? null) === BranchConnectionType::P2P) {
                $extra[] = new PcfWarning(
                    $component->sourceLine,
                    'branch_on_header_wall',
                    $component->type,
                );
            }

            foreach ($component->keypoints as $point) {
                if ($point->kind === 'TAP-POINT') {
                    $extra[] = new PcfWarning(
                        $point->sourceLine,
                        'tap_connection',
                        $component->type,
                    );
                }
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
