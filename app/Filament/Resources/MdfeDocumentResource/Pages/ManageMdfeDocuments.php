<?php

namespace App\Filament\Resources\MdfeDocumentResource\Pages;

use App\Filament\Actions\DownloadFiscalXmlsAction;
use App\Filament\Resources\MdfeDocumentResource;
use App\Models\MdfeDocument;
use Closure;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageMdfeDocuments extends ManageRecords
{
    protected static string $resource = MdfeDocumentResource::class;

    protected function getTableRecordUrlUsing(): ?Closure
    {
        return fn (MdfeDocument $record): string => MdfeDocumentResource::getUrl('view', ['record' => $record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DownloadFiscalXmlsAction::make(MdfeDocumentResource::class, 'mdfe'),
            Actions\CreateAction::make()->label('Preparar MDF-e'),
        ];
    }
}
