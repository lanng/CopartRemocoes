<?php

namespace App\Filament\Resources\CteEmissionBatchResource\RelationManagers;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Filament\Actions\ResolveCteFiscalPartyAction;
use App\Filament\Actions\ViewRegisterAction;
use App\Models\CteDocument;
use App\Services\Cte\CheckCteBatchParties;
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

    private ?array $partyChecks = null;

    public function refreshPartyReview(): void
    {
        $this->partyChecks = null;
        $this->resetTable();
        $this->dispatch('fiscal-parties-resolved');
    }

    private function isReview(): bool
    {
        return $this->getOwnerRecord()->status === CteEmissionBatchStatusEnum::DRAFT;
    }

    private function partyCheck(CteDocument $document): array
    {
        $this->partyChecks ??= app(CheckCteBatchParties::class)->report($this->getOwnerRecord());

        return $this->partyChecks[$document->id] ?? [];
    }

    private function partyDescription(CteDocument $document, string $role): ?string
    {
        $party = $this->partyCheck($document)[$role] ?? [];
        $cnpj = $party['party']['cnpj'] ?? null;
        if ($cnpj === null) {
            return null;
        }

        return 'CNPJ '.$cnpj.($role === 'destination' ? ' · '.$party['city']['name'].'/'.$party['city']['uf'] : '');
    }

    public function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->recordTitleAttribute('cte_number')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['register', 'batch']))
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('register.vehicle_plate')->label('Placa'),
                TextColumn::make('party_review_status')
                    ->label('Conferência')
                    ->visible(fn (): bool => $this->isReview())
                    ->state(fn (CteDocument $record): string => ($this->partyCheck($record)['ready'] ?? false) ? 'Pronto' : 'Pendente')
                    ->badge()->color(fn (string $state): string => $state === 'Pronto' ? 'success' : 'warning'),
                TextColumn::make('snapshot.origin_city')->label('Origem')->visible(fn (): bool => $this->isReview()),
                TextColumn::make('snapshot.insurance')->label('Seguradora no registro')->wrap()->visible(fn (): bool => $this->isReview()),
                TextColumn::make('confirmed_sender')->label('Remetente confirmado')
                    ->visible(fn (): bool => $this->isReview())
                    ->state(fn (CteDocument $record): ?string => $this->partyCheck($record)['sender']['party']['xNome'] ?? null)
                    ->description(fn (CteDocument $record): ?string => $this->partyDescription($record, 'sender'))
                    ->placeholder('A conciliar')->wrap(),
                TextColumn::make('snapshot.destination_city')->label('Destino no registro')->visible(fn (): bool => $this->isReview()),
                TextColumn::make('confirmed_destination')->label('Destinatário / tomador confirmado')
                    ->visible(fn (): bool => $this->isReview())
                    ->state(fn (CteDocument $record): ?string => $this->partyCheck($record)['destination']['party']['xNome'] ?? null)
                    ->description(fn (CteDocument $record): ?string => $this->partyDescription($record, 'destination'))
                    ->placeholder('A conciliar')->wrap(),
                TextColumn::make('party_review_errors')->label('Pendências')
                    ->visible(fn (): bool => $this->isReview())
                    ->state(fn (CteDocument $record): ?string => implode(' ', $this->partyCheck($record)['errors'] ?? []) ?: null)
                    ->placeholder('—')->wrap(),
                TextColumn::make('status')
                    ->label('Situação')
                    ->visible(fn (): bool => ! $this->isReview())
                    ->badge()
                    ->color(fn (CteDocumentStatusEnum $state): string => $state->color())
                    ->formatStateUsing(fn (CteDocumentStatusEnum $state): string => $state->label()),
                TextColumn::make('failure_details')
                    ->label('Motivo da falha/rejeição')
                    ->visible(fn (): bool => ! $this->isReview())
                    ->state(fn (CteDocument $record): ?string => $this->failureDetails($record))
                    ->placeholder('—')
                    ->limit(100)
                    ->tooltip(fn (CteDocument $record): ?string => $this->failureDetails($record))
                    ->wrap(),
                TextColumn::make('register.vehicle_id')->label('ID veículo')->visible(fn (): bool => ! $this->isReview()),
                TextColumn::make('cte_number')->label('Número do CT-e')->visible(fn (): bool => ! $this->isReview()),
                TextColumn::make('access_key')->label('Chave de acesso')->limit(18)->visible(fn (): bool => ! $this->isReview()),
                TextColumn::make('protocol')->label('Protocolo')->visible(fn (): bool => ! $this->isReview()),
                TextColumn::make('authorized_at')->label('Autorizado em')->dateTime('d/m/Y H:i')->timezone('America/Sao_Paulo')->visible(fn (): bool => ! $this->isReview()),
            ])
            ->actions([
                ResolveCteFiscalPartyAction::make('sender'),
                ResolveCteFiscalPartyAction::make('destination'),
                ViewRegisterAction::make(fn (CteDocument $record): ?\App\Models\Register => $record->register),
                Tables\Actions\Action::make('retry')
                    ->label('Tentar novamente')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (CteDocument $record): bool => CteDocumentRecoveryService::canBeRequeued($record))
                    ->requiresConfirmation()
                    ->modalDescription('O documento voltará para a fila de emissão e será preenchido e emitido novamente pela máquina host.')
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

    private function failureDetails(CteDocument $document): ?string
    {
        if (filled($document->error_message)) {
            return $document->error_message;
        }

        if (filled($document->fiscal_status_message)) {
            return filled($document->fiscal_status_code)
                ? $document->fiscal_status_code.' · '.$document->fiscal_status_message
                : $document->fiscal_status_message;
        }

        if (in_array($document->status, [
            CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
            CteDocumentStatusEnum::REJECTED,
            CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
        ], true)) {
            return 'O emissor não retornou um motivo detalhado.';
        }

        return null;
    }
}
