<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Filament\Resources\CiotResource;
use Filament\Resources\Pages\ViewRecord;

class ViewCiot extends ViewRecord
{
    protected static string $resource = CiotResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
