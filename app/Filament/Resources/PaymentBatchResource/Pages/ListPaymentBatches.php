<?php

namespace App\Filament\Resources\PaymentBatchResource\Pages;

use App\Filament\Resources\PaymentBatchResource;
use App\Services\Payments\GeneratePendingPaymentBatches;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPaymentBatches extends ListRecords
{
    protected static string $resource = PaymentBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generatePending')
                ->label('Gerar lotes pendentes')
                ->action(function (): void {
                    $result = app(GeneratePendingPaymentBatches::class)->handle('manual');
                    Notification::make()->success()->title('Geração concluída')->body("{$result['created']} lote(s) criado(s).")->send();
                }),
        ];
    }
}
