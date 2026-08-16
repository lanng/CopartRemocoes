<?php

namespace App\Filament\Resources\IntegrationInboxItemResource\Pages;

use App\Filament\Resources\IntegrationInboxItemResource;
use Filament\Resources\Pages\ListRecords;

class ListIntegrationInboxItems extends ListRecords
{
    protected static string $resource = IntegrationInboxItemResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
