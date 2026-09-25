<?php

namespace App\Filament\Resources\CiotVehicleResource\Pages;

use App\Filament\Resources\CiotVehicleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCiotVehicles extends ListRecords
{
    protected static string $resource = CiotVehicleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
