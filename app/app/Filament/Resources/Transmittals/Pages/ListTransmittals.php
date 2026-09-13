<?php

declare(strict_types=1);

namespace App\Filament\Resources\Transmittals\Pages;

use App\Filament\Resources\Transmittals\TransmittalResource;
use Filament\Resources\Pages\ListRecords;

class ListTransmittals extends ListRecords
{
    protected static string $resource = TransmittalResource::class;

    /** Issuing a transmittal is TransmittalService's job. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
