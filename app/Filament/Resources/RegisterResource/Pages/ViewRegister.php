<?php

namespace App\Filament\Resources\RegisterResource\Pages;

use App\Filament\Resources\RegisterResource;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Resources\Pages\ViewRecord;

class ViewRegister extends ViewRecord
{
    protected static string $resource = RegisterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }
}
