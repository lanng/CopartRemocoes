<?php

namespace App\Filament\Widgets;

use App\Filament\Support\IntegrationInboxItemPresentation;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\AcknowledgeIntegrationAlert;
use App\Services\MicrosoftGraph\RemovalRequests\ResolveRemovalRequestImport;
use App\Services\MicrosoftGraph\RemovalRequests\RetryRemovalRequestImport;
use App\Services\MicrosoftGraph\ResolveIntegrationInboxItem;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class ActionableIntegrations extends BaseWidget
{
    protected static string $view = 'filament.widgets.actionable-integrations';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Ações necessárias')
            ->query(fn (): Builder => IntegrationInboxItem::query()
                ->with(['register', 'resolver', 'acknowledger'])
                ->requiringUserAction()
                ->byActionPriority()
                ->limit(10))
            ->columns([
                Tables\Columns\TextColumn::make('action')
                    ->label('Ação')
                    ->state(fn (IntegrationInboxItem $record): string => $record->actionLabel())
                    ->badge()
                    ->color(fn (IntegrationInboxItem $record): string => match ($record->actionPriority()) {
                        1, 2 => 'danger',
                        3, 4, 5 => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('message_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'removal_request' => 'Inclusão de registro',
                        'checklist' => 'Baixa de entrega',
                        default => $state,
                    }),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Assunto')
                    ->limit(55)
                    ->tooltip(fn (IntegrationInboxItem $record): ?string => $record->subject),
                Tables\Columns\TextColumn::make('received_at')
                    ->label('Recebido em')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nenhuma ação pendente')
            ->emptyStateDescription('As integrações que exigirem intervenção aparecerão aqui.')
            ->actions([
                Tables\Actions\Action::make('view')
                    ->label('Visualizar')
                    ->icon('heroicon-o-eye')
                    ->url(fn (IntegrationInboxItem $record): string => route('filament.admin.resources.integration-inbox-items.view', ['record' => $record])),
                Tables\Actions\Action::make('acknowledgeDeliveryAlert')
                    ->label('Reconhecer')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => ! $record->isRemovalRequest()
                        && $record->delivery_alert !== null
                        && $record->acknowledged_at === null)
                    ->requiresConfirmation()
                    ->action(fn (IntegrationInboxItem $record): IntegrationInboxItem => app(AcknowledgeIntegrationAlert::class)->handle($record, auth()->user())),
                Tables\Actions\Action::make('resolve')
                    ->label('Conciliar')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => ! $record->isRemovalRequest() && $record->status === 'pending')
                    ->disabled(fn (IntegrationInboxItem $record): bool => IntegrationInboxItemPresentation::matchingRegisterOptions($record) === [])
                    ->form(fn (IntegrationInboxItem $record): array => [
                        Select::make('register_id')
                            ->label('Registro')
                            ->options(IntegrationInboxItemPresentation::matchingRegisterOptions($record))
                            ->required(),
                        Textarea::make('reason')
                            ->label('Justificativa')
                            ->required()
                            ->maxLength(1000),
                    ])
                    ->action(function (IntegrationInboxItem $record, array $data): void {
                        app(ResolveIntegrationInboxItem::class)->handle(
                            $record,
                            Register::query()->findOrFail($data['register_id']),
                            auth()->user(),
                            $data['reason'],
                        );
                    }),
                Tables\Actions\Action::make('reviewRemovalRequest')
                    ->label('Revisar')
                    ->icon('heroicon-o-pencil-square')
                    ->color('warning')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest()
                        && $record->status === 'pending'
                        && ($record->proposed_changes !== null || $record->candidate_pdf_path !== null))
                    ->url(fn (IntegrationInboxItem $record): string => route('filament.admin.resources.integration-inbox-items.view', ['record' => $record])),
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
                    ->action(fn (IntegrationInboxItem $record): IntegrationInboxItem => app(RetryRemovalRequestImport::class)->handle($record)),
                Tables\Actions\Action::make('acknowledgeRemovalAlert')
                    ->label('Reconhecer')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (IntegrationInboxItem $record): bool => $record->isRemovalRequest() && $record->status === 'alert')
                    ->requiresConfirmation()
                    ->action(fn (IntegrationInboxItem $record): IntegrationInboxItem => app(ResolveRemovalRequestImport::class)->acknowledge($record, auth()->user())),
            ]);
    }
}
