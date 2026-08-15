<?php

namespace App\Filament\Resources\CteEmissionBatchResource\Pages;

use App\Filament\Resources\CteEmissionBatchResource;
use Filament\Resources\Pages\ListRecords;

class ListCteEmissionBatches extends ListRecords
{
    protected static string $resource = CteEmissionBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
