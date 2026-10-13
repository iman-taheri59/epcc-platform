<?php

declare(strict_types=1);

namespace App\Filament\Resources\PunchItems\Pages;

use App\Filament\Resources\PunchItems\PunchItemResource;
use Filament\Resources\Pages\ListRecords;

class ListPunchItems extends ListRecords
{
    protected static string $resource = PunchItemResource::class;

    /** Raising an item is a walkdown activity; see PunchItemResource. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
