<?php

namespace App\Filament\Resources\CteEmissionBatchResource\Pages;

use App\Filament\Resources\CteEmissionBatchResource;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCteEmissionBatch extends ViewRecord
{
    protected static string $resource = CteEmissionBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
