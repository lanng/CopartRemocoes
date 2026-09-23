<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CiotResource;
use App\Jobs\EmitCiotJob;
use App\Services\Ciot\EmitCiotDeclaration;
use Filament\Actions;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Notifications\Notification;
use Throwable;

class EditCiot extends EditRecord
{
    protected static string $resource = CiotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make()->label('Visualizar'),
            Actions\DeleteAction::make()->label('Excluir'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * @return list<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('saveAndEmit')
                ->label('Salvar e emitir')
                ->color('success')
                ->icon('heroicon-m-paper-airplane')
                ->action('saveAndEmit'),
            ...parent::getFormActions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CiotResource::transformFormData($data);
    }

    /**
     * Salva as correções e envia a declaração à ANTT na hora: o resultado
     * (emitido/falha) aparece no infolist para o qual redirecionamos.
     */
    public function saveAndEmit(): void
    {
        $this->save();

        $record = $this->getRecord();

        try {
            $record = app(EmitCiotDeclaration::class)->handle($record, sync: true);
        } catch (Throwable $exception) {
            report($exception);

            EmitCiotJob::dispatch($record->id);

            Notification::make()
                ->title('CIOT salvo — emissão em processamento')
                ->body('A ANTT não respondeu agora; a emissão ficou na fila com retry automático.')
                ->warning()
                ->send();

            $this->redirect($this->getRedirectUrl());

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

        $this->redirect($this->getRedirectUrl());
    }
}
