<?php

declare(strict_types=1);

namespace App\Filament\Resources\Subsystems\Pages;

use App\Filament\Resources\Subsystems\SubsystemResource;
use Filament\Resources\Pages\ListRecords;

class ListSubsystems extends ListRecords
{
    protected static string $resource = SubsystemResource::class;

    /** Subsystem breakdown is imported from system engineering, not typed. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
