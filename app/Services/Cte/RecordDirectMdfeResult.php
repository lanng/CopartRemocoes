<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\MdfeDocument;
use App\Services\Dfe\BuildMdfeProc;
use App\Services\Dfe\BuiltMdfe;
use App\Services\Dfe\DfeDocumentStore;
use App\Services\Dfe\SefazResponse;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

class RecordDirectMdfeResult
{
    public function __construct(
        private readonly DirectMdfeDocumentWorkflow $workflow,
        private readonly BuildMdfeProc $procBuilder,
        private readonly DfeDocumentStore $store,
    ) {}

    public function handle(MdfeDocument $document, BuiltMdfe $built, string $signedXml, SefazResponse $response): MdfeDocument
    {
        $document = DB::transaction(function () use ($document, $built, $signedXml, $response): MdfeDocument {
            $document = MdfeDocument::query()->lockForUpdate()->findOrFail($document->id);

            if (in_array($document->status, [
                CteDocumentStatusEnum::AUTHORIZED,
                CteDocumentStatusEnum::REJECTED,
                CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
            ], true)) {
                return $document;
            }

            if ($response->cStat === null) {
                throw new DomainException('Resposta da SEFAZ sem cStat para o MDF-e '.$document->public_id.'.');
            }

            $digest = $this->digestOf($signedXml);

            if ($response->isAuthorized()) {
                if ($response->digestValue !== null && $digest !== null && $response->digestValue !== $digest) {
                    $this->workflow->transition($document, CteDocumentStatusEnum::RECONCILIATION_REQUIRED);
                    $document->forceFill([
                        'payload_xml' => $signedXml,
                        'response_xml' => $response->rawXml,
                        'digest_value' => $digest,
                        'fiscal_status_code' => $response->cStat,
                        'fiscal_status_message' => $response->xMotivo,
                        'error_stage' => 'transmission',
                        'error_code' => 'digval_mismatch',
                        'error_message' => 'digVal informado pela SEFAZ diverge do XML assinado.',
                    ])->save();

                    return $document->refresh();
                }

                if (blank($response->protocol)) {
                    throw new DomainException('Resposta autorizada do MDF-e sem protocolo.');
                }

                $document->forceFill([
                    'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
                    'issued_at' => $document->issued_at ?? now(),
                    'authorized_at' => $document->authorized_at ?? now(),
                    'mdfe_number' => (string) $built->number,
                    'access_key' => $built->accessKey,
                    'series' => (string) $built->series,
                    'protocol' => $response->protocol,
                    'fiscal_status_code' => $response->cStat,
                    'fiscal_status_message' => $response->xMotivo,
                    'payload_xml' => $signedXml,
                    'response_xml' => $response->rawXml,
                    'digest_value' => $digest,
                    'error_stage' => 'artifact',
                    'error_code' => 'archive_pending',
                    'error_message' => 'Autorização confirmada; mdfeProc aguardando armazenamento.',
                ])->save();

                return $document->refresh();
            }

            $this->workflow->transition($document, CteDocumentStatusEnum::REJECTED);
            $document->forceFill([
                'fiscal_status' => FiscalDocumentStatusEnum::NOT_AUTHORIZED,
                'snapshot' => \Illuminate\Support\Arr::except($document->snapshot, ['last_rejected_attempt']),
                'payload_xml' => $signedXml,
                'response_xml' => $response->rawXml,
                'digest_value' => $digest,
                'fiscal_status_code' => $response->cStat,
                'fiscal_status_message' => $response->xMotivo,
                'error_stage' => 'transmission',
                'error_code' => $response->cStat,
            ])->save();

            return $document->refresh();
        });

        if ($document->status !== CteDocumentStatusEnum::AUTHORIZING
            || $document->fiscal_status !== FiscalDocumentStatusEnum::AUTHORIZED
            || filled($document->xml_path)) {
            app(\App\Services\Dfe\ReserveMdfeDocuments::class)->releaseIfTerminal($document);

            return $document;
        }

        try {
            $proc = $this->procBuilder->handle($signedXml, (string) $response->rawXml);
            $stored = $this->store->storeMdfeProc($built->accessKey, $proc);
        } catch (Throwable $exception) {
            MdfeDocument::query()->whereKey($document->id)->update([
                'error_stage' => 'artifact',
                'error_code' => 'archive_pending',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $document = DB::transaction(function () use ($document, $stored): MdfeDocument {
            $document = MdfeDocument::query()->lockForUpdate()->findOrFail($document->id);

            if ($document->status === CteDocumentStatusEnum::AUTHORIZING
                && $document->fiscal_status === FiscalDocumentStatusEnum::AUTHORIZED) {
                $this->workflow->transition($document, CteDocumentStatusEnum::AUTHORIZED);
                $document->forceFill([
                    'xml_path' => $stored['path'],
                    'sha256' => $stored['sha256'],
                    'error_stage' => null,
                    'error_code' => null,
                    'error_message' => null,
                ])->save();
            }

            return $document->refresh();
        });

        app(\App\Services\Dfe\ReserveMdfeDocuments::class)->releaseIfTerminal($document);

        return $document;
    }

    private function digestOf(string $xml): ?string
    {
        return preg_match('/<DigestValue>([^<]*)<\/DigestValue>/', $xml, $matches) === 1 ? $matches[1] : null;
    }
}
