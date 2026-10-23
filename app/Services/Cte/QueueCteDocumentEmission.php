<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\CteDocument;
use DomainException;
use Illuminate\Support\Facades\DB;

class QueueCteDocumentEmission
{
    public function __construct(
        private AssertCteNfeOriginalsArchived $assertNfeOriginalsArchived,
        private ValidateGeneralCargoCteSnapshot $generalCargoValidator,
    ) {}

    public function handle(CteDocument $document): CteDocument
    {
        if (! $document->exists || $document->getKey() === null) {
            throw new DomainException('Somente um CT-e persistido pode ser enfileirado.');
        }

        return DB::transaction(function () use ($document): CteDocument {
            $lockedDocument = CteDocument::query()
                ->lockForUpdate()
                ->find($document->getKey());

            if (! $lockedDocument instanceof CteDocument) {
                throw new DomainException('O CT-e selecionado não foi encontrado para emissão.');
            }

            if ($lockedDocument->emitter !== 'direct'
                || $lockedDocument->execution_mode !== 'live'
                || $lockedDocument->workflow_origin !== FiscalDocumentWorkflowOriginEnum::ControlledV1) {
                throw new DomainException('Somente um CT-e do emissor direto, fluxo controlado e modo live pode ser enfileirado.');
            }

            $snapshot = (array) $lockedDocument->snapshot;
            $isGeneralCargo = ($snapshot['line'] ?? null) === 'general_cargo';

            if ($isGeneralCargo) {
                if (! in_array((int) ($snapshot['schema_version'] ?? 0), [4, 5], true)) {
                    throw new DomainException('Somente snapshots v4 ou v5 de CT-e avulso podem ser enfileirados.');
                }
            } elseif ((int) ($snapshot['schema_version'] ?? 0) !== 3 || ($snapshot['line'] ?? null) !== 'tank_alcohol') {
                throw new DomainException('Somente snapshots v3 de CT-e de álcool ou v4 de CT-e avulso podem ser enfileirados.');
            }

            if ($lockedDocument->status === CteDocumentStatusEnum::QUEUED) {
                return $lockedDocument->load('nfeDocuments');
            }

            if ($lockedDocument->status !== CteDocumentStatusEnum::DRAFT) {
                throw new DomainException('Somente um CT-e em rascunho pode ser enfileirado.');
            }

            $this->assertNfeOriginalsArchived->handle($lockedDocument);

            if ($isGeneralCargo) {
                $this->generalCargoValidator->handle($snapshot, forEmission: true);
            } else {
                $this->assertSnapshotReady($snapshot);
            }

            app(DirectCteDocumentWorkflow::class)->transition($lockedDocument, CteDocumentStatusEnum::QUEUED);
            EmitCteDirectJob::dispatch($lockedDocument->id)->afterCommit();

            return $lockedDocument->load('nfeDocuments');
        });
    }

    /** @param array<string, mixed> $snapshot */
    private function assertSnapshotReady(array $snapshot): void
    {
        $missingDeliveryFields = array_values(array_filter(array_map(
            'strval',
            (array) ($snapshot['delivery_missing_fields'] ?? []),
        )));

        if ($missingDeliveryFields !== []) {
            throw new DomainException('A entrega física está incompleta; preencha os campos: '.implode(', ', $missingDeliveryFields).'.');
        }

        $delivery = (array) ($snapshot['delivery_party'] ?? []);

        foreach (['xNome', 'xLgr', 'nro', 'xBairro', 'cMun', 'xMun', 'cep', 'uf'] as $field) {
            if (blank($delivery[$field] ?? null)) {
                throw new DomainException("A entrega física está incompleta; preencha o campo {$field}.");
            }
        }

        $cnpj = preg_replace('/\D/', '', (string) ($delivery['cnpj'] ?? '')) ?: '';
        $cpf = preg_replace('/\D/', '', (string) ($delivery['cpf'] ?? '')) ?: '';

        if (($cnpj === '') === ($cpf === '')
            || ($cnpj !== '' && ! preg_match('/^\d{14}$/', $cnpj))
            || ($cpf !== '' && ! preg_match('/^\d{11}$/', $cpf))) {
            throw new DomainException('A entrega física deve conter CNPJ ou CPF válido.');
        }

        if (! preg_match('/^\d{7}$/', (string) $delivery['cMun'])
            || ! preg_match('/^\d{8}$/', (string) $delivery['cep'])
            || ! preg_match('/^[A-Z]{2}$/', (string) $delivery['uf'])) {
            throw new DomainException('A entrega física contém município, CEP ou UF inválidos.');
        }

        $destination = (array) ($snapshot['destination'] ?? []);

        if ((string) ($destination['ibge'] ?? '') !== (string) $delivery['cMun']
            || (string) ($destination['name'] ?? '') !== (string) $delivery['xMun']
            || (string) ($destination['uf'] ?? '') !== (string) $delivery['uf']) {
            throw new DomainException('O destino efetivo do CT-e deve corresponder ao município da entrega física.');
        }

        if ((string) config('dfe.tp_amb') === '1'
            && mb_strtoupper(trim((string) ($snapshot['dest']['ie'] ?? ''))) === 'ISENTO'
            && strtoupper((string) ($snapshot['dest']['uf'] ?? '')) === 'SP') {
            throw new DomainException('O tomador fiscal em SP não pode ser informado como ISENTO na inscrição estadual.');
        }

        try {
            $freightCents = ExactDecimal::parse((string) ($snapshot['value'] ?? ''), 2, 'do CT-e', 'frete');
        } catch (DomainException $exception) {
            throw new DomainException('O valor do frete do CT-e é inválido.', previous: $exception);
        }

        if ($freightCents <= 0) {
            throw new DomainException('O valor do frete do CT-e deve ser maior que zero.');
        }

        $deliveryDate = (string) ($snapshot['dprev'] ?? '');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            throw new DomainException('A previsão de entrega do CT-e não é uma data válida.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $deliveryDate));

        if (! checkdate($month, $day, $year)) {
            throw new DomainException('A previsão de entrega do CT-e não é uma data válida.');
        }

        if ($deliveryDate < now('America/Sao_Paulo')->toDateString()) {
            throw new DomainException('A previsão de entrega está vencida; atualize a data antes de enfileirar.');
        }
    }
}
