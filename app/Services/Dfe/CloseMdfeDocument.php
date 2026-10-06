<?php

namespace App\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Models\City;
use App\Models\MdfeDocument;
use App\Models\MdfeEvent;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

class CloseMdfeDocument
{
    private const EVENT_TYPE = '110112';

    /** @var list<string> */
    private const REGISTERED_CODES = ['134', '135', '136'];

    /** @var list<string> */
    private const RECONCILIATION_CODES = ['631', '609'];

    public function __construct(
        private readonly BuildMdfeClosureEvent $builder,
        private readonly MdfeSoapClient $client,
        private readonly DfeDocumentStore $store,
        private readonly BuildMdfeEventProc $procBuilder,
    ) {}

    public function handle(MdfeDocument $document, City $closureCity): MdfeEvent
    {
        if ($document->status !== CteDocumentStatusEnum::AUTHORIZED || $document->emitter !== 'direct') {
            throw new DomainException('Somente MDF-e autorizado pelo emissor direto pode ser encerrado por esta tela.');
        }

        if ((string) $document->fiscal_status_code === '132') {
            throw new DomainException('A consulta à SEFAZ já informa este MDF-e como encerrado.');
        }

        if ((string) config('dfe.tp_amb') === '1' && ! config('dfe.mdfe.production_enabled')) {
            throw new DomainException('A transmissão de encerramento em produção está bloqueada por DFE_MDFE_PRODUCTION_ENABLED.');
        }

        $previousAttempt = $document->events()
            ->where('event_type', self::EVENT_TYPE)
            ->orderByDesc('attempt')
            ->first();

        if ($previousAttempt?->status === 'reconciliation_required') {
            $consultation = $this->consultAndRecordStatus($document, (string) $previousAttempt->event_id);

            if ($consultation?->cStat !== '100' || $consultation->hasMdfeClosureEvent !== false) {
                throw new DomainException('A consulta não confirmou MDF-e autorizado sem encerramento; o evento não será reenviado.');
            }
        } elseif ($previousAttempt !== null && ! $this->canRetryAfter($previousAttempt)) {
            throw new DomainException('A tentativa anterior não permite reenvio; consulte/reconcilie a situação na SEFAZ antes de prosseguir.');
        }

        $built = $this->builder->handle($document, $closureCity);
        $signer = new XmlSigner(app(SefazCertificate::class));
        $signedXml = $signer->sign(
            $built->xml,
            'infEvento',
            'http://www.portalfiscal.inf.br/mdfe',
            $built->eventId,
        );
        app(XsdValidator::class)->validateMdfeEvent($signedXml);

        $event = $document->events()->create([
            'event_type' => self::EVENT_TYPE,
            'sequence' => 1,
            'attempt' => ($previousAttempt?->attempt ?? 0) + 1,
            'status' => 'pending',
            'event_id' => $built->eventId,
            'event_date' => $built->eventDate->toDateString(),
            'c_uf' => $built->cUf,
            'c_mun' => $built->cMun,
            'payload_xml' => $signedXml,
        ]);

        try {
            $response = $this->client->transmitEvent(
                $signedXml,
                function (int $httpStatus, ?string $contentType, string $body) use ($event): void {
                    $event->forceFill([
                        'transport_http_status' => $httpStatus,
                        'response_content_type' => $contentType,
                        'response_body' => $body,
                    ])->save();
                },
            );
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'reconciliation_required',
                'error_message' => $exception->getMessage(),
            ])->save();

            $this->consultAndRecordStatus($document, $built->eventId);

            throw new DomainException('Resultado do encerramento inconclusivo; consulte a SEFAZ antes de tentar novamente.', previous: $exception);
        }

        $status = $this->statusForResponse($response->cStat);
        $event->forceFill([
            'response_xml' => $response->rawXml,
            'protocol' => $response->protocol,
            'error_code' => in_array($response->cStat, self::REGISTERED_CODES, true) ? null : $response->cStat,
            'error_message' => in_array($response->cStat, self::REGISTERED_CODES, true) ? null : $response->xMotivo,
            'status' => $status,
        ])->save();

        if (! in_array($response->cStat, self::REGISTERED_CODES, true)) {
            if (in_array($response->cStat, self::RECONCILIATION_CODES, true) || $status === 'reconciliation_required') {
                $this->consultAndRecordStatus($document, $built->eventId);
            }

            return $event;
        }

        try {
            $procEvento = $this->procBuilder->handle($signedXml, (string) $response->rawXml);
            $stored = $this->store->storeMdfeEventProc((string) $document->access_key, $built->eventId, $procEvento);
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'reconciliation_required',
                'error_message' => 'Evento registrado, mas falhou a montagem/guarda do procEventoMDFe: '.$exception->getMessage(),
            ])->save();

            throw new DomainException('A SEFAZ registrou o encerramento, mas não foi possível guardar o procEventoMDFe; reconciliação necessária.', previous: $exception);
        }

        $event->forceFill([
            'xml_path' => $stored['path'],
            'sha256' => $stored['sha256'],
        ])->save();

        $this->consultAndRecordStatus($document, $built->eventId);

        return $event;
    }

    private function canRetryAfter(MdfeEvent $event): bool
    {
        return $event->isRetryableRejection();
    }

    private function statusForResponse(?string $cStat): string
    {
        if (in_array($cStat, self::REGISTERED_CODES, true)) {
            return 'registered';
        }

        if (in_array($cStat, MdfeEvent::CORRECTABLE_REJECTION_CODES, true)) {
            return 'rejected_retryable';
        }

        if ($cStat === '631') {
            return 'duplicate_reconciliation';
        }

        if ($cStat === '609') {
            return 'already_closed';
        }

        return 'reconciliation_required';
    }

    private function consultAndRecordStatus(MdfeDocument $document, string $eventId): ?SefazResponse
    {
        try {
            $consultation = $this->client->consult((string) $document->access_key);
            $document->forceFill([
                'fiscal_status_code' => $consultation->cStat,
                'fiscal_status_message' => $consultation->xMotivo,
            ])->save();

            if ($consultation->cStat !== '132') {
                Log::channel((string) config('dfe.log_channel'))->warning('A consulta do MDF-e após resposta de evento não confirmou o encerramento.', [
                    'mdfe_id' => $document->public_id,
                    'event_id' => $eventId,
                    'cStat' => $consultation->cStat,
                    'xMotivo' => $consultation->xMotivo,
                ]);
            }

            return $consultation;
        } catch (Throwable $exception) {
            Log::channel((string) config('dfe.log_channel'))->warning('Não foi possível consultar o MDF-e após a resposta do evento.', [
                'mdfe_id' => $document->public_id,
                'event_id' => $eventId,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
