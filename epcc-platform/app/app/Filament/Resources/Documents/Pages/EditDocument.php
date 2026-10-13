<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No DeleteAction. A controlled document register does not delete - revisions
 * supersede and states archive. The scaffold shipped one here AND a bulk
 * delete on the table; removing only the table button would have left the
 * capability intact one click deeper.
 */
class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
