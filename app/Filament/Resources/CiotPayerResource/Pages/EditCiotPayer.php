<?php

namespace App\Filament\Resources\CiotPayerResource\Pages;

use App\Filament\Resources\CiotPayerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditCiotPayer extends EditRecord
{
    protected static string $resource = CiotPayerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
