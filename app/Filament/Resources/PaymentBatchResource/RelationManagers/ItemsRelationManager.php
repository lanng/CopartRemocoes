<?php

namespace App\Filament\Resources\PaymentBatchResource\RelationManagers;

use App\Enums\PaymentBatchStatusEnum;
use App\Filament\Actions\ViewRegisterAction;
use App\Models\PaymentBatchItem;
use App\Services\Payments\RemovePaymentBatchItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Itens do lote';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('register'))
            ->columns([
                Tables\Columns\TextColumn::make('vehicle_plate')->label('Placa'),
                Tables\Columns\TextColumn::make('amount')->label('Valor')->money('BRL'),
                Tables\Columns\TextColumn::make('cte_number')->label('Número do CT-e')->placeholder('CT-e ausente'),
                Tables\Columns\TextColumn::make('delivery_confirmed_at')->label('Data da entrega')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo'),
            ])
            ->defaultPaginationPageOption(25)
            ->actions([
                ViewRegisterAction::make(fn (PaymentBatchItem $record): ?\App\Models\Register => $record->register),
                Tables\Actions\Action::make('remove')
                    ->label('Retirar do lote')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->visible(fn (PaymentBatchItem $record): bool => $record->batch?->status === PaymentBatchStatusEnum::PENDING)
                    ->requiresConfirmation()
                    ->modalHeading('Retirar item do lote')
                    ->modalDescription('O registro continuará entregue e será incluído na próxima geração de pagamentos.')
                    ->action(function (PaymentBatchItem $record): void {
                        app(RemovePaymentBatchItem::class)->handle($record);
                    })
                    ->successNotificationTitle('Item retirado do lote.'),
            ])
            ->bulkActions([]);
    }
}
