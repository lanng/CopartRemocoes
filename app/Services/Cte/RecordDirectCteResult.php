<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Services\Dfe\BuildCteProc;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\GenerateDactePdf;
use App\Services\Dfe\SefazResponse;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resultado da transmissão direta (spec dfe §4.3) — irmão do
 * RecordCteAgentResult, sem as evidências do Lab (placa/payment_code/filename),
 * mas com o cross-check do digVal: a SEFAZ ecoa o DigestValue que assinamos —
 * divergência é gravíssima e marca reconciliation_required (spec §1.3).
 */
class RecordDirectCteResult
{
    public function __construct(
        protected DirectCteDocumentWorkflow $workflow,
        protected BuildCteProc $procBuilder,
        protected DfeDocumentStore $store,
        protected GenerateDactePdf $dacteGenerator,
    ) {}

    public function handle(CteDocument $document, BuiltCte $built, string $signedXml, SefazResponse $response): CteDocument
    {
        return DB::transaction(function () use ($document, $built, $signedXml, $response): CteDocument {
            $document = CteDocument::query()->lockForUpdate()->findOrFail($document->id);

            // Idempotência do job: resultado já registrado, nada a fazer.
            if (in_array($document->status, [
                CteDocumentStatusEnum::AUTHORIZED,
                CteDocumentStatusEnum::REJECTED,
                CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
            ], true)) {
                return $document;
            }

            if ($response->cStat === null) {
                throw new DomainException('Resposta da SEFAZ sem cStat para o documento '.$document->public_id.'.');
            }

            $digest = $this->digestOfSigned($signedXml);

            if ($response->isAuthorized()) {
                if ($response->digestValue !== null && $digest !== null && $response->digestValue !== $digest) {
                    $this->recordReconciliation($document, $signedXml, $response, $digest);
                } else {
                    $this->recordAuthorized($document, $built, $signedXml, $response, $digest);
                }
            } else {
                $this->recordRejected($document, $signedXml, $response);
            }

            $document = $document->refresh();
            app(CteBatchStatusUpdater::class)->handle($document->batch);

            return $document;
        });
    }

    protected function recordAuthorized(CteDocument $document, BuiltCte $built, string $signedXml, SefazResponse $response, ?string $digest): void
    {
        $proc = $this->procBuilder->handle($signedXml, (string) $response->rawXml);
        $stored = $this->store->storeCteProc((string) $built->accessKey, $proc);

        $dactePath = $this->generateDacte((string) $built->accessKey, $proc);

        $this->workflow->transition($document, CteDocumentStatusEnum::AUTHORIZED);

        $document->forceFill([
            'issued_at' => now(),
            'authorized_at' => now(),
            'cte_number' => (string) $built->number,
            'access_key' => $built->accessKey,
            'series' => (string) $built->serie,
            'protocol' => $response->protocol,
            'fiscal_status_code' => $response->cStat,
            'fiscal_status_message' => $response->xMotivo,
            'payload_xml' => $signedXml,
            'response_xml' => $response->rawXml,
            'digest_value' => $digest,
            'xml_path' => $stored['path'],
            'pdf_path' => $dactePath,
            'sha256' => $stored['sha256'],
            'error_stage' => null,
            'error_code' => null,
            'error_message' => null,
        ])->save();

        $this->log('info', 'CT-e autorizado na SEFAZ.', [
            'document' => $document->public_id,
            'access_key' => $built->accessKey,
            'protocol' => $response->protocol,
            'dacte' => $dactePath,
        ]);
    }

    /**
     * DACTE na autorização (spec §5.1, linha tanque crítica) — mas a
     * autorização é sagrada: falha de PDF só loga (pdf_path fica null e a
     * listagem regenera sob demanda a partir do cteProc do S3).
     */
    protected function generateDacte(string $accessKey, string $proc): ?string
    {
        try {
            return $this->dacteGenerator->generateForProc($accessKey, $proc);
        } catch (Throwable $exception) {
            $this->log('warning', 'Falha ao gerar o DACTE (regenerável sob demanda).', [
                'access_key' => $accessKey,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * cStat 100 com digVal divergente do DigestValue assinado: algo gravíssimo
     * (spec §1.3) — o protocolo não pode ser confiado; artifacts ficam
     * guardados para a reconciliação manual e o cteProc NÃO vai para o S3.
     */
    protected function recordReconciliation(CteDocument $document, string $signedXml, SefazResponse $response, ?string $digest): void
    {
        $this->workflow->transition($document, CteDocumentStatusEnum::RECONCILIATION_REQUIRED);

        $document->forceFill([
            'fiscal_status_code' => $response->cStat,
            'fiscal_status_message' => $response->xMotivo,
            'payload_xml' => $signedXml,
            'response_xml' => $response->rawXml,
            'digest_value' => $digest,
            'error_stage' => 'transmission',
            'error_code' => 'digval_mismatch',
            'error_message' => sprintf(
                'digVal da SEFAZ (%s) diverge do DigestValue assinado (%s) — protocolo %s não confiável.',
                (string) $response->digestValue,
                (string) $digest,
                (string) $response->protocol,
            ),
        ])->save();

        $this->log('critical', 'digVal divergente do DigestValue assinado.', [
            'document' => $document->public_id,
            'access_key' => $document->refresh()->access_key,
        ]);
    }

    protected function recordRejected(CteDocument $document, string $signedXml, SefazResponse $response): void
    {
        $this->workflow->transition($document, CteDocumentStatusEnum::REJECTED);

        $document->forceFill([
            'fiscal_status_code' => $response->cStat,
            'fiscal_status_message' => $response->xMotivo,
            'payload_xml' => $signedXml,
            'response_xml' => $response->rawXml,
            'error_stage' => 'transmission',
            'error_code' => $response->cStat,
        ])->save();

        $this->log('warning', 'CT-e rejeitado pela SEFAZ.', [
            'document' => $document->public_id,
            'cStat' => $response->cStat,
            'xMotivo' => $response->xMotivo,
        ]);
    }

    /**
     * DigestValue embutido no SignedInfo do XML que enviamos.
     */
    protected function digestOfSigned(string $signedXml): ?string
    {
        return preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', $signedXml, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel((string) config('dfe.log_channel'))->{$level}($message, $context);
    }
}
