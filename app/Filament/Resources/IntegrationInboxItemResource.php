<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IntegrationInboxItemResource\Pages;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\ResolveIntegrationInboxItem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class IntegrationInboxItemResource extends Resource
{
    protected static ?string $model = IntegrationInboxItem::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationGroup = 'Financeiro';

    protected static ?string $navigationLabel = 'Baixas por e-mail';

    protected static ?string $modelLabel = 'Baixa por e-mail';

    protected static ?string $pluralModelLabel = 'Baixas por e-mail';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            TextEntry::make('status')->label('Situação')->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->statusLabel())->badge(),
            TextEntry::make('source'),
            TextEntry::make('external_id'),
            TextEntry::make('sender'),
            TextEntry::make('subject'),
            TextEntry::make('received_at')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo'),
            TextEntry::make('extracted_vehicle_id')->label('ID veículo'),
            TextEntry::make('extracted_vehicle_plate')->label('Placa'),
            TextEntry::make('register.vehicle_id')->label('Registro associado'),
            TextEntry::make('failure_reason')->label('Motivo')->formatStateUsing(fn (IntegrationInboxItem $record): ?string => $record->failureReasonLabel()),
            TextEntry::make('resolver.name')->label('Conciliado por')->placeholder('Não informado'),
            TextEntry::make('resolved_at')->label('Data da conciliação')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo')->placeholder('Não informado'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')->label('Situação')->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->statusLabel())->badge()->searchable(),
                Tables\Columns\TextColumn::make('sender')->searchable(),
                Tables\Columns\TextColumn::make('subject')->limit(50),
                Tables\Columns\TextColumn::make('received_at')->label('Recebido em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo')->sortable(),
                Tables\Columns\TextColumn::make('extracted_vehicle_id')->label('ID veículo')->searchable(),
                Tables\Columns\TextColumn::make('extracted_vehicle_plate')->label('Placa')->searchable(),
                Tables\Columns\TextColumn::make('failure_reason')->label('Motivo')->formatStateUsing(fn (IntegrationInboxItem $record): ?string => $record->failureReasonLabel())->limit(50),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pendente',
                    'processed' => 'Processado',
                    'duplicate' => 'Duplicado',
                ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('resolve')
                    ->label('Conciliar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->status === 'pending')
                    ->form(fn (IntegrationInboxItem $record): array => [
                        Select::make('register_id')
                            ->label('Registro')
                            ->options(Register::query()
                                ->where('company', 'copart')
                                ->where('vehicle_id', $record->extracted_vehicle_id)
                                ->get()
                                ->filter(fn (Register $register): bool => strtoupper(str_replace('-', '', $register->vehicle_plate)) === strtoupper((string) $record->extracted_vehicle_plate))
                                ->mapWithKeys(fn (Register $register): array => [$register->id => "{$register->vehicle_id} - {$register->vehicle_plate}"])
                                ->all())
                            ->required(),
                        Textarea::make('reason')->label('Justificativa')->required()->maxLength(1000),
                    ])
                    ->action(function (IntegrationInboxItem $record, array $data): void {
                        app(ResolveIntegrationInboxItem::class)->handle(
                            $record,
                            Register::query()->findOrFail($data['register_id']),
                            auth()->user(),
                            $data['reason'],
                        );
                    }),
            ])
            ->bulkActions([]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIntegrationInboxItems::route('/'),
            'view' => Pages\ViewIntegrationInboxItem::route('/{record}'),
        ];
    }
}
