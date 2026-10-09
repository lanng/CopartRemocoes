<?php

namespace App\Filament\Resources\MdfeDocumentResource\Pages;

use App\Filament\Resources\MdfeDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMdfeDocuments extends ManageRecords
{
    protected static string $resource = MdfeDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Preparar MDF-e'),
        ];
    }
}
