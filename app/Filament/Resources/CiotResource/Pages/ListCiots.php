<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Filament\Resources\CiotResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCiots extends ListRecords
{
    protected static string $resource = CiotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
