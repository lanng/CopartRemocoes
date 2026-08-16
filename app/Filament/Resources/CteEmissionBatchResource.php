<?php

namespace App\Filament\Resources;

use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource\Pages;
use App\Filament\Resources\CteEmissionBatchResource\RelationManagers;
use App\Models\CteEmissionBatch;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use Filament\Forms\Form;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CteEmissionBatchResource extends Resource
{
    protected static ?string $model = CteEmissionBatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Lotes de CT-e';

    protected static ?string $modelLabel = 'Lote de CT-e';

    protected static ?string $pluralModelLabel = 'Lotes de CT-e';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Section::make('Resumo financeiro')
                ->columns(2)
                ->schema([
                    TextEntry::make('total_cargo_value')
                        ->label('Valor total da carga')
                        ->state(fn (CteEmissionBatch $record): int => $record->totalCargoValueInCents())
                        ->money('BRL', divideBy: 100, locale: 'pt_BR'),
                    TextEntry::make('total_transport_value')
                        ->label('Valor total do transporte')
                        ->state(fn (CteEmissionBatch $record): int => $record->totalTransportValueInCents())
                        ->money('BRL', divideBy: 100, locale: 'pt_BR'),
                ]),
            TextEntry::make('status')
                ->label('Situação')
                ->badge()
                ->color(fn (CteEmissionBatchStatusEnum $state): string => $state->color())
                ->formatStateUsing(fn (CteEmissionBatchStatusEnum $state): string => $state->label()),
            TextEntry::make('execution_mode')
                ->label('Modo')
                ->formatStateUsing(fn (string $state): string => self::formatExecutionMode($state)),
            TextEntry::make('creator.name')->label('Criado por'),
            TextEntry::make('approver.name')->label('Aprovado por'),
            TextEntry::make('approved_at')->label('Aprovado em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo'),
            TextEntry::make('processing_started_at')->label('Processamento iniciado em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo'),
            TextEntry::make('completed_at')->label('Concluído em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->color(fn (CteEmissionBatchStatusEnum $state): string => $state->color())
                    ->formatStateUsing(fn (CteEmissionBatchStatusEnum $state): string => $state->label()),
                Tables\Columns\TextColumn::make('execution_mode')
                    ->label('Modo')
                    ->formatStateUsing(fn (string $state): string => self::formatExecutionMode($state)),
                Tables\Columns\TextColumn::make('creator.name')->label('Criado por')->searchable(),
                Tables\Columns\TextColumn::make('approved_at')
                    ->label('Aprovado em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->sortable(),
                Tables\Columns\TextColumn::make('documents_count')->counts('documents')->label('Itens'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Criado em')
                    ->dateTime('d/m/Y H:i')
                    ->timezone('America/Sao_Paulo')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Situação')
                    ->options(collect(CteEmissionBatchStatusEnum::cases())->mapWithKeys(
                        fn (CteEmissionBatchStatusEnum $status): array => [$status->value => $status->label()]
                    )->all()),
                Tables\Filters\SelectFilter::make('execution_mode')
                    ->label('Modo')
                    ->options([
                        'dry_run' => 'Simulação',
                        'live' => 'Emissão real',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver lote'),
                Tables\Actions\Action::make('delete')
                    ->label('Excluir lote')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (CteEmissionBatch $record): bool => $record->status === CteEmissionBatchStatusEnum::DRAFT)
                    ->requiresConfirmation()
                    ->modalHeading('Excluir lote')
                    ->modalDescription('Os documentos deste lote serão removidos. Os registros originais permanecerão disponíveis.')
                    ->action(function (CteEmissionBatch $record): void {
                        app(DeleteDraftCteEmissionBatch::class)->handle($record);
                    })
                    ->successNotificationTitle('Lote excluído.'),
            ])
            ->bulkActions([]);
    }

    private static function formatExecutionMode(string $state): string
    {
        return match ($state) {
            'dry_run' => 'Simulação',
            'live' => 'Emissão real',
            default => $state,
        };
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\DocumentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCteEmissionBatches::route('/'),
            'view' => Pages\ViewCteEmissionBatch::route('/{record}'),
        ];
    }
}
