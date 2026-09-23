<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Jobs\EmitCiotJob;
use App\Services\Ciot\EmitCiotDeclaration;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Notifications\Notification;
use Throwable;

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
                    $record = $this->getRecord();

                    try {
                        $record = app(EmitCiotDeclaration::class)->handle($record, sync: true);
                    } catch (Throwable $exception) {
                        report($exception);

                        EmitCiotJob::dispatch($record->id);

                        Notification::make()
                            ->title('Emissão em processamento')
                            ->body('A ANTT não respondeu agora; a emissão ficou na fila com retry automático.')
                            ->warning()
                            ->send();

                        $this->redirect(CiotResource::getUrl('view', ['record' => $record]));

                        return;
                    }

                    $record->refresh();

                    if ($record->status === CiotStatusEnum::ISSUED) {
                        Notification::make()
                            ->title('CIOT emitido: '.$record->fullNumber())
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Emissão rejeitada pela ANTT')
                            ->body((string) $record->error_message)
                            ->danger()
                            ->send();
                    }

                    $this->redirect(CiotResource::getUrl('view', ['record' => $record]));
                }),
        ];
    }
}
