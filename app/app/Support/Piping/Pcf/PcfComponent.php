<?php

declare(strict_types=1);

namespace App\Support\Piping\Pcf;

/** One component block from a PCF file, still in the file's own vocabulary. */
final readonly class PcfComponent
{
    /**
     * @param  list<PcfKeypoint>  $keypoints
     * @param  array<string,mixed>  $attributes
     */
    public function __construct(
        public string $type,
        public array $keypoints,
        public array $attributes,
        public int $sourceLine,
    ) {}

    public function attribute(string $key): ?string
    {
        $value = $this->attributes[strtoupper(trim($key))] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        $value = is_string($value) ? trim($value) : null;

        return ($value === null || $value === '') ? null : $value;
    }

    public function hasAttribute(string $key): bool
    {
        return array_key_exists(strtoupper(trim($key)), $this->attributes);
    }

    /** @return list<PcfKeypoint> */
    public function connectingKeypoints(): array
    {
        return array_values(array_filter($this->keypoints, fn (PcfKeypoint $keypoint): bool => $keypoint->connecting));
    }

    public function isTapConnectionEntry(): bool
    {
        return strtoupper($this->type) === 'TAP-CONNECTION';
    }

    /** @return list<PcfKeypoint> */
    public function tapConnectionKeypoints(): array
    {
        return array_values(array_filter(
            $this->keypoints,
            static fn (PcfKeypoint $point): bool => $point->kind === 'TAP-CONNECTION',
        ));
    }

    /**
     * Is this a weld rather than a piece of material?
     *
     * PCF makes welds first-class objects, which is a gift: the shop/field
     * split that construction cares about is already stated in the file as
     * CATEGORY FABRICATION vs ERECTION, instead of being guessed from whether
     * a joint happens to sit on a spool boundary.
     */
    public function isWeld(): bool
    {
        return strtoupper($this->type) === 'WELD';
    }

    /** SHOP or FIELD, from the weld's CATEGORY; null when not a weld or not stated. */
    public function weldCategory(): ?string
    {
        if (! $this->isWeld()) {
            return null;
        }

        return match (strtoupper((string) $this->attribute('CATEGORY'))) {
            'FABRICATION' => 'SHOP',
            'ERECTION' => 'FIELD',
            default => null,
        };
    }
}
