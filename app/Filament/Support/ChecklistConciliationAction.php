<?php

namespace App\Filament\Support;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\AcknowledgeIntegrationAlert;
use App\Services\MicrosoftGraph\ResolveIntegrationInboxItem;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Tables\Actions\Action;

class ChecklistConciliationAction
{
    public static function make(): Action
    {
        return Action::make('conciliarChecklist')
            ->label('Conciliar')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (IntegrationInboxItem $record): bool => ! $record->isRemovalRequest()
                && $record->acknowledged_at === null
                && ($record->status === 'pending' || $record->delivery_alert !== null))
            ->disabled(fn (IntegrationInboxItem $record): bool => $record->status === 'pending'
                && $record->delivery_alert === null
                && IntegrationInboxItemPresentation::matchingRegisterOptions($record) === [])
            ->requiresConfirmation()
            ->modalHeading('Conciliar baixa de entrega')
            ->modalDescription(fn (IntegrationInboxItem $record): string => match (true) {
                $record->status !== 'pending' => 'Confirme para registrar sua ciência desta baixa já processada.',
                $record->delivery_alert !== null => 'Escolha como deseja concluir esta baixa de entrega.',
                default => 'Confirme a baixa de entrega para o registro compatível.',
            })
            ->form(fn (IntegrationInboxItem $record): array => match (true) {
                $record->status !== 'pending' => [],
                $record->delivery_alert !== null => [
                    Radio::make('decisao')
                        ->label('Decisão')
                        ->options([
                            'deliver' => 'Prosseguir com a baixa (status Entregue)',
                            'keep' => 'Manter status atual (baixa manual pelo escritório)',
                        ])
                        ->default('deliver')
                        ->live()
                        ->required(),
                    Placeholder::make('registro')
                        ->label('Registro')
                        ->content(fn (): string => self::registerLabel($record)),
                ],
                default => [
                    Select::make('register_id')
                        ->label('Registro')
                        ->options(IntegrationInboxItemPresentation::matchingRegisterOptions($record))
                        ->default(fn (IntegrationInboxItem $record): int|string|null => in_array($record->register_id, array_keys(IntegrationInboxItemPresentation::matchingRegisterOptions($record)))
                            ? $record->register_id
                            : null)
                        ->disabled(fn (IntegrationInboxItem $record): bool => IntegrationInboxItemPresentation::matchingRegisterOptions($record) === [])
                        ->helperText(fn (IntegrationInboxItem $record): ?string => IntegrationInboxItemPresentation::matchingRegisterOptions($record) === []
                            ? 'Nenhum registro compatível encontrado para esta baixa.'
                            : null)
                        ->required(),
                ],
            })
            ->action(function (IntegrationInboxItem $record, array $data): void {
                if ($record->status !== 'pending'
                    || ($record->delivery_alert !== null && ($data['decisao'] ?? null) === 'keep')) {
                    app(AcknowledgeIntegrationAlert::class)->handle($record, auth()->user());

                    return;
                }

                app(ResolveIntegrationInboxItem::class)->handle(
                    $record,
                    Register::query()->findOrFail($record->delivery_alert !== null
                        ? $record->register_id
                        : $data['register_id']),
                    auth()->user(),
                    'Baixa conciliada manualmente',
                );
            });
    }

    private static function registerLabel(IntegrationInboxItem $record): string
    {
        $register = $record->register;

        return $register !== null
            ? "{$register->vehicle_id} - {$register->vehicle_plate}"
            : (string) $record->extracted_vehicle_id;
    }
}
