<?php

declare(strict_types=1);

namespace App\Support\Piping;

/** ASME B31.3-2024 §300.2 services; severe cyclic is a separate condition. */
final class FluidServiceCategory
{
    public const ALL = [
        'NORMAL', 'CATEGORY_D', 'CATEGORY_M', 'ELEVATED_TEMPERATURE',
        'HIGH_PRESSURE', 'HIGH_PURITY',
    ];
}
