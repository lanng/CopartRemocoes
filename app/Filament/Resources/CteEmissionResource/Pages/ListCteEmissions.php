<?php

namespace App\Filament\Resources\CteEmissionResource\Pages;

use App\Filament\Resources\CteEmissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCteEmissions extends ListRecords
{
    protected static string $resource = CteEmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo CT-e de álcool'),
        ];
    }
}
