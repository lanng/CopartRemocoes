<?php

namespace App\Filament\Resources\CteEmissionBatchResource\Pages;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Filament\Actions\GenerateCiotForBatchAction;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Services\Ciot\DispatchMdfeForBatch;
use App\Services\Ciot\EmitCiotDeclaration;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\DeleteDraftCteEmissionBatch;
use App\Services\Cte\ReconcileMdfeDocument;
use DomainException;
use Filament\Actions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ViewCteEmissionBatch extends ViewRecord
{
    protected static string $resource = CteEmissionBatchResource::class;

    public function refreshBatch(): void
    {
        $this->authorizeAccess();
        $this->getRecord()->refresh();
    }

    /**
     * MDF-es reiniciáveis: falhos/rejeitados OU presos em estado intermediário
     * com lease expirado (agent morreu/pausou no meio do fluxo sem reportar
     * falha terminal — o documento nunca sairia daí pelos próprios meios).
     */
    protected function restartableMdfeDocuments(): HasMany
    {
        return $this->record->mdfeDocuments()
            ->where(function ($query): void {
                $query->whereIn('status', [
                    CteDocumentStatusEnum::REJECTED->value,
                    CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                    CteDocumentStatusEnum::DRY_RUN_COMPLETED->value,
                ])
                    ->orWhere(fn ($query) => $query
                        ->whereIn('status', [
                            CteDocumentStatusEnum::CLAIMED->value,
                            CteDocumentStatusEnum::FILLING->value,
                            CteDocumentStatusEnum::VALIDATING->value,
                            CteDocumentStatusEnum::READY_TO_AUTHORIZE->value,
                            CteDocumentStatusEnum::AUTHORIZING->value,
                            CteDocumentStatusEnum::WAITING_FOR_XML->value,
                        ])
                        ->where('claim_expires_at', '<', now()));
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            GenerateCiotForBatchAction::make($this->getRecord()),
            Actions\Action::make('reemitCiot')
                ->label('Reemitir CIOT')
                ->icon('heroicon-m-arrow-path')
                ->color('primary')
                ->visible(fn (): bool => $this->record->ciots()->where('status', CiotStatusEnum::FAILED->value)->exists())
                ->requiresConfirmation()
                ->modalHeading('Reemitir CIOT da viagem')
                ->modalDescription('Gera um novo IdOperacaoTransporte e reenvia a declaração à ANTT com os dados atuais do CIOT (edite-os pelo botão Editar do CIOT, se necessário).')
                ->action(function (): void {
                    /** @var Ciot|null $ciot */
                    $ciot = $this->record->ciots()
                        ->where('status', CiotStatusEnum::FAILED->value)
                        ->latest('id')
                        ->first();

                    if ($ciot === null) {
                        Notification::make()
                            ->title('Nenhum CIOT com falha neste lote.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        $ciot = app(EmitCiotDeclaration::class)->handle($ciot, sync: true);
                    } catch (Throwable $exception) {
                        report($exception);

                        EmitCiotJob::dispatch($ciot->id);

                        Notification::make()
                            ->title('Reemissão em processamento')
                            ->body('A ANTT não respondeu agora; a reemissão ficou na fila com retry automático.')
                            ->warning()
                            ->send();

                        $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));

                        return;
                    }

                    $ciot->refresh();

                    if ($ciot->status === CiotStatusEnum::ISSUED) {
                        Notification::make()
                            ->title('CIOT reemitido: '.$ciot->fullNumber())
                            ->success()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Reemissão rejeitada pela ANTT')
                            ->body((string) $ciot->error_message)
                            ->danger()
                            ->send();
                    }

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('emitMdfe')
                ->label('Emitir MDF-e')
                ->icon('heroicon-m-document-check')
                ->color('warning')
                ->visible(fn (): bool => in_array($this->record->refresh()->status?->value, ['completed', 'completed_with_errors'], true)
                    && $this->record->ciots()->where('status', CiotStatusEnum::ISSUED->value)->exists()
                    && ! $this->record->mdfeDocuments()
                        ->whereNotIn('status', ['rejected', 'failed_before_authorization', 'cancelled'])
                        ->exists())
                ->requiresConfirmation()
                ->modalHeading('Emitir MDF-e da viagem')
                ->modalDescription('Despacha o claim de MDF-e para o agente com o snapshot da viagem.')
                ->action(function (): void {
                    $mdfe = app(DispatchMdfeForBatch::class)->handle($this->record);

                    if ($mdfe === null) {
                        $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));

                        return;
                    }

                    Notification::make()
                        ->title('MDF-e enfileirado para o agente.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('requeueMdfe')
                ->label('Reenviar MDF-e')
                ->icon('heroicon-m-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => self::restartableMdfeDocuments()->exists())
                ->requiresConfirmation()
                ->modalHeading('Reenviar MDF-e')
                ->modalDescription('O documento volta para a fila do agente para nova tentativa.')
                ->action(function (): void {
                    self::restartableMdfeDocuments()
                        ->each(fn ($mdfe) => $mdfe->forceFill([
                            'status' => 'queued',
                            'claimed_by' => null,
                            'claim_token_hash' => null,
                            'claimed_at' => null,
                            'claim_expires_at' => null,
                            'error_stage' => null,
                            'error_code' => null,
                            'error_message' => null,
                            // Resultado da tentativa anterior: sem limpar o hash,
                            // toda nova tentativa esbarrava no 409 "a different
                            // result was already recorded".
                            'result_payload_hash' => null,
                            'issued_at' => null,
                            'authorized_at' => null,
                            'mdfe_number' => null,
                            'access_key' => null,
                            'series' => null,
                            'protocol' => null,
                            'fiscal_status_code' => null,
                            'fiscal_status_message' => null,
                        ])->save());

                    Notification::make()
                        ->title('MDF-e reenfileirado.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('reconcileMdfe')
                ->label('Conciliar MDF-e')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('success')
                ->visible(fn (): bool => $this->record->mdfeDocuments()
                    ->where('status', CteDocumentStatusEnum::RECONCILIATION_REQUIRED->value)
                    ->exists())
                ->form([
                    TextInput::make('access_key')
                        ->label('Chave de acesso do MDF-e (44 dígitos)')
                        ->mask(str_repeat('9', 44))
                        ->maxLength(44)
                        ->required(),
                    TextInput::make('protocol')
                        ->label('Protocolo de autorização (opcional)')
                        ->maxLength(60),
                ])
                ->modalHeading('Conciliar MDF-e da viagem')
                ->modalDescription('Use quando o agente autorizou o MDF-e no Lab mas não conseguiu reportar o resultado. O número e a série são derivados da chave de acesso.')
                ->action(function (array $data): void {
                    $mdfe = $this->record->mdfeDocuments()
                        ->where('status', CteDocumentStatusEnum::RECONCILIATION_REQUIRED->value)
                        ->latest('id')
                        ->first();

                    if ($mdfe === null) {
                        Notification::make()
                            ->title('Nenhum MDF-e aguardando conciliação neste lote.')
                            ->warning()
                            ->send();

                        return;
                    }

                    try {
                        app(ReconcileMdfeDocument::class)->handle(
                            $mdfe,
                            (string) $data['access_key'],
                            filled($data['protocol'] ?? null) ? (string) $data['protocol'] : null,
                        );
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Falha ao conciliar o MDF-e')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('MDF-e conciliado e marcado como autorizado.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionBatchResource::getUrl('view', ['record' => $this->record]));
                }),
            Actions\Action::make('delete')
                ->label('Excluir lote')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => $this->record->status?->value === 'draft')
                ->requiresConfirmation()
                ->modalHeading('Excluir lote')
                ->modalDescription('Os documentos deste lote serão removidos. Os registros originais permanecerão disponíveis.')
                ->action(function (): void {
                    app(DeleteDraftCteEmissionBatch::class)->handle($this->record);
                    $this->redirect(CteEmissionBatchResource::getUrl('index'));
                })
                ->successNotificationTitle('Lote excluído.'),
            Actions\Action::make('approve')
                ->label('Aprovar lote')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->record->status?->value === 'draft')
                ->requiresConfirmation()
                ->action(function (): void {
                    app(ApproveCteEmissionBatch::class)->handle($this->record, auth()->user());
                })
                ->successNotificationTitle('Lote aprovado e enviado para o agente.'),
        ];
    }
}
