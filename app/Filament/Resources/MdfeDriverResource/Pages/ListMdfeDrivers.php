<?php

namespace App\Filament\Resources\MdfeDriverResource\Pages;

use App\Filament\Resources\MdfeDriverResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMdfeDrivers extends ListRecords
{
    protected static string $resource = MdfeDriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
