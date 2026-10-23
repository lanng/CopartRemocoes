<?php

namespace App\Filament\Resources\CteEmissionResource\Pages;

use App\Filament\Actions\DownloadFiscalXmlsAction;
use App\Filament\Resources\CteEmissionResource;
use App\Models\CteDocument;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCteEmissions extends ListRecords
{
    protected static string $resource = CteEmissionResource::class;

    protected function getTableRecordUrlUsing(): ?Closure
    {
        return fn (CteDocument $record): string => CteEmissionResource::getUrl('view', ['record' => $record]);
    }

    protected function getHeaderActions(): array
    {
        return [
            DownloadFiscalXmlsAction::make(CteEmissionResource::class, 'cte'),
            Action::make('createGeneralCargo')
                ->label('Novo CT-e avulso')
                ->icon('heroicon-o-plus')
                ->url(CteEmissionResource::getUrl('create-avulso')),
            CreateAction::make()->label('Novo CT-e de álcool'),
        ];
    }
}
