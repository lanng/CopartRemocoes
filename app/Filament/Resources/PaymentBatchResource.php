<?php

namespace App\Filament\Resources;

use App\Enums\PaymentBatchStatusEnum;
use App\Filament\Resources\PaymentBatchResource\Pages;
use App\Filament\Resources\PaymentBatchResource\RelationManagers\ItemsRelationManager;
use App\Models\PaymentBatch;
use App\Services\Payments\ConfirmPaymentBatch;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentBatchResource extends Resource
{
    protected static ?string $model = PaymentBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Lotes de pagamento';

    protected static ?string $modelLabel = 'Lote de pagamento';

    protected static ?string $pluralModelLabel = 'Lotes de pagamento';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('status')->label('Situação')->formatStateUsing(fn (PaymentBatch $record): string => $record->status->label())->badge(),
            TextEntry::make('window_start')->label('Início')->date('d/m/Y'),
            TextEntry::make('window_end')->label('Fim')->date('d/m/Y'),
            TextEntry::make('items_count')->label('Itens')->state(fn (PaymentBatch $record): int => $record->items()->count()),
            TextEntry::make('total_amount')->label('Total')->money('BRL'),
            TextEntry::make('outlook_sync_error')->label('Aviso do Outlook')->placeholder('Nenhum'),
            TextEntry::make('items_snapshot')
                ->label('Itens do lote')
                ->state(fn (PaymentBatch $record): string => $record->items
                    ->map(fn ($item): string => "{$item->vehicle_plate} | {$item->amount} | ".($item->cte_number ?: 'CT-e ausente')." | {$item->delivery_confirmed_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i')}")
                    ->implode('; ')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('window_start')->label('Início')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('window_end')->label('Fim')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Situação')->formatStateUsing(fn (PaymentBatch $record): string => $record->status->label())->badge(),
                Tables\Columns\TextColumn::make('total_amount')->label('Total')->money('BRL'),
                Tables\Columns\TextColumn::make('items_count')->label('Itens')->counts('items'),
                Tables\Columns\TextColumn::make('generated_at')->label('Gerado em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('confirm')
                    ->label('Confirmar pagamento')
                    ->requiresConfirmation()
                    ->visible(fn (PaymentBatch $record): bool => $record->status === PaymentBatchStatusEnum::PENDING)
                    ->action(function (PaymentBatch $record): void {
                        app(ConfirmPaymentBatch::class)->handle($record, auth()->user());
                        Notification::make()->success()->title('Lote confirmado')->send();
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentBatches::route('/'),
            'view' => Pages\ViewPaymentBatch::route('/{record}'),
        ];
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
        ];
    }
}
