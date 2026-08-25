<?php

namespace App\Filament\Resources;

use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\IntegrationInboxItemResource\Pages;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\RemovalRequests\ResolveRemovalRequestImport;
use App\Services\MicrosoftGraph\ResolveIntegrationInboxItem;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

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
            TextEntry::make('message_type')->label('Tipo')->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->messageTypeLabel()),
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
            TextEntry::make('removal_alerts')
                ->label('Alertas da importação')
                ->state(fn (IntegrationInboxItem $record): ?string => $record->hasRemovalAlert() ? implode(', ', $record->removalAlertLabels()) : null)
                ->badge()
                ->color(fn (IntegrationInboxItem $record): string => $record->removalAlertColor())
                ->placeholder('Sem alerta'),
            RepeatableEntry::make('proposed_changes_for_display')
                ->label('Alterações propostas')
                ->state(fn (IntegrationInboxItem $record): array => $record->proposedChangesForDisplay())
                ->schema([
                    TextEntry::make('field')->label('Campo'),
                    TextEntry::make('current')->label('Atual')->placeholder('Não informado'),
                    TextEntry::make('proposed')->label('Proposto')->placeholder('Não informado'),
                ])
                ->columns(3)
                ->visible(fn (IntegrationInboxItem $record): bool => $record->proposed_changes !== null),
            TextEntry::make('candidate_pdf_path')
                ->label('PDF candidato')
                ->url(fn (IntegrationInboxItem $record): ?string => $record->candidate_pdf_path ? Storage::disk('s3')->url($record->candidate_pdf_path) : null)
                ->openUrlInNewTab()
                ->placeholder('Não informado'),
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
                    ->state(fn (IntegrationInboxItem $record): ?string => $record->deliveryAlertLabel()
                        ?? ($record->hasRemovalAlert() ? implode(', ', $record->removalAlertLabels()) : null)
                        ?? $record->failureReasonLabel())
                    ->badge()
                    ->color(fn (IntegrationInboxItem $record): string => $record->hasDeliveryAlert()
                        ? $record->deliveryAlertColor()
                        : $record->removalAlertColor()),
            ])
            ->defaultPaginationPageOption(25)
            ->defaultSort('received_at', 'desc')

            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pendente',
                    'processed' => 'Processado',
                    'alert' => 'Alerta',
                    'no_changes' => 'Sem alterações',
                    'duplicate' => 'Duplicado',
                ]),
                Tables\Filters\SelectFilter::make('message_type')->label('Tipo')->options([
                    'checklist' => 'Checklist digital',
                    'removal_request' => 'Pedido de remoção',
                ]),
                Tables\Filters\TernaryFilter::make('has_delivery_alert')
                    ->label('Alerta')
                    ->placeholder('Todos')
                    ->trueLabel('Com alerta')
                    ->falseLabel('Sem alerta')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                            $query->whereNotNull('delivery_alert')->orWhereJsonLength('alerts', '>', 0);
                        }),
                        false: fn (Builder $query): Builder => $query
                            ->whereNull('delivery_alert')
                            ->where(function (Builder $query): void {
                                $query->whereNull('alerts')->orWhereJsonLength('alerts', 0);
                            }),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('reviewRemovalRequest')
                    ->label('Revisar importação')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest()
                        && $record->status === 'pending'
                        && ($record->proposed_changes !== null || $record->candidate_pdf_path !== null))
                    ->form(fn (IntegrationInboxItem $record): array => [
                        CheckboxList::make('fields')
                            ->label('Campos para aplicar')
                            ->options(collect($record->proposed_changes ?? [])
                                ->reject(fn (array $change, string $field): bool => $field === 'pdf_path')
                                ->mapWithKeys(fn (array $change, string $field): array => [$field => match ($field) {
                                    'vehicle_model' => 'Veículo',
                                    'origin_city' => 'Cidade de origem',
                                    'destination_city' => 'Cidade de destino',
                                    'deadline_withdraw' => 'Data limite de retirada',
                                    'deadline_delivery' => 'Data limite de entrega',
                                    'value' => 'Frete',
                                    'insurance' => 'Seguradora',
                                    'fipe_value' => 'FIPE',
                                    'payment_code' => 'Código de pagamento',
                                    'notes' => 'Observações',
                                    default => $field,
                                }])
                                ->all())
                            ->columns(1),
                        Toggle::make('replace_pdf')->label('Substituir PDF candidato')->default(false),
                    ])
                    ->action(function (IntegrationInboxItem $record, array $data): void {
                        app(ResolveRemovalRequestImport::class)->apply(
                            $record,
                            auth()->user(),
                            array_map('strval', $data['fields'] ?? []),
                            (bool) ($data['replace_pdf'] ?? false),
                        );
                    }),
                Tables\Actions\Action::make('rejectRemovalRequest')
                    ->label('Rejeitar importação')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest() && $record->requiresAttention())
                    ->form([
                        Textarea::make('reason')->label('Justificativa')->required()->maxLength(1000),
                    ])
                    ->action(function (IntegrationInboxItem $record, array $data): void {
                        app(ResolveRemovalRequestImport::class)->reject($record, auth()->user(), $data['reason']);
                    }),
                Tables\Actions\Action::make('acknowledgeRemovalAlert')
                    ->label('Reconhecer alerta')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest() && $record->status === 'alert')
                    ->requiresConfirmation()
                    ->action(fn (IntegrationInboxItem $record): IntegrationInboxItem => app(ResolveRemovalRequestImport::class)->acknowledge($record, auth()->user())),
                Tables\Actions\Action::make('resolve')
                    ->label('Conciliar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => ! $record->isRemovalRequest() && $record->status === 'pending')
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
            ->recordClasses(fn (IntegrationInboxItem $record): ?string => match ($record->hasDeliveryAlert() ? $record->deliveryAlertColor() : $record->removalAlertColor()) {
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
