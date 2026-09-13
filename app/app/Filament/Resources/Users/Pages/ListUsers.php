<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    /**
     * No "New user" button.
     *
     * Provisioning an account is a tenant-scoped, audited operation that runs
     * through the team screen and the authorization pipeline. A create form
     * here would write a row with whatever company_id the form carried.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
