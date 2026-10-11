<?php

namespace App\Filament\Resources\MdfeDocumentResource\Pages;

use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Support\CiotEmissionNotice;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Services\Dfe\EditRejectedMdfeDocument;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Illuminate\Validation\ValidationException;

class EditMdfeDocument extends CreateMdfeDocument
{
    use InteractsWithRecord;

    protected static ?string $title = 'Editar MDF-e';

    public function mount(int|string|null $record = null): void
    {
        abort_if($record === null, 404);
        $this->record = $this->resolveRecord($record);
        abort_unless(auth()->check() && MdfeDocumentResource::canEdit($this->getRecord()), 403);
        $this->batchId = $this->getRecord()->cte_emission_batch_id;
        $this->form->fill(app(EditRejectedMdfeDocument::class)->formData($this->getRecord()));
    }

    public function isEditing(): bool
    {
        return true;
    }

    public function getBackUrl(): string
    {
        return MdfeDocumentResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    public function prepare(): void
    {
        $this->save();
    }

    public function save(): void
    {
        abort_unless(auth()->check() && MdfeDocumentResource::canEdit($this->getRecord()), 403);
        try {
            $data = $this->form->getState();
            $service = app(EditRejectedMdfeDocument::class);

            if (($data['ciot_mode'] ?? null) === 'new') {
                $ciotEmission = $service->createAndEmitReplacementCiot($this->getRecord(), $data);
                (new CiotEmissionNotice)->send($ciotEmission);

                if (! $ciotEmission->isIssued()) {
                    return;
                }

                $data['ciot_mode'] = 'existing';
                $data['ciot_id'] = $ciotEmission->ciot->id;
                $this->form->fill($data);
            }

            $this->record = $service->save($this->getRecord(), $data);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages(['data.cte_document_ids' => $exception->getMessage()]);
        } catch (ValidationException $exception) {
            if (collect(array_keys($exception->errors()))->contains(fn (string $key): bool => str_starts_with($key, 'data.'))) {
                throw $exception;
            }
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['data.'.$key => $messages])->all());
        }
        Notification::make()->title('Alterações do MDF-e salvas')->body('Use Reenviar MDF-e para transmitir com o mesmo número e série.')->success()->send();
        $this->redirect($this->getBackUrl());
    }

    protected function ciotModeOptions(): array
    {
        return [
            'existing' => 'Usar um CIOT emitido e aberto',
            'new' => 'Criar e emitir um novo CIOT',
        ];
    }

    protected function cteSearchResults(string $search, mixed $lineValue): array
    {
        $linked = $this->getRecord()->cteDocuments()
            ->when($search !== '', fn ($query) => $query->whereLike('cte_number', '%'.$search.'%'))
            ->get()->mapWithKeys(fn (CteDocument $document): array => [$document->id => 'CT-e '.$document->cte_number.'/'.$document->series])->all();

        return parent::cteSearchResults($search, $lineValue) + $linked;
    }

    protected function cteOptionLabels(array $values): array
    {
        return CteDocument::query()->whereIn('id', $values)->get()->mapWithKeys(
            fn (CteDocument $document): array => [$document->id => 'CT-e '.$document->cte_number.'/'.$document->series],
        )->all();
    }

    protected function ciotOptions(mixed $lineValue): array
    {
        $ciot = $this->getRecord()->ciot;
        $linked = $ciot instanceof Ciot ? [$ciot->id => $ciot->ciot_number.' · '.$ciot->payer_name] : [];

        return parent::ciotOptions($lineValue) + $linked;
    }
}
