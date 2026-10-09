<?php

namespace App\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Log;
use Throwable;

class CancelCteDocument
{
    private const EVENT_TYPE = '110111';

    public function __construct(
        private readonly BuildCteCancellationEvent $builder,
        private readonly SefazSoapClient $client,
        private readonly RecordCteEventResult $recorder,
        private readonly DfeDocumentStore $store,
        private readonly XsdValidator $validator,
    ) {}

    public function handle(CteDocument $document, string $justification): CteEvent
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
                throw new DomainException('A situação do CT-e continua inconclusiva; nenhum novo evento foi enviado.');
            }
        }

        $this->assertNoActiveMdfeReference($document);

        $built = $this->builder->handle($document, $justification);
        $signedXml = (new XmlSigner(app(SefazCertificate::class)))->sign(
            $built->xml,
            'infEvento',
            'http://www.portalfiscal.inf.br/cte',
            $built->eventId,
        );
        $this->validator->validateCteEvent($signedXml);

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

        if (in_array($response->cStat, ['135', '218'], true)) {
            $this->consultAndReconcile($document, $event, cancellationConfirmed: true);
        }

        return $event->refresh();
    }

    private function assertCancellationAllowed(CteDocument $document): void
    {
        if ($document->status !== CteDocumentStatusEnum::AUTHORIZED || $document->emitter !== 'direct') {
            throw new DomainException('Somente CT-e autorizado pelo emissor direto pode ser cancelado por esta tela.');
        }

        if ($document->fiscal_status === FiscalDocumentStatusEnum::CANCELLED || $document->fiscal_status_code === '101') {
            throw new DomainException('A situação fiscal do CT-e já indica cancelamento.');
        }

        if ((string) config('dfe.tp_amb') === '1' && ! config('dfe.cte.production_enabled')) {
            throw new DomainException('O cancelamento de CT-e em produção está bloqueado por DFE_CTE_PRODUCTION_ENABLED.');
        }

    }

    private function assertNoActiveMdfeReference(CteDocument $document): void
    {
        $activeMdfeExists = $document->mdfeDocuments()
            ->where('mdfe_documents.status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->where(function ($query): void {
                $query->whereNull('mdfe_documents.fiscal_status')
                    ->orWhere('mdfe_documents.fiscal_status', '!=', FiscalDocumentStatusEnum::CANCELLED->value);
            })
            ->where(function ($query): void {
                $query->whereNull('mdfe_documents.fiscal_status_code')
                    ->orWhere('mdfe_documents.fiscal_status_code', '!=', '101');
            })
            ->exists();

        if ($activeMdfeExists) {
            throw new DomainException('O CT-e não pode ser cancelado enquanto houver MDF-e autorizado vinculado, aberto ou encerrado.');
        }
    }

    private function consultAndReconcile(
        CteDocument $document,
        CteEvent $event,
        bool $cancellationConfirmed,
    ): ?bool {
        try {
            $consultation = $this->client->consult((string) $document->access_key);
        } catch (Throwable $exception) {
            Log::channel((string) config('dfe.log_channel'))->warning('Não foi possível consultar o CT-e após resposta do evento.', [
                'cte_id' => $document->public_id,
                'event_id' => $event->event_id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($consultation->cStat === '101' || $consultation->hasCteCancellationEvent === true) {
            $document->forceFill([
                'status' => CteDocumentStatusEnum::CANCELLED,
                'fiscal_status' => FiscalDocumentStatusEnum::CANCELLED,
                'fiscal_status_code' => '101',
                'fiscal_status_message' => $consultation->xMotivo,
            ])->save();

            $this->storeConsultedCancellation($document, $event, $consultation);

            return true;
        }

        if ($consultation->cStat === '100' && $consultation->hasCteCancellationEvent !== true) {
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
        CteDocument $document,
        CteEvent $event,
        SefazResponse $consultation,
    ): void {
        $procEvento = $consultation->cancellationEventXml;

        if ($procEvento === null
            || ($consultation->cancellationEventId !== null && $consultation->cancellationEventId !== $event->event_id)) {
            return;
        }

        try {
            $this->validator->validateProcCteEvent($procEvento);
            $stored = $this->store->storeCteEventProc((string) $document->access_key, $event->event_id, $procEvento);
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
                'error_message' => 'O cancelamento consta na consulta, mas falhou a guarda do procEventoCTe: '.$exception->getMessage(),
            ])->save();
        }
    }
}
