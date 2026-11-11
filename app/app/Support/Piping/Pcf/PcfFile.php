<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

/** A parsed PCF: its header, its components, and what could not be understood. */
final readonly class PcfFile
{
    /**
     * @param  array<string,string>  $header
     * @param  list<PcfComponent>  $components
     * @param  list<PcfWarning>  $warnings
     */
    public function __construct(
        public array $header,
        public array $components,
        public array $warnings,
    ) {}

    public function header(string $key): ?string
    {
        $value = trim((string) ($this->header[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * The bore unit, which MUST be read and never assumed.
     *
     * A file in INCH-SIXTEENTH read as INCH turns a 6" line into a 96" line,
     * and nothing downstream would question it - the number is valid, just
     * catastrophically wrong. Defaulting is therefore not allowed: a file with
     * no UNITS-BORE is refused by the importer.
     */
    public function unitsBore(): ?string
    {
        return $this->header('UNITS-BORE');
    }

    /**
     * Coordinate units. MM-HUNDREDTHS is common from AVEVA and means the
     * numbers are 100x what they look like.
     */
    public function unitsCoords(): string
    {
        return strtoupper($this->header('UNITS-CO-ORDS') ?? 'MM');
    }

    /** Millimetres per coordinate unit, for the touch tolerance. */
    public function coordinateScaleMm(): float
    {
        return match ($this->unitsCoords()) {
            'MM-HUNDREDTHS' => 0.01,
            'INCH' => 25.4,
            'INCH-SIXTEENTH' => 25.4 / 16.0,
            default => 1.0,
        };
    }

    public function lineNumber(): ?string
    {
        // Exporters disagree about which key carries it, and both appear in
        // real files from the same vendor.
        return $this->header('PIPELINE-REFERENCE') ?? $this->header('LINE-NUMBER');
    }

    /** @return list<PcfComponent> */
    public function materialComponents(): array
    {
        return array_values(array_filter(
            $this->components,
            fn (PcfComponent $component): bool => ! $component->isWeld() && ! $component->isTapConnectionEntry(),
        ));
    }

    /** @return list<PcfComponent> */
    public function welds(): array
    {
        return array_values(array_filter($this->components, fn (PcfComponent $component): bool => $component->isWeld()));
    }

    /**
     * Normalize both PCF tap forms without turning a tap into a component port.
     *
     * A TAP-CONNECTION inside a component block is implicitly associated with
     * that component. A standalone TAP-CONNECTION block instead names its host
     * via UNIQUE-COMPONENT-IDENTIFIER. A missing point is retained so admission
     * can report it rather than silently dropping the tap.
     *
     * @return list<PcfTapConnection>
     */
    public function tapConnections(): array
    {
        $taps = [];
        $hostsByUci = [];

        foreach ($this->materialComponents() as $candidate) {
            $uci = $candidate->attribute('UNIQUE-COMPONENT-IDENTIFIER');

            if ($uci !== null) {
                $hostsByUci[strtolower($uci)][] = $candidate;
            }
        }

        foreach ($this->components as $component) {
            if ($component->isTapConnectionEntry()) {
                $location = null;

                foreach ($component->keypoints as $point) {
                    if (in_array($point->kind, ['CO-ORDS', 'TAP-CONNECTION'], true)) {
                        $location = $point;
                        break;
                    }
                }

                $hostUci = $component->attribute('UNIQUE-COMPONENT-IDENTIFIER');
                $hostMatches = $hostUci === null ? [] : ($hostsByUci[strtolower($hostUci)] ?? []);

                $taps[] = new PcfTapConnection(
                    location: $location,
                    hostUniqueComponentIdentifier: $hostUci,
                    hostComponent: count($hostMatches) === 1 ? $hostMatches[0] : null,
                    sourceLine: $component->sourceLine,
                    separateEntry: true,
                );

                continue;
            }

            foreach ($component->tapConnectionKeypoints() as $point) {
                $taps[] = new PcfTapConnection(
                    location: $point,
                    hostUniqueComponentIdentifier: $component->attribute('UNIQUE-COMPONENT-IDENTIFIER'),
                    hostComponent: $component,
                    sourceLine: $point->sourceLine,
                    separateEntry: false,
                );
            }
        }

        return $taps;
    }
}
