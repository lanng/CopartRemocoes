<?php

namespace App\Filament\Resources\CteEmissionBatchResource\Pages;

use App\Filament\Resources\CteEmissionBatchResource;
use App\Services\Cte\ApproveCteEmissionBatch;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCteEmissionBatch extends ViewRecord
{
    protected static string $resource = CteEmissionBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
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
