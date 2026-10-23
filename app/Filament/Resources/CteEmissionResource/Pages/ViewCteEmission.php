<?php

namespace App\Filament\Resources\CteEmissionResource\Pages;

use App\Filament\Resources\CteEmissionResource;
use App\Models\CteDocument;
use App\Models\CteDocumentNfe;
use App\Services\Cte\QueueCteDocumentEmission;
use App\Services\Cte\RemoveDraftCteDocument;
use App\Services\Cte\UpdateGeneralCargoCteDraft;
use App\Services\Dfe\CancelCteDocument;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\GenerateDactePdf;
use DomainException;
use Filament\Actions;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Leandrocfe\FilamentPtbrFormFields\Money;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ViewCteEmission extends ViewRecord
{
    protected static string $resource = CteEmissionResource::class;

    public function refreshDocument(): void
    {
        $this->emission()->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('editGeneralCargoDraft')
                ->label('Editar carga e CFOP')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $this->canEditGeneralCargoDraft())
                ->fillForm(fn (): array => $this->generalCargoEditForm())
                ->form([
                    TextInput::make('cfop')
                        ->label('CFOP')
                        ->maxLength(4)
                        ->helperText('Em rota SP para outra UF, escolha o CFOP de transporte conforme a operação.'),
                    Money::make('cargo.value')->label('Valor total da carga')->required(),
                    TextInput::make('cargo.predominant_product')->label('Produto predominante')->required()->maxLength(60),
                    TextInput::make('cargo.other_category')->label('Outras características')->maxLength(30),
                    Repeater::make('cargo.measurements')
                        ->label('Medidas da carga')
                        ->helperText('Informe PESO ESTIMADO em kg (unidade 01) ou VEICULOS/UNIDADES transportados (unidade 03). Peso estimado não é peso aferido.')
                        ->schema([
                            Select::make('unit')
                                ->label('Unidade')
                                ->options([
                                    '00' => 'm³',
                                    '01' => 'kg',
                                    '02' => 'toneladas',
                                    '03' => 'unidades',
                                    '04' => 'litros',
                                    '05' => 'MMBTU',
                                ])
                                ->required(),
                            TextInput::make('measure')
                                ->label('Descrição da medida')
                                ->placeholder('PESO ESTIMADO, VEICULOS ou UNIDADES')
                                ->required()
                                ->maxLength(20),
                            TextInput::make('quantity')->label('Quantidade')->numeric()->required(),
                        ])
                        ->minItems(1)
                        ->columns(3),
                ])
                ->modalHeading('Editar rascunho de CT-e avulso')
                ->modalDescription('Atualiza o CFOP e os dados da carga. As referências e os XMLs originais das NF-es serão preservados; nenhuma emissão é iniciada.')
                ->action(function (array $data): void {
                    try {
                        app(UpdateGeneralCargoCteDraft::class)->handle($this->emission(), $data);
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Não foi possível editar o rascunho.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->refreshDocument();

                    Notification::make()
                        ->title('Rascunho atualizado.')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('deleteDraft')
                ->label('Excluir rascunho')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => app(RemoveDraftCteDocument::class)->canRemove($this->emission()))
                ->requiresConfirmation()
                ->modalHeading('Excluir rascunho do CT-e?')
                ->modalDescription('Esta ação remove o rascunho e seus vínculos no sistema. Os XMLs originais das NF-es arquivados serão preservados. Documentos com qualquer evidência de comunicação fiscal não podem ser excluídos.')
                ->action(function (): void {
                    try {
                        app(RemoveDraftCteDocument::class)->handle($this->emission());
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->title('Não foi possível excluir o rascunho.')
                            ->body($exception->errors()['document'][0] ?? 'O CT-e deixou de atender aos critérios de exclusão segura.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Rascunho do CT-e excluído.')
                        ->success()
                        ->send();

                    $this->redirect(CteEmissionResource::getUrl('index'));
                }),
            Actions\Action::make('queueEmission')
                ->label('Enfileirar emissão')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Enfileirar emissão do CT-e?')
                ->modalDescription('O CT-e será validado e enviado para processamento. A autorização fiscal será acompanhada nesta página.')
                ->visible(fn (): bool => $this->emission()->status?->value === 'draft')
                ->action(function (): void {
                    $document = $this->emission();

                    try {
                        app(QueueCteDocumentEmission::class)->handle($document);
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Não foi possível enfileirar o CT-e.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $document->refresh();

                    Notification::make()
                        ->title('CT-e enfileirado para emissão.')
                        ->body('O processamento será atualizado nesta página.')
                        ->success()
                        ->send();
                }),
            Actions\Action::make('cancelCte')
                ->label(fn (): string => $this->hasCancellationToReconcile() ? 'Reconciliar cancelamento' : 'Cancelar CT-e')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->emission()->isCancellationAvailable())
                ->form([
                    Textarea::make('justification')
                        ->label('Justificativa do cancelamento')
                        ->rows(4)
                        ->minLength(15)
                        ->maxLength(255)
                        ->required()
                        ->helperText('Informe de 15 a 255 caracteres. A janela usual é de até 168 horas após a autorização; a SEFAZ confirma a possibilidade.'),
                ])
                ->requiresConfirmation()
                ->modalHeading('Cancelar somente este CT-e')
                ->modalDescription('O sistema consulta a situação antes de um eventual reenvio. O cancelamento do CT-e não altera o CIOT nem outros documentos fiscais.')
                ->action(function (array $data): void {
                    try {
                        $event = app(CancelCteDocument::class)->handle($this->emission(), $data['justification']);
                    } catch (DomainException $exception) {
                        Notification::make()
                            ->title('Não foi possível cancelar o CT-e.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(match ($event->status) {
                            'registered' => 'Cancelamento registrado pela SEFAZ.',
                            'already_cancelled' => 'A SEFAZ confirmou que o CT-e já estava cancelado.',
                            'rejected' => 'A SEFAZ rejeitou o cancelamento; não houve reenvio automático.',
                            default => 'Resultado inconclusivo; consulte a situação na SEFAZ antes de reenviar.',
                        })
                        ->body($event->error_message)
                        ->color(match ($event->status) {
                            'registered', 'already_cancelled' => 'success',
                            'rejected' => 'danger',
                            default => 'warning',
                        })
                        ->send();
                }),
            Actions\Action::make('downloadXml')
                ->label('Baixar XML do CT-e')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->hasArchivedFile($this->emission()->xml_path))
                ->action(fn (): StreamedResponse => $this->downloadXml()),
            Actions\Action::make('downloadPdf')
                ->label('Baixar DACTE')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->hasArchivedFile($this->emission()->pdf_path)
                    || $this->hasArchivedFile($this->emission()->xml_path))
                ->action(fn (): StreamedResponse => $this->downloadPdf()),
            Actions\Action::make('downloadNfeOriginal')
                ->label('Baixar NF-e original')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->nfeOriginalOptions() !== [])
                ->form([
                    Select::make('nfe_document_id')
                        ->label('NF-e arquivada')
                        ->options(fn (): array => $this->nfeOriginalOptions())
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $document = $this->emission();
                    $nfeDocument = $document->nfeDocuments()->whereKey($data['nfe_document_id'])->firstOrFail();

                    abort_unless($this->hasArchivedFile($nfeDocument->xml_path), 404);

                    $filename = basename(str_replace('\\', '/', (string) $nfeDocument->source_filename));

                    return Storage::disk('dfe')->download((string) $nfeDocument->xml_path, $filename);
                }),
            Actions\Action::make('downloadCartaAttachment')
                ->label('Baixar carta de remoção anexada')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn (): bool => $this->hasCartaAttachment())
                ->action(fn (): StreamedResponse => $this->downloadCartaAttachment()),
        ];
    }

    /** @return array<int, string> */
    private function nfeOriginalOptions(): array
    {
        return $this->emission()->nfeDocuments
            ->filter(fn (CteDocumentNfe $nfeDocument): bool => $this->hasArchivedFile($nfeDocument->xml_path))
            ->mapWithKeys(fn (CteDocumentNfe $nfeDocument): array => [
                $nfeDocument->id => sprintf(
                    'NF-e %s · série %s · %s',
                    $nfeDocument->number,
                    $nfeDocument->series,
                    $nfeDocument->source_filename,
                ),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function generalCargoEditForm(): array
    {
        $snapshot = (array) $this->emission()->snapshot;

        return [
            'cfop' => data_get($snapshot, 'cfop', ''),
            'cargo' => [
                'value' => data_get($snapshot, 'cargo.value', ''),
                'predominant_product' => data_get($snapshot, 'cargo.predominant_product', ''),
                'other_category' => data_get($snapshot, 'cargo.other_category', ''),
                'measurements' => data_get($snapshot, 'cargo.measurements', []),
            ],
        ];
    }

    private function canEditGeneralCargoDraft(): bool
    {
        return data_get($this->emission()->snapshot, 'line') === 'general_cargo'
            && app(RemoveDraftCteDocument::class)->canRemove($this->emission());
    }

    private function downloadXml(): StreamedResponse
    {
        $document = $this->emission();

        abort_unless($this->hasArchivedFile($document->xml_path), 404);

        return Storage::disk('dfe')->download(
            (string) $document->xml_path,
            strtolower((string) ($document->access_key ?? $document->id)).'-cte.xml',
        );
    }

    private function downloadPdf(): StreamedResponse
    {
        $document = $this->emission();
        $store = app(DfeDocumentStore::class);
        $pdfPath = (string) $document->pdf_path;

        if (! filled($pdfPath) || ! $store->exists($pdfPath)) {
            abort_unless($this->hasArchivedFile($document->xml_path), 404);

            $pdfPath = app(GenerateDactePdf::class)->generateForProc(
                (string) $document->access_key,
                $store->read((string) $document->xml_path),
            );
            $document->forceFill(['pdf_path' => $pdfPath])->save();
        }

        return Storage::disk('dfe')->download(
            $pdfPath,
            strtolower((string) ($document->access_key ?? $document->id)).'-dacte.pdf',
        );
    }

    private function hasCartaAttachment(): bool
    {
        $attachment = data_get($this->emission()->snapshot, 'other_document.attachment');
        $path = (string) data_get($attachment, 'path', '');

        return data_get($this->emission()->snapshot, 'origin_document_mode') === 'other'
            && str_starts_with($path, 'cte-drafts/'.$this->emission()->public_id.'/carta-remocao/')
            && $this->hasArchivedFile($path);
    }

    private function downloadCartaAttachment(): StreamedResponse
    {
        $document = $this->emission();
        $attachment = data_get($document->snapshot, 'other_document.attachment');
        $path = (string) data_get($attachment, 'path', '');
        $expectedPrefix = 'cte-drafts/'.$document->public_id.'/carta-remocao/';
        $sha256 = (string) data_get($attachment, 'sha256', '');
        $store = app(DfeDocumentStore::class);

        abort_unless(str_starts_with($path, $expectedPrefix)
            && preg_match('/^[a-f0-9]{64}$/', $sha256)
            && $store->exists($path)
            && hash_equals($sha256, hash('sha256', $store->read($path))), 404);

        $filename = basename(str_replace('\\', '/', (string) data_get($attachment, 'filename', 'carta-remocao')));

        return Storage::disk('dfe')->download($path, $filename);
    }

    private function hasArchivedFile(?string $path): bool
    {
        return filled($path) && app(DfeDocumentStore::class)->exists($path);
    }

    private function hasCancellationToReconcile(): bool
    {
        return $this->emission()->events()
            ->where('event_type', '110111')
            ->whereIn('status', ['pending', 'reconciliation_required'])
            ->exists();
    }

    private function emission(): CteDocument
    {
        abort_unless(auth()->check(), 403);

        $document = $this->getRecord();

        abort_unless($document instanceof CteDocument, 404);

        return $document;
    }
}
