<?php

namespace App\Filament\Resources\MdfeDocumentResource\Pages;

use App\Enums\CiotStatusEnum;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\MdfeDocumentResource;
use App\Models\MdfeDocument;
use App\Models\MdfeEvent;
use App\Services\Dfe\CancelMdfeDocument;
use App\Services\Dfe\CloseTransportOperation;
use App\Services\Dfe\CloseTransportOperationResult;
use App\Services\Dfe\DeleteDraftMdfeDocument;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\EditRejectedMdfeDocument;
use App\Services\Dfe\GenerateDamdfePdf;
use DomainException;
use Filament\Actions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewMdfeDocument extends ViewRecord
{
    protected static string $resource = MdfeDocumentResource::class;

    public function refreshDocument(): void
    {
        $this->document()->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()
                ->label('Editar MDF-e')
                ->visible(fn (): bool => MdfeDocumentResource::canEdit($this->document()))
                ->url(fn (): string => MdfeDocumentResource::getUrl('edit', ['record' => $this->document()])),
            Actions\Action::make('retryEmission')
                ->label('Reenviar MDF-e')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => MdfeDocumentResource::canEdit($this->document()))
                ->requiresConfirmation()
                ->modalDescription('O MDF-e será reenviado com os dados salvos, mantendo o mesmo número e série. Nenhum novo CIOT será emitido.')
                ->action(function (): void {
                    abort_unless(MdfeDocumentResource::canEdit($this->document()), 403);
                    try {
                        app(EditRejectedMdfeDocument::class)->enqueue($this->document());
                    } catch (DomainException $exception) {
                        Notification::make()->title('Não foi possível reenviar o MDF-e')->body($exception->getMessage())->danger()->send();

                        return;
                    } catch (ValidationException $exception) {
                        Notification::make()->title('Revise os dados do MDF-e antes de reenviar')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    $this->refreshDocument();
                    Notification::make()->title('MDF-e reenfileirado com o mesmo número')->success()->send();
                }),
            Actions\Action::make('deleteDraft')
                ->label('Excluir rascunho')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => app(DeleteDraftMdfeDocument::class)->canDelete($this->document()))
                ->requiresConfirmation()
                ->modalHeading('Excluir rascunho do MDF-e?')
                ->modalDescription('Esta ação remove o rascunho, seus vínculos com CT-es e as reservas de documentos. O CT-e e o CIOT vinculados serão preservados. Documentos com qualquer evidência de comunicação fiscal não podem ser excluídos.')
                ->action(function (): void {
                    try {
                        app(DeleteDraftMdfeDocument::class)->handle($this->document());
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Não foi possível excluir o rascunho.')
                            ->body($exception->errors()['document'][0] ?? 'O MDF-e deixou de atender aos critérios de exclusão segura.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Rascunho do MDF-e excluído.')
                        ->success()
                        ->send();

                    $this->redirect(MdfeDocumentResource::getUrl('index'));
                }),
            Actions\Action::make('closeTransportOperation')
                ->label(fn (): string => $this->hasClosureToReconcile() ? 'Reconciliar encerramento' : 'Encerrar operação de transporte')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => $this->document()->isClosureAvailable())
                ->requiresConfirmation()
                ->modalHeading('Encerrar operação de transporte')
                ->modalDescription('A cidade de encerramento será o destino final da viagem. A ANTT só será chamada depois que a consulta à SEFAZ confirmar o encerramento do MDF-e.')
                ->action(function (): void {
                    $this->runTransportClosure();
                }),
            Actions\Action::make('resumeCiotClosure')
                ->label('Retomar encerramento do CIOT')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => (string) $this->document()->fiscal_status_code === '132'
                    && $this->document()->ciot?->status === CiotStatusEnum::ISSUED)
                ->requiresConfirmation()
                ->modalHeading('Retomar somente o encerramento do CIOT')
                ->modalDescription('A SEFAZ já confirmou o encerramento do MDF-e. Esta ação tenta somente o encerramento do CIOT na ANTT.')
                ->action(function (): void {
                    $this->runTransportClosure();
                }),
            Actions\Action::make('cancelMdfe')
                ->label(fn (): string => $this->hasCancellationToReconcile() ? 'Reconciliar cancelamento' : 'Cancelar MDF-e')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->document()->isCancellationAvailable())
                ->form([
                    Textarea::make('justification')
                        ->label('Justificativa do cancelamento')
                        ->rows(4)
                        ->minLength(15)
                        ->maxLength(255)
                        ->required()
                        ->helperText('Informe de 15 a 255 caracteres. O prazo usual é de até 24 horas após a autorização e antes do início do transporte; a SEFAZ confirma a possibilidade.'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Cancelar somente este MDF-e')
                ->modalDescription('O cancelamento não altera a situação do CIOT nem dos CT-es vinculados. Consulte os estados dos documentos abaixo após a operação.')
                ->action(function (array $data): void {
                    try {
                        $event = app(CancelMdfeDocument::class)->handle($this->document(), $data['justification']);
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Não foi possível cancelar o MDF-e.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(match ($event->status) {
                            'registered' => 'Cancelamento registrado pela SEFAZ.',
                            'already_cancelled' => 'A SEFAZ confirmou que o MDF-e já estava cancelado.',
                            'already_closed' => 'A SEFAZ informou que o MDF-e já está encerrado.',
                            'rejected' => 'A SEFAZ rejeitou o cancelamento; não houve reenvio automático.',
                            default => 'Resultado inconclusivo; consulte a situação na SEFAZ antes de reenviar.',
                        })
                        ->body($event->error_message)
                        ->color(match ($event->status) {
                            'registered', 'already_cancelled' => 'success',
                            'rejected', 'already_closed' => 'danger',
                            default => 'warning',
                        })
                        ->send();
                }),
            Actions\Action::make('viewBatch')
                ->label('Ver lote')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => CteEmissionBatchResource::getUrl('view', ['record' => $this->document()->cte_emission_batch_id]))
                ->visible(fn (): bool => $this->document()->cte_emission_batch_id !== null),
            Actions\Action::make('downloadXml')
                ->label('Baixar XML do MDF-e')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->hasArchivedFile($this->document()->xml_path))
                ->action(fn (): StreamedResponse => $this->downloadXml()),
            Actions\Action::make('downloadPdf')
                ->label('Baixar DAMDFE')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->hasArchivedFile($this->document()->pdf_path)
                    || $this->hasArchivedFile($this->document()->xml_path))
                ->action(fn (): StreamedResponse => $this->downloadPdf()),
            Actions\Action::make('downloadEventXml')
                ->label('Baixar XML de evento')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->eventXmlOptions() !== [])
                ->form([
                    Select::make('event_id')
                        ->label('Evento fiscal')
                        ->options(fn (): array => $this->eventXmlOptions())
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $event = $this->document()->events()->whereKey($data['event_id'])->firstOrFail();

                    abort_unless($this->hasArchivedFile($event->xml_path), 404);

                    return Storage::disk('dfe')->download(
                        (string) $event->xml_path,
                        basename((string) $event->xml_path),
                    );
                }),
        ];
    }

    /** @return array<int, string> */
    private function eventXmlOptions(): array
    {
        return $this->document()->events
            ->filter(fn (MdfeEvent $event): bool => $this->hasArchivedFile($event->xml_path))
            ->sortByDesc('created_at')
            ->mapWithKeys(fn (MdfeEvent $event): array => [
                $event->id => sprintf(
                    '%s · tentativa %s · %s',
                    $event->event_type,
                    $event->attempt,
                    $event->status,
                ),
            ])
            ->all();
    }

    private function hasCancellationToReconcile(): bool
    {
        return $this->document()->events()
            ->where('event_type', '110111')
            ->whereIn('status', ['pending', 'reconciliation_required'])
            ->exists();
    }

    private function hasClosureToReconcile(): bool
    {
        return $this->document()->events()
            ->where('event_type', '110112')
            ->whereIn('status', ['pending', 'registered', 'reconciliation_required', 'duplicate_reconciliation'])
            ->exists();
    }

    private function runTransportClosure(): void
    {
        try {
            $result = app(CloseTransportOperation::class)->handle($this->document());
        } catch (DomainException $exception) {
            Notification::make()
                ->title('Não foi possível encerrar a operação de transporte.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(match ($result->status) {
                CloseTransportOperationResult::COMPLETED => $result->mdfe->ciot_id === null
                    ? 'MDF-e encerrado pela SEFAZ.'
                    : 'MDF-e e CIOT encerrados.',
                CloseTransportOperationResult::MDFE_PENDING => 'Aguardando confirmação do encerramento pela SEFAZ.',
                default => 'MDF-e encerrado; encerramento do CIOT pendente na ANTT.',
            })
            ->body($result->message)
            ->color($result->status === CloseTransportOperationResult::COMPLETED ? 'success' : 'warning')
            ->send();
    }

    private function downloadXml(): StreamedResponse
    {
        $document = $this->document();

        abort_unless($this->hasArchivedFile($document->xml_path), 404);

        return Storage::disk('dfe')->download(
            (string) $document->xml_path,
            strtolower((string) ($document->access_key ?? $document->id)).'-mdfe.xml',
        );
    }

    private function downloadPdf(): StreamedResponse
    {
        $document = $this->document();
        $store = app(DfeDocumentStore::class);
        $pdfPath = (string) $document->pdf_path;

        if (! filled($pdfPath) || ! $store->exists($pdfPath)) {
            abort_unless($this->hasArchivedFile($document->xml_path), 404);

            $pdfPath = app(GenerateDamdfePdf::class)->generateForProc(
                (string) $document->access_key,
                $store->read((string) $document->xml_path),
            );
            $document->forceFill(['pdf_path' => $pdfPath])->save();
        }

        return Storage::disk('dfe')->download(
            $pdfPath,
            strtolower((string) ($document->access_key ?? $document->id)).'-damdfe.pdf',
        );
    }

    private function hasArchivedFile(?string $path): bool
    {
        return filled($path) && app(DfeDocumentStore::class)->exists($path);
    }

    private function document(): MdfeDocument
    {
        abort_unless(auth()->check(), 403);

        $document = $this->getRecord();

        abort_unless($document instanceof MdfeDocument, 404);

        return $document;
    }
}
