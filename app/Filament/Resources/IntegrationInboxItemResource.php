<?php

namespace App\Filament\Resources;

use App\Enums\RegisterStatusEnum;
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
use Illuminate\Database\Eloquent\Builder;

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
            TextEntry::make('previous_register_status')
                ->label('Status anterior')
                ->placeholder('Não informado')
                ->formatStateUsing(fn (mixed $state): mixed => RegisterStatusEnum::tryFrom((string) $state)?->localizedLabel() ?? $state),
            TextEntry::make('delivery_alert')
                ->label('Nível do alerta')
                ->formatStateUsing(fn (IntegrationInboxItem $record): ?string => $record->deliveryAlertLabel())
                ->badge()
                ->color(fn (IntegrationInboxItem $record): string => $record->deliveryAlertColor())
                ->placeholder('Sem alerta'),
            TextEntry::make('authorized_cte_number_at_delivery')
                ->label('CT-e autorizado na baixa')
                ->placeholder('Não encontrado'),
            TextEntry::make('resolver.name')->label('Conciliado por')->placeholder('Não informado'),
            TextEntry::make('resolved_at')->label('Data da conciliação')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo')->placeholder('Não informado'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')->label('Situação')->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->statusLabel())->badge()->searchable(),
                Tables\Columns\TextColumn::make('extracted_vehicle_id')
                    ->label('Veículo')
                    ->description(fn (IntegrationInboxItem $record): ?string => $record->extracted_vehicle_plate)
                    ->searchable(['extracted_vehicle_id', 'extracted_vehicle_plate', 'sender']),
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Recebido')
                    ->date('d/m/Y')
                    ->timezone('America/Sao_Paulo')
                    ->description(fn (IntegrationInboxItem $record): ?string => $record->received_at?->copy()->setTimezone('America/Sao_Paulo')->format('H:i'))
                    ->sortable()
                    ->visibleFrom('md'),
                Tables\Columns\TextColumn::make('occurrence')
                    ->label('Ocorrência')
                    ->state(fn (IntegrationInboxItem $record): ?string => $record->deliveryAlertLabel() ?? $record->failureReasonLabel())
                    ->badge()
                    ->color(fn (IntegrationInboxItem $record): string => $record->hasDeliveryAlert() ? $record->deliveryAlertColor() : 'gray'),
            ])
            ->defaultPaginationPageOption(25)
            ->defaultSort('received_at', 'desc')

            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pendente',
                    'processed' => 'Processado',
                    'duplicate' => 'Duplicado',
                ]),
                Tables\Filters\TernaryFilter::make('has_delivery_alert')
                    ->label('Alerta')
                    ->placeholder('Todos')
                    ->trueLabel('Com alerta')
                    ->falseLabel('Sem alerta')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('delivery_alert'),
                        false: fn (Builder $query): Builder => $query->whereNull('delivery_alert'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
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
            ->recordClasses(fn (IntegrationInboxItem $record): ?string => match ($record->deliveryAlertColor()) {
                'warning' => 'integration-inbox-alert-warning',
                'danger' => 'integration-inbox-alert-danger',
                default => null,
            })
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
