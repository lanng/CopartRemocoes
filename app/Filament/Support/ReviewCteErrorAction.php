<?php

namespace App\Filament\Support;

use App\Enums\RegisterStatusEnum;
use App\Filament\Resources\IntegrationInboxItemResource;
use App\Models\IntegrationInboxItem;
use App\Services\MicrosoftGraph\ResolveCteErrorAnalysis;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action;

class ReviewCteErrorAction
{
    public static function make(): Action
    {
        return Action::make('reviewCteError')
            ->label('Revisar erro no CT-e')
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->authorize(fn (IntegrationInboxItem $record): bool => auth()->check() && IntegrationInboxItemResource::canView($record))
            ->visible(fn (IntegrationInboxItem $record): bool => $record->isCteError() && $record->requiresAttention())
            ->disabled(fn (IntegrationInboxItem $record): bool => $record->register_id !== null
                && $record->register?->status !== RegisterStatusEnum::ANALYSIS)
            ->modalHeading('Revisar erro no CT-e')
            ->modalDescription(fn (IntegrationInboxItem $record): string => $record->register_id === null
                ? 'Confira o e-mail e os registros manualmente. Encerrar esta pendência registra sua conferência e não altera nenhum registro.'
                : 'Confirmar o erro mantém o registro em Análise até a correção manual ou a autorização de um novo CT-e. Descartar o aviso retoma o fluxo, aproveitando uma entrega já reconhecida.')
            ->form([
                Placeholder::make('cte_number')->label('CT-e')
                    ->content(fn (IntegrationInboxItem $record): string => (string) ($record->extracted_data['cte_number'] ?? 'Não identificado')),
                Placeholder::make('error_message')->label('Mensagem de erro')
                    ->content(fn (IntegrationInboxItem $record): string => (string) ($record->extracted_data['error_message'] ?? 'Não identificada')),
                Radio::make('decision')->label('Decisão')
                    ->options(fn (IntegrationInboxItem $record): array => $record->register_id === null
                        ? ['manual_review' => 'Conferido manualmente — encerrar pendência']
                        : [
                            'acknowledge' => 'Confirmar erro e manter em Análise',
                            'false_positive' => 'Descartar aviso como falso positivo',
                        ])
                    ->required(),
            ])
            ->action(function (IntegrationInboxItem $record, array $data): void {
                app(ResolveCteErrorAnalysis::class)->handle($record, auth()->user(), $data['decision']);

                Notification::make()->title('Revisão registrada')->success()->send();
            });
    }
}
