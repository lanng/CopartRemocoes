<?php

namespace App\Filament\Resources\CteEmissionBatchResource\Pages;

use App\Enums\CiotStatusEnum;
use App\Filament\Actions\GenerateCiotForBatchAction;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Services\Ciot\DispatchMdfeForBatch;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewCteEmissionBatch extends ViewRecord
{
    protected static string $resource = CteEmissionBatchResource::class;

    public function refreshBatch(): void
    {
        $this->authorizeAccess();
        $this->getRecord()->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            GenerateCiotForBatchAction::make($this->getRecord()),
            Actions\Action::make('emitMdfe')
                ->label('Emitir MDF-e')
                ->icon('heroicon-m-document-check')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->record->refresh()->status?->value, ['completed', 'completed_with_errors'], true)
                    && $this->record->ciots()->where('status', CiotStatusEnum::ISSUED->value)->exists()
                    && ! $this->record->mdfeDocuments()
                        ->whereNotIn('status', ['rejected', 'failed_before_authorization', 'cancelled'])
                        ->exists())
                ->requiresConfirmation()
                ->modalHeading('Emitir MDF-e da viagem')
                ->modalDescription('Despacha o claim de MDF-e para o agente com o snapshot da viagem.')
                ->action(function (): void {
                    $mdfe = app(DispatchMdfeForBatch::class)->handle($this->record);

                    if ($mdfe === null) {
                        $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));

                        return;
                    }

                    Notification::make()
                        ->title('MDF-e enfileirado para o agente.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('requeueMdfe')
                ->label('Reenviar MDF-e')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->record->mdfeDocuments()
                    ->whereIn('status', ['rejected', 'failed_before_authorization'])
                    ->exists())
                ->requiresConfirmation()
                ->modalHeading('Reenviar MDF-e')
                ->modalDescription('O documento volta para a fila do agente para nova tentativa.')
                ->action(function (): void {
                    $this->record->mdfeDocuments()
                        ->whereIn('status', ['rejected', 'failed_before_authorization'])
                        ->each(fn ($mdfe) => $mdfe->forceFill([
                            'status' => 'queued',
                            'claimed_by' => null,
                            'claim_token_hash' => null,
                            'claimed_at' => null,
                            'claim_expires_at' => null,
                            'error_stage' => null,
                            'error_code' => null,
                            'error_message' => null,
                        ])->save());

                    Notification::make()
                        ->title('MDF-e reenfileirado.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('delete')
                ->label('Excluir lote')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status?->value === 'draft')
                ->requiresConfirmation()
                ->modalHeading('Excluir lote')
                ->modalDescription('Os documentos deste lote serão removidos. Os registros originais permanecerão disponíveis.')
                ->action(function (): void {
                    app(DeleteDraftCteEmissionBatch::class)->handle($this->record);
                    $this->redirect(CteEmissionBatchResource::getUrl('index'));
                })
                ->successNotificationTitle('Lote excluído.'),
            Actions\Action::make('approve')
                ->label('Aprovar lote')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status?->value === 'draft')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ApproveCteEmissionBatch::class)->handle($this->record, auth()->user());
                })
                ->successNotificationTitle('Lote aprovado e enviado para o agente.'),
        ];
    }
}
