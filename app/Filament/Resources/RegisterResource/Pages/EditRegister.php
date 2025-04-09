<?php

namespace App\Filament\Resources\RegisterResource\Pages;

use App\Filament\Resources\RegisterResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRegister extends EditRecord
{
    protected static string $resource = RegisterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
