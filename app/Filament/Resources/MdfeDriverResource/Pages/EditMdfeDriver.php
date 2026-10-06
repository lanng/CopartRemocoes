<?php

namespace App\Filament\Resources\MdfeDriverResource\Pages;

use App\Filament\Resources\MdfeDriverResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMdfeDriver extends EditRecord
{
    protected static string $resource = MdfeDriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
