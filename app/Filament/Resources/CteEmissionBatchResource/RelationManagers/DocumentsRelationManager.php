<?php

namespace App\Filament\Resources\CteEmissionBatchResource\RelationManagers;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Actions\ViewRegisterAction;
use App\Models\CteDocument;
use App\Services\Cte\CteDocumentRecoveryService;
use App\Services\Cte\RemoveDraftCteDocument;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('cte_number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('register'))
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->color(fn (CteDocumentStatusEnum $state): string => $state->color())
                    ->formatStateUsing(fn (CteDocumentStatusEnum $state): string => $state->label()),
                TextColumn::make('register.vehicle_id')->label('ID veículo'),
                TextColumn::make('register.vehicle_plate')->label('Placa'),
                TextColumn::make('cte_number')->label('Número do CT-e'),
                TextColumn::make('access_key')->label('Chave de acesso')->limit(18),
                TextColumn::make('protocol')->label('Protocolo'),
                TextColumn::make('authorized_at')->label('Autorizado em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo'),
            ])
            ->actions([
                ViewRegisterAction::make(fn (CteDocument $record): ?\App\Models\Register => $record->register),
                Tables\Actions\Action::make('retry')
                    ->label('Tentar novamente')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (CteDocument $record): bool => $record->status === CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION)
                    ->requiresConfirmation()
                    ->action(function (CteDocument $record): void {
                        app(CteDocumentRecoveryService::class)->retry($record);
                    }),
                Tables\Actions\Action::make('reconcile')
                    ->label('Conciliar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (CteDocument $record): bool => $record->status === CteDocumentStatusEnum::RECONCILIATION_REQUIRED)
                    ->form([
                        TextInput::make('number')->label('Número do CT-e')->required()->maxLength(20),
                        TextInput::make('access_key')->label('Chave de acesso')->required()->length(44),
                        TextInput::make('protocol')->label('Protocolo')->required()->maxLength(30),
                        Textarea::make('reason')->label('Justificativa')->required()->maxLength(1000),
                    ])
                    ->action(function (CteDocument $record, array $data): void {
                        app(CteDocumentRecoveryService::class)->reconcile($record, $data);
                    }),
                Tables\Actions\Action::make('remove')
                    ->label('Retirar do lote')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->visible(fn (CteDocument $record): bool => $record->status === CteDocumentStatusEnum::DRAFT
                        && $record->batch?->status === CteEmissionBatchStatusEnum::DRAFT)
                    ->requiresConfirmation()
                    ->modalHeading('Retirar documento do lote')
                    ->modalDescription('O registro original permanecerá disponível para nova emissão.')
                    ->action(function (CteDocument $record): void {
                        app(RemoveDraftCteDocument::class)->handle($record);
                    })
                    ->successNotificationTitle('Documento retirado do lote.'),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('retrySelected')
                    ->label('Tentar novamente selecionados')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $retried = app(CteDocumentRecoveryService::class)->retryMany($records);

                        Notification::make()
                            ->success()
                            ->title('Documentos reenfileirados')
                            ->body("{$retried} documento(s) pronto(s) para nova tentativa.")
                            ->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
