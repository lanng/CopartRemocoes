<?php

namespace App\Filament\Resources;

use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\IntegrationInboxItemResource\Pages;
use App\Filament\Support\ChecklistConciliationAction;
use App\Filament\Support\ReviewRemovalRequestAction;
use App\Models\IntegrationInboxItem;
use App\Services\MicrosoftGraph\RemovalRequests\ResolveRemovalRequestImport;
use App\Services\MicrosoftGraph\RemovalRequests\RetryRemovalRequestImport;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
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

    protected static ?string $navigationLabel = 'Integrações por e-mail';

    protected static ?string $modelLabel = 'Integração por e-mail';

    protected static ?string $pluralModelLabel = 'Integrações por e-mail';

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
            TextEntry::make('acknowledger.name')->label('Alerta reconhecido por')->placeholder('Não reconhecido'),
            TextEntry::make('acknowledged_at')->label('Data do reconhecimento')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo')->placeholder('Não reconhecido'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')->label('Situação')->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->statusLabel())->badge()->searchable(),
                Tables\Columns\TextColumn::make('message_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (IntegrationInboxItem $record): string => $record->messageTypeLabel())
                    ->badge()
                    ->color(fn (IntegrationInboxItem $record): string => $record->isRemovalRequest() ? 'info' : 'gray'),
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
                Tables\Actions\ViewAction::make()
                    ->iconButton()
                    ->tooltip('Visualizar'),
                ChecklistConciliationAction::make()
                    ->iconButton()
                    ->tooltip('Conciliar'),
                Tables\Actions\Action::make('acceptRemovalRequest')
                    ->label('Aceitar importação')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest()
                        && $record->status === 'pending'
                        && $record->register_id !== null
                        && ($record->proposed_changes !== null || $record->candidate_pdf_path !== null))
                    ->requiresConfirmation()
                    ->action(function (IntegrationInboxItem $record): void {
                        app(ResolveRemovalRequestImport::class)->apply(
                            $record,
                            auth()->user(),
                            array_map('strval', array_keys($record->proposed_changes ?? [])),
                            $record->candidate_pdf_path !== null,
                        );
                    })
                    ->iconButton()
                    ->tooltip('Aceitar importação'),
                ReviewRemovalRequestAction::make()
                    ->iconButton()
                    ->tooltip('Revisar importação'),
                Tables\Actions\Action::make('retryRemovalRequest')
                    ->label('Tentar novamente')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest()
                        && (($record->status === 'pending'
                            && in_array($record->failure_reason, [
                                'domain_error',
                                'processing_failed',
                                'graph_connection_missing',
                            ], true))
                            || ($record->status === 'alert'
                                && in_array('consignor_letter_failed', $record->alerts ?? [], true))))
                    ->requiresConfirmation()
                    ->action(function (IntegrationInboxItem $record): void {
                        app(RetryRemovalRequestImport::class)->handle($record);

                        Notification::make()
                            ->title('Importação reenfileirada')
                            ->success()
                            ->send();
                    })
                    ->iconButton()
                    ->tooltip('Tentar novamente'),
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
                    })
                    ->iconButton()
                    ->tooltip('Rejeitar importação'),
                Tables\Actions\Action::make('acknowledgeRemovalAlert')
                    ->label('Reconhecer alerta')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest() && $record->status === 'alert')
                    ->requiresConfirmation()
                    ->action(fn (IntegrationInboxItem $record): IntegrationInboxItem => app(ResolveRemovalRequestImport::class)->acknowledge($record, auth()->user()))
                    ->iconButton()
                    ->tooltip('Reconhecer alerta'),
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
