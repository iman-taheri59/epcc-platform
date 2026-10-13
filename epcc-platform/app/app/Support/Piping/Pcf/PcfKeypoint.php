<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

/**
 * One keypoint of a PCF component - which is to say, one port.
 *
 * Coordinates are kept in the file's own units and NOT converted here. The
 * conversion needs UNITS-CO-ORDS from the header, and a value silently
 * converted twice is unfindable later.
 */
final readonly class PcfKeypoint
{
    public function __construct(
        public string $kind,
        public ?float $x,
        public ?float $y,
        public ?float $z,
        public ?float $bore,
        public ?string $endType,
        public int $sourceLine,
        public bool $connecting,
    ) {}

    public function hasCoordinates(): bool
    {
        return $this->x !== null && $this->y !== null && $this->z !== null;
    }

    /**
     * A rounded coordinate key, used to find which keypoints touch.
     *
     * Rounded because two components that meet were written by the modeller's
     * geometry engine, not by hand: they agree to within floating-point noise,
     * never exactly. The tolerance is applied by the caller in the file's own
     * units - the importer knows whether these are millimetres or hundredths.
     */
    public function coordinateKey(float $tolerance): ?string
    {
        if (! $this->hasCoordinates()) {
            return null;
        }

        $snap = static fn (float $v): string => (string) (int) round($v / $tolerance);

        return $snap((float) $this->x).':'.$snap((float) $this->y).':'.$snap((float) $this->z);
    }
}
