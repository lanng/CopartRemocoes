<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Filament\Support\CiotEmissionNotice;
use App\Services\Ciot\EmitCiotDeclaration;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewCiot extends ViewRecord
{
    protected static string $resource = CiotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Editar')
                ->icon('heroicon-m-pencil-square')
                ->color('gray')
                ->url(fn (): string => CiotResource::getUrl('edit', ['record' => $this->getRecord()]))
                ->visible(fn (): bool => $this->getRecord()->status === CiotStatusEnum::DRAFT
                    || $this->getRecord()->status === CiotStatusEnum::FAILED),
            Action::make('emit')
                ->label('Emitir')
                ->icon('heroicon-m-paper-airplane')
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord()->status === CiotStatusEnum::DRAFT
                    || $this->getRecord()->status === CiotStatusEnum::FAILED)
                ->requiresConfirmation()
                ->modalHeading('Emitir CIOT na ANTT')
                ->modalDescription('A declaração será enviada agora; o botão fica em loading até a resposta da ANTT.')
                ->action(function (): void {
                    $result = app(EmitCiotDeclaration::class)->emit($this->getRecord());

                    (new CiotEmissionNotice(queuedTitle: 'Emissão em processamento'))->send($result);

                    $this->redirect(CiotResource::getUrl('view', ['record' => $result->ciot]));
                }),
        ];
    }
}
