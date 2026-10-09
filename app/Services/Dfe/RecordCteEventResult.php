<?php

namespace App\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEvent;
use Carbon\CarbonImmutable;
use DomainException;
use Throwable;

class RecordCteEventResult
{
    public function __construct(
        private readonly BuildCteEventProc $procBuilder,
        private readonly DfeDocumentStore $store,
    ) {}

    public function handle(
        CteDocument $document,
        CteEvent $event,
        string $signedEventXml,
        SefazResponse $response,
    ): CteEvent {
        $receivedAt = $response->receivedAt ?? $response->cancellationEventReceivedAt;
        $eventFields = [
            'response_xml' => $response->rawXml,
            'protocol' => $response->protocol,
            'event_date' => $this->dateOnly($receivedAt),
            'error_code' => $response->cStat,
            'error_message' => $response->xMotivo,
            'status' => 'rejected',
        ];

        if ($response->cStat === '135') {
            $eventFields['status'] = 'registered';
            $eventFields['error_code'] = null;
            $eventFields['error_message'] = null;
            $event->forceFill($eventFields)->save();
            $this->markCancelled($document, $response->xMotivo);

            try {
                $procEvento = $this->procBuilder->handle($signedEventXml, (string) $response->rawXml);
                $stored = $this->store->storeCteEventProc((string) $document->access_key, $event->event_id, $procEvento);
            } catch (Throwable $exception) {
                $event->forceFill([
                    'status' => 'reconciliation_required',
                    'error_message' => 'Evento registrado, mas falhou a montagem/guarda do procEventoCTe: '.$exception->getMessage(),
                ])->save();

                throw new DomainException('A SEFAZ registrou o cancelamento, mas não foi possível guardar o procEventoCTe; reconciliação necessária.', previous: $exception);
            }

            $event->forceFill([
                'xml_path' => $stored['path'],
                'sha256' => $stored['sha256'],
            ])->save();

            return $event->refresh();
        }

        if ($response->cStat === '218') {
            $eventFields['status'] = 'already_cancelled';
            $eventFields['protocol'] = $response->cancellationEventProtocol ?? $response->protocol;
            $eventFields['event_date'] = $this->dateOnly($response->cancellationEventReceivedAt);
            $event->forceFill($eventFields)->save();
            $this->markCancelled($document, $response->xMotivo);

            return $event->refresh();
        }

        if ($response->cStat === null) {
            $eventFields['status'] = 'reconciliation_required';
        }

        $event->forceFill($eventFields)->save();

        return $event->refresh();
    }

    private function markCancelled(CteDocument $document, ?string $message): void
    {
        $document->forceFill([
            'status' => CteDocumentStatusEnum::CANCELLED,
            'fiscal_status' => FiscalDocumentStatusEnum::CANCELLED,
            'fiscal_status_code' => '101',
            'fiscal_status_message' => $message ?? 'Cancelamento homologado pela SEFAZ.',
        ])->save();
    }

    private function dateOnly(?string $dateTime): ?string
    {
        if (blank($dateTime)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($dateTime)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
