<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

/**
 * A standard PCF tap, still read-only and in the file's coordinate units.
 *
 * The host is either the component whose block carries the TAP-CONNECTION
 * attribute, or a component found by UNIQUE-COMPONENT-IDENTIFIER when the tap
 * is a separate entry. This object resolves only that file-level reference;
 * it does not assert geometric or engineering compatibility.
 */
final readonly class PcfTapConnection
{
    public function __construct(
        public ?PcfKeypoint $location,
        public ?string $hostUniqueComponentIdentifier,
        public ?PcfComponent $hostComponent,
        public int $sourceLine,
        public bool $separateEntry,
    ) {}

    public function hasLocation(): bool
    {
        return $this->location?->hasCoordinates() ?? false;
    }
}
