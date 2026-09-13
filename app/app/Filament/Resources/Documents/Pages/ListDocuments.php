<?php

declare(strict_types=1);

namespace App\Filament\Resources\Documents\Pages;

use App\Filament\Resources\Documents\DocumentResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No CreateAction. A document must be born through DocumentRegister so that it
 * receives a number from the project counter and an opening WIP revision;
 * a bare Eloquent insert from an admin form would produce a document with
 * neither, which every downstream screen then has to defend against.
 *
 * Creating deliverables is the job of /projects/{code}/documents.
 */
class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
