<?php

namespace App\Services\Dfe;

use App\Enums\CiotStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\City;
use App\Models\MdfeDocument;
use App\Models\MdfeEvent;
use App\Services\Ciot\AnttCiotException;
use App\Services\Ciot\CloseCiot;
use DomainException;

class CloseTransportOperation
{
    public function __construct(
        private readonly CloseMdfeDocument $closeMdfeDocument,
        private readonly CloseCiot $closeCiot,
        private readonly ReserveMdfeDocuments $reservations,
    ) {}

    public function handle(MdfeDocument $mdfe, ?City $closureCity = null): CloseTransportOperationResult
    {
        $mdfe->refresh()->load('ciot');
        $event = null;

        if (! $this->isSefazClosureConfirmed($mdfe)) {
            if ($closureCity === null) {
                $closureCity = $this->destinationCity($mdfe);
            }

            try {
                $event = $this->closeMdfeDocument->handle($mdfe, $closureCity);
            } catch (DomainException $exception) {
                $mdfe->refresh();

                if (! $this->isSefazClosureConfirmed($mdfe)) {
                    if ($this->hasUnresolvedClosureAttempt($mdfe)) {
                        return new CloseTransportOperationResult(
                            CloseTransportOperationResult::MDFE_PENDING,
                            $mdfe->load('ciot'),
                            $this->latestClosureEvent($mdfe),
                            $exception->getMessage(),
                        );
                    }

                    throw $exception;
                }
            }

            $mdfe->refresh();

            if (! $this->isSefazClosureConfirmed($mdfe)) {
                return new CloseTransportOperationResult(
                    CloseTransportOperationResult::MDFE_PENDING,
                    $mdfe->load('ciot'),
                    $event,
                    $event?->error_message ?? 'A SEFAZ ainda não confirmou o encerramento do MDF-e; o CIOT permanece aberto.',
                );
            }
        }

        if ($mdfe->fiscal_status !== FiscalDocumentStatusEnum::CLOSED) {
            $mdfe->forceFill(['fiscal_status' => FiscalDocumentStatusEnum::CLOSED])->save();
            $mdfe->refresh();
        }

        $this->reservations->releaseIfTerminal($mdfe);

        $ciot = $mdfe->ciot()->first();

        if ($ciot === null) {
            return new CloseTransportOperationResult(CloseTransportOperationResult::COMPLETED, $mdfe, $event);
        }

        if ($ciot->isSyntheticHomologation()) {
            return new CloseTransportOperationResult(
                CloseTransportOperationResult::COMPLETED,
                $mdfe->load('ciot'),
                $event,
                'MDF-e encerrado na SEFAZ; CIOT sintético preservado e nenhuma solicitação enviada à ANTT.',
            );
        }

        if ($ciot->status === CiotStatusEnum::CLOSED) {
            $this->reservations->releaseCiotIfTerminal($ciot);

            return new CloseTransportOperationResult(CloseTransportOperationResult::COMPLETED, $mdfe->load('ciot'), $event);
        }

        if ($ciot->status !== CiotStatusEnum::ISSUED) {
            return new CloseTransportOperationResult(
                CloseTransportOperationResult::CIOT_PENDING,
                $mdfe->load('ciot'),
                $event,
                'O MDF-e está encerrado, mas o CIOT ainda não está emitido e aberto para encerramento na ANTT.',
            );
        }

        try {
            $ciot = $this->closeCiot->handle($ciot);
        } catch (AnttCiotException|DomainException $exception) {
            $ciot->refresh();

            if ($ciot->status === CiotStatusEnum::CLOSED) {
                $this->reservations->releaseCiotIfTerminal($ciot);

                return new CloseTransportOperationResult(CloseTransportOperationResult::COMPLETED, $mdfe->load('ciot'), $event);
            }

            return new CloseTransportOperationResult(
                CloseTransportOperationResult::CIOT_PENDING,
                $mdfe->load('ciot'),
                $event,
                $exception->getMessage(),
            );
        }

        if ($ciot->status === CiotStatusEnum::CLOSED) {
            return new CloseTransportOperationResult(CloseTransportOperationResult::COMPLETED, $mdfe->load('ciot'), $event);
        }

        return new CloseTransportOperationResult(
            CloseTransportOperationResult::CIOT_PENDING,
            $mdfe->load('ciot'),
            $event,
            'O MDF-e foi encerrado, mas a ANTT ainda não confirmou o encerramento do CIOT.',
        );
    }

    private function isSefazClosureConfirmed(MdfeDocument $mdfe): bool
    {
        return (string) $mdfe->fiscal_status_code === '132';
    }

    private function hasUnresolvedClosureAttempt(MdfeDocument $mdfe): bool
    {
        return $mdfe->events()
            ->where('event_type', '110112')
            ->whereIn('status', ['pending', 'registered', 'reconciliation_required', 'duplicate_reconciliation'])
            ->exists();
    }

    private function latestClosureEvent(MdfeDocument $mdfe): ?MdfeEvent
    {
        return $mdfe->events()
            ->where('event_type', '110112')
            ->orderByDesc('attempt')
            ->first();
    }

    private function destinationCity(MdfeDocument $mdfe): City
    {
        $mdfeDestination = (array) data_get($mdfe->snapshot, 'destination', []);
        $ciotDestination = (array) ($mdfe->ciot?->destination ?? []);
        $mdfeIbgeCode = preg_replace('/\D/', '', (string) ($mdfeDestination['ibge'] ?? ''));
        $ciotIbgeCode = preg_replace('/\D/', '', (string) ($ciotDestination['ibge'] ?? ''));
        $ibgeCode = preg_match('/^\d{7}$/', (string) $mdfeIbgeCode) ? $mdfeIbgeCode : $ciotIbgeCode;
        $state = strtoupper(trim((string) ($mdfeDestination['uf'] ?? '')));
        $state = $state !== '' ? $state : strtoupper(trim((string) ($ciotDestination['uf'] ?? '')));

        if (! preg_match('/^\d{7}$/', (string) $ibgeCode) || $state === '') {
            throw new DomainException('O destino final do MDF-e e do CIOT não possui município IBGE e UF válidos para o encerramento.');
        }

        return new City([
            'ibge_code' => $ibgeCode,
            'name' => trim((string) ($mdfeDestination['name'] ?? $ciotDestination['cidade'] ?? '')),
            'state' => $state,
        ]);
    }
}
