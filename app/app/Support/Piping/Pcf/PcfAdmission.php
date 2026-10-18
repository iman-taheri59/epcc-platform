<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

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

        return $file;
    }
}
