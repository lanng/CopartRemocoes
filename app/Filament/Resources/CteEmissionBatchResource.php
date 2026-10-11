<?php

namespace App\Filament\Resources;

use App\Enums\CiotStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource\Pages;
use App\Filament\Resources\CteEmissionBatchResource\RelationManagers;
use App\Models\Ciot;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use App\Services\Cte\CheckCteBatchParties;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
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

    protected static ?string $navigationGroup = 'Emissões';

    protected static ?string $navigationLabel = 'Lotes de CT-e';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Lote de CT-e';

    protected static ?string $pluralModelLabel = 'Lotes de CT-e';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->extraAttributes([
                'wire:poll.5s' => 'refreshBatch',
            ])
            ->schema([
                Section::make('Conferência para emissão')
                    ->visible(fn (CteEmissionBatch $record): bool => $record->status === CteEmissionBatchStatusEnum::DRAFT)
                    ->schema([
                        TextEntry::make('party_review')
                            ->label('Remetentes e destinatários')
                            ->state(fn (CteEmissionBatch $record): string => app(CheckCteBatchParties::class)->summary($record)),
                    ]),
                Section::make('MDF-e da viagem')
                    ->visible(fn (CteEmissionBatch $record): bool => $record->mdfeDocuments()->exists())
                    ->schema([
                        TextEntry::make('mdfe_documents')
                            ->label('MDF-e')
                            ->state(fn (CteEmissionBatch $record): string => $record->mdfeDocuments()
                                ->get()
                                ->map(fn (MdfeDocument $mdfe): string => trim(implode(' | ', array_filter([
                                    $mdfe->mdfe_number ? 'Nº '.$mdfe->mdfe_number : 'sem número',
                                    $mdfe->access_key,
                                    $mdfe->protocol ? 'Protocolo '.$mdfe->protocol : null,
                                    $mdfe->status->label(),
                                ]))))
                                ->implode("\n")),
                    ]),
                Section::make('CIOT da viagem')
                    ->visible(fn (CteEmissionBatch $record): bool => $record->ciots()->exists())
                    ->schema([
                        RepeatableEntry::make('ciots')
                            ->label('CIOTs da viagem')
                            ->columns(3)
                            ->schema([
                                TextEntry::make('ciot_number')
                                    ->label('Número')
                                    ->state(fn (Ciot $record): string => $record->fullNumber() ?? '(sem número)')
                                    ->fontFamily('mono'),
                                TextEntry::make('status')
                                    ->label('Situação')
                                    ->badge()
                                    ->color(fn (CiotStatusEnum $state): string => $state->color())
                                    ->formatStateUsing(fn (CiotStatusEnum $state): string => $state->label()),
                                TextEntry::make('error_code')
                                    ->label('Código ANTT')
                                    ->visible(fn (Ciot $record): bool => $record->status === CiotStatusEnum::FAILED)
                                    ->placeholder('—'),
                                TextEntry::make('error_message')
                                    ->label('Resposta da ANTT')
                                    ->visible(fn (Ciot $record): bool => $record->status === CiotStatusEnum::FAILED
                                        && filled($record->error_message))
                                    ->columnSpanFull(),
                                TextEntry::make('ciot_details')
                                    ->label('Ação')
                                    ->state('Ver detalhes do CIOT')
                                    ->url(fn (Ciot $record): string => CiotResource::getUrl('view', ['record' => $record]))
                                    ->visible(fn (Ciot $record): bool => $record->status === CiotStatusEnum::FAILED),
                            ]),
                    ]),
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
            ->defaultSort('approved_at', 'desc')
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
                    ->visible(fn (CteEmissionBatch $record): bool => app(DeleteDraftCteEmissionBatch::class)->canDelete($record))
                    ->requiresConfirmation()
                    ->modalHeading('Excluir lote')
                    ->modalDescription('CT-es rascunho sem evidência de comunicação serão removidos. Os demais permanecerão como documentos avulsos; os XMLs originais das NF-es serão preservados.')
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
