<?php

namespace App\Services\Ciot;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use DomainException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Despacha o claim de MDF-e para uma viagem (lote) quando ela está completa:
 * todos os CT-es autorizados + CIOT emitido (spec mdfe-agent-payload §orquestração).
 * Idempotente: um lote tem no máximo um MDF-e ativo.
 */
class DispatchMdfeForBatch
{
    public function __construct(private readonly ValidateMdfeDocumentSelection $selectionValidator) {}

    public function handle(CteEmissionBatch $batch): ?MdfeDocument
    {
        try {
            return $this->dispatch($batch);
        } catch (DomainException $exception) {
            $this->log("MDF-e do lote #{$batch->id} não despachado: ".$exception->getMessage());

            return null;
        } catch (Throwable $exception) {
            $this->log("Erro ao despachar MDF-e do lote #{$batch->id}: ".$exception->getMessage());

            return null;
        }
    }

    protected function dispatch(CteEmissionBatch $batch): ?MdfeDocument
    {
        $documents = $batch->documents;

        if ($documents->isEmpty() || $documents->contains(
            fn ($document): bool => $document->status !== CteDocumentStatusEnum::AUTHORIZED,
        )) {
            $this->log("MDF-e do lote #{$batch->id} aguardando: ainda há CT-es sem autorização.");

            return null;
        }

        $ciot = $batch->ciots()
            ->where('status', CiotStatusEnum::ISSUED->value)
            ->latest('id')
            ->get()
            ->first(fn (Ciot $candidate): bool => ! $candidate->isSyntheticHomologation());

        if ($ciot === null) {
            $this->log("MDF-e do lote #{$batch->id} aguardando: sem CIOT emitido.");

            return null;
        }

        $this->selectionValidator->handle($documents, $ciot);

        $ciotHasActiveMdfe = $ciot->mdfeDocuments()
            ->whereNotIn('status', [
                CteDocumentStatusEnum::REJECTED->value,
                CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                CteDocumentStatusEnum::CANCELLED->value,
            ])
            ->exists();

        if ($ciotHasActiveMdfe) {
            $this->log("MDF-e do lote #{$batch->id} aguardando: CIOT já vinculado a um MDF-e ativo.");

            return null;
        }

        $ativo = $batch->mdfeDocuments()
            ->whereNotIn('status', [
                CteDocumentStatusEnum::REJECTED->value,
                CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION->value,
                CteDocumentStatusEnum::CANCELLED->value,
            ])
            ->exists();

        if ($ativo) {
            $this->log("MDF-e do lote #{$batch->id} já possui documento ativo.");

            return null;
        }

        $snapshot = app(BuildMdfeSnapshot::class)->handle($batch);

        $mdfe = MdfeDocument::query()->create([
            'public_id' => (string) Str::uuid(),
            'cte_emission_batch_id' => $batch->id,
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'snapshot' => $snapshot,
            'idempotency_key' => (string) Str::uuid(),
            'execution_mode' => $batch->execution_mode,
            'emitter' => 'agent',
        ]);

        $mdfe->cteDocuments()->attach($documents
            ->values()
            ->mapWithKeys(fn (CteDocument $document, int $position): array => [
                $document->id => ['position' => $position + 1],
            ])
            ->all());

        activity()
            ->performedOn($ciot)
            ->log("MDF-e da viagem enfileirado (lote #{$batch->id}).");

        return $mdfe;
    }

    protected function log(string $message): void
    {
        Log::channel(config('ciot.log_channel'))->info($message);
    }
}
