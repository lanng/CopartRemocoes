<?php

namespace App\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\MdfeDocument;
use App\Models\MdfeEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

class CancelMdfeDocument
{
    private const EVENT_TYPE = '110111';

    public function __construct(
        private readonly BuildMdfeCancellationEvent $builder,
        private readonly MdfeSoapClient $client,
        private readonly RecordMdfeEventResult $recorder,
        private readonly DfeDocumentStore $store,
        private readonly XsdValidator $validator,
        private readonly ReserveMdfeDocuments $reservations,
    ) {}

    public function handle(MdfeDocument $document, string $justification): MdfeEvent
    {
        $this->assertCancellationAllowed($document);

        $previousAttempt = $document->events()
            ->where('event_type', self::EVENT_TYPE)
            ->orderByDesc('attempt')
            ->first();

        if ($previousAttempt !== null) {
            if (! in_array($previousAttempt->status, ['pending', 'reconciliation_required'], true)) {
                throw new DomainException('A tentativa anterior é terminal; o cancelamento não será retransmitido.');
            }

            $reconciled = $this->consultAndReconcile($document, $previousAttempt, cancellationConfirmed: false);

            if ($reconciled === true) {
                return $previousAttempt->refresh();
            }

            if ($reconciled !== false) {
                throw new DomainException('A situação do MDF-e continua inconclusiva; nenhum novo evento foi enviado.');
            }
        }

        $built = $this->builder->handle($document, $justification);
        $signedXml = (new XmlSigner(app(SefazCertificate::class)))->sign(
            $built->xml,
            'infEvento',
            'http://www.portalfiscal.inf.br/mdfe',
            $built->eventId,
        );
        $this->validator->validateMdfeEvent($signedXml);

        $event = $document->events()->create([
            'event_type' => self::EVENT_TYPE,
            'sequence' => 1,
            'attempt' => ($previousAttempt?->attempt ?? 0) + 1,
            'status' => 'pending',
            'event_id' => $built->eventId,
            'event_date' => $built->eventDate->toDateString(),
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

            if ($this->consultAndReconcile($document, $event, cancellationConfirmed: false) === true) {
                return $event->refresh();
            }

            throw new DomainException('Resultado do cancelamento inconclusivo; consulte a SEFAZ antes de tentar novamente.', previous: $exception);
        }

        $event = $this->recorder->handle($document, $event, $signedXml, $response);

        if (in_array($response->cStat, ['135', '218', '609'], true)) {
            $this->consultAndReconcile($document, $event, cancellationConfirmed: true);
        }

        if (in_array($document->refresh()->fiscal_status, [FiscalDocumentStatusEnum::CANCELLED, FiscalDocumentStatusEnum::CLOSED], true)) {
            $this->reservations->releaseIfTerminal($document->refresh());
        }

        return $event->refresh();
    }

    private function assertCancellationAllowed(MdfeDocument $document): void
    {
        if ($document->status !== CteDocumentStatusEnum::AUTHORIZED || $document->emitter !== 'direct') {
            throw new DomainException('Somente MDF-e autorizado pelo emissor direto pode ser cancelado por esta tela.');
        }

        if (in_array($document->fiscal_status, [FiscalDocumentStatusEnum::CANCELLED, FiscalDocumentStatusEnum::CLOSED], true)
            || in_array((string) $document->fiscal_status_code, ['101', '132'], true)) {
            throw new DomainException('O MDF-e já está cancelado ou encerrado na situação fiscal local.');
        }

        if ((string) config('dfe.tp_amb') === '1' && ! config('dfe.mdfe.production_enabled')) {
            throw new DomainException('O cancelamento de MDF-e em produção está bloqueado por DFE_MDFE_PRODUCTION_ENABLED.');
        }
    }

    private function consultAndReconcile(
        MdfeDocument $document,
        MdfeEvent $event,
        bool $cancellationConfirmed,
    ): ?bool {
        try {
            $consultation = $this->client->consult((string) $document->access_key);
        } catch (Throwable $exception) {
            Log::channel((string) config('dfe.log_channel'))->warning('Não foi possível consultar o MDF-e após resposta do evento.', [
                'mdfe_id' => $document->public_id,
                'event_id' => $event->event_id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($consultation->cStat === '101' || $consultation->hasMdfeCancellationEvent === true) {
            $document->forceFill([
                'status' => CteDocumentStatusEnum::CANCELLED,
                'fiscal_status' => FiscalDocumentStatusEnum::CANCELLED,
                'fiscal_status_code' => '101',
                'fiscal_status_message' => $consultation->xMotivo,
            ])->save();

            $this->storeConsultedCancellation($document, $event, $consultation);
            $this->reservations->releaseIfTerminal($document);

            return true;
        }

        if ($consultation->cStat === '132' || $consultation->hasMdfeClosureEvent === true) {
            $document->forceFill([
                'fiscal_status' => FiscalDocumentStatusEnum::CLOSED,
                'fiscal_status_code' => '132',
                'fiscal_status_message' => $consultation->xMotivo,
            ])->save();
            $event->forceFill([
                'status' => 'already_closed',
                'error_code' => '609',
                'error_message' => $consultation->xMotivo ?? 'A consulta à SEFAZ informa que o MDF-e está encerrado.',
                'protocol' => $consultation->closureEventProtocol ?? $event->protocol,
            ])->save();
            $this->reservations->releaseIfTerminal($document);

            return true;
        }

        if ($consultation->cStat === '100' && $consultation->hasMdfeCancellationEvent !== true) {
            if (! $cancellationConfirmed) {
                $document->forceFill([
                    'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
                    'fiscal_status_code' => '100',
                    'fiscal_status_message' => $consultation->xMotivo,
                ])->save();
            }

            return $cancellationConfirmed;
        }

        return null;
    }

    private function storeConsultedCancellation(
        MdfeDocument $document,
        MdfeEvent $event,
        SefazResponse $consultation,
    ): void {
        $procEvento = $consultation->cancellationEventXml;

        if ($procEvento === null
            || ($consultation->cancellationEventId !== null && $consultation->cancellationEventId !== $event->event_id)) {
            return;
        }

        try {
            $this->validator->validateProcMdfeEvent($procEvento);
            $stored = $this->store->storeMdfeEventProc((string) $document->access_key, $event->event_id, $procEvento);
            $event->forceFill([
                'protocol' => $consultation->cancellationEventProtocol ?? $event->protocol,
                'event_date' => $consultation->cancellationEventReceivedAt
                    ? CarbonImmutable::parse($consultation->cancellationEventReceivedAt)->toDateString()
                    : $event->event_date,
                'xml_path' => $stored['path'],
                'sha256' => $stored['sha256'],
                'status' => $event->status === 'already_cancelled' ? 'already_cancelled' : 'registered',
                'error_code' => null,
                'error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $event->forceFill([
                'status' => 'reconciliation_required',
                'error_message' => 'O cancelamento consta na consulta, mas falhou a guarda do procEventoMDFe: '.$exception->getMessage(),
            ])->save();
        }
    }
}
