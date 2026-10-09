<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use App\Models\CteDocument;
use App\Models\CteDocumentNfe;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateAlcoholCteDocument
{
    private const DELIVERY_FIELDS = [
        'cnpj', 'cpf', 'name', 'ie', 'street', 'number', 'complement',
        'district', 'ibge', 'city', 'state', 'zipcode',
    ];

    private const REQUIRED_DELIVERY_FIELDS = [
        'name', 'street', 'number', 'district', 'ibge', 'city', 'state', 'zipcode',
    ];

    private const DELIVERY_FIELD_MAX_LENGTHS = [
        'name' => 60,
        'street' => 255,
        'number' => 60,
        'complement' => 60,
        'district' => 60,
        'ibge' => 7,
        'city' => 60,
        'state' => 2,
        'zipcode' => 8,
    ];

    public function __construct(
        private PersistCteDocumentNfes $persistNfes,
        private ConsolidateAlcoholNfes $consolidator,
    ) {}

    /**
     * @param  list<array{filename: string, xml: string, operator_complements?: array<string, mixed>|null}>  $files
     */
    public function handle(
        array $files,
        string $freightValue,
        ?User $operator = null,
        ?string $deliveryDate = null,
    ): CteDocument {
        $freightCents = $this->freightCents($freightValue);

        if ($freightCents <= 0) {
            throw new DomainException('O valor do frete do CT-e deve ser maior que zero.');
        }

        $deliveryDate = $this->deliveryDate($deliveryDate);

        return DB::transaction(function () use ($files, $freightCents, $operator, $deliveryDate): CteDocument {
            $document = CteDocument::query()->create([
                'public_id' => (string) Str::uuid(),
                'cte_emission_batch_id' => null,
                'register_id' => null,
                'status' => CteDocumentStatusEnum::DRAFT,
                'fiscal_status' => FiscalDocumentStatusEnum::NOT_AUTHORIZED,
                'workflow_origin' => FiscalDocumentWorkflowOriginEnum::ControlledV1,
                'snapshot' => [],
                'idempotency_key' => (string) Str::uuid(),
                'execution_mode' => 'live',
                'emitter' => 'direct',
            ]);

            $persisted = $this->persistNfes->handle($document, $files, $operator);
            $documents = $this->applyOperatorComplements($persisted['documents'], $persisted['nfe_documents']);
            $consolidated = $this->consolidator->handle($documents);
            $delivery = $this->mergeDelivery($consolidated['delivery_candidates']);
            $snapshot = $this->snapshot(
                $consolidated,
                $delivery,
                $freightCents,
                $deliveryDate,
                $persisted['nfe_documents'],
                $document,
            );

            $document->forceFill(['snapshot' => $snapshot])->save();

            return $document->fresh(['nfeDocuments']);
        });
    }

    private function freightCents(string $freightValue): int
    {
        try {
            return ExactDecimal::parse($freightValue, 2, 'do CT-e', 'frete');
        } catch (DomainException $exception) {
            throw new DomainException('O frete do CT-e deve ser informado como valor monetário com até duas casas decimais.', previous: $exception);
        }
    }

    private function deliveryDate(?string $deliveryDate): string
    {
        $deliveryDate ??= now('America/Sao_Paulo')->addDay()->toDateString();

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $deliveryDate)) {
            throw new DomainException('A previsão de entrega deve usar o formato AAAA-MM-DD.');
        }

        [$year, $month, $day] = array_map('intval', explode('-', $deliveryDate));

        if (! checkdate($month, $day, $year)) {
            throw new DomainException('A previsão de entrega informada não é uma data válida.');
        }

        if ($deliveryDate < now('America/Sao_Paulo')->toDateString()) {
            throw new DomainException('A previsão de entrega não pode ser anterior à data de emissão.');
        }

        return $deliveryDate;
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @param  list<CteDocumentNfe>  $nfeDocuments
     * @return list<array<string, mixed>>
     */
    private function applyOperatorComplements(array $documents, array $nfeDocuments): array
    {
        $nfeDocumentsByKey = collect($nfeDocuments)->keyBy('access_key');

        foreach ($documents as &$document) {
            $nfeDocument = $nfeDocumentsByKey->get($document['access_key']);
            $complements = (array) ($nfeDocument?->operator_complements ?? []);

            if (array_diff(array_keys($complements), ['delivery']) !== []) {
                throw new DomainException("Os complementos da NF-e {$document['source_filename']} só podem alterar dados da entrega física.");
            }

            $deliveryComplements = $complements['delivery'] ?? [];

            if (! is_array($deliveryComplements)) {
                throw new DomainException("Os complementos da entrega da NF-e {$document['source_filename']} devem ser uma lista de campos.");
            }

            $delivery = $document['delivery'] ?? $document['recipient'];
            $missingFields = $document['delivery_missing_fields'] ?? [];

            foreach ($deliveryComplements as $field => $value) {
                if (! is_string($field) || ! in_array($field, self::DELIVERY_FIELDS, true)) {
                    throw new DomainException("A NF-e {$document['source_filename']} contém um campo de entrega não permitido nos complementos.");
                }

                if (! is_string($value) || trim($value) === '') {
                    throw new DomainException("O complemento do campo {$field} da NF-e {$document['source_filename']} deve conter texto.");
                }

                $value = $this->normalizeDeliveryValue($field, $value);
                $this->assertComplementLength($field, $value, $document['source_filename']);
                $sourceValue = $this->normalizeDeliveryValue($field, (string) ($delivery[$field] ?? ''));

                if (filled($delivery[$field] ?? null) && $sourceValue !== $value) {
                    throw new DomainException("O complemento {$field} da NF-e {$document['source_filename']} não pode substituir um dado informado no XML original.");
                }

                if (! in_array($field, $missingFields, true)) {
                    continue;
                }

                $delivery[$field] = $value;
            }

            $document['delivery'] = $delivery;
            $document['delivery_missing_fields'] = $this->missingDeliveryFields($delivery);
        }
        unset($document);

        return $documents;
    }

    private function assertComplementLength(string $field, string $value, string $filename): void
    {
        $maximumLength = self::DELIVERY_FIELD_MAX_LENGTHS[$field] ?? null;

        if ($maximumLength !== null && mb_strlen($value) > $maximumLength) {
            throw new DomainException("O complemento {$field} da NF-e {$filename} excede o limite de {$maximumLength} caracteres.");
        }
    }

    /** @param array<string, string> $delivery */
    private function normalizeDeliveryValue(string $field, string $value): string
    {
        $value = trim($value);

        return match ($field) {
            'cnpj', 'cpf', 'ibge', 'zipcode' => preg_replace('/\D/', '', $value) ?: '',
            'state' => mb_strtoupper($value),
            default => preg_replace('/\s+/u', ' ', $value) ?? $value,
        };
    }

    /** @param array<string, string> $delivery @return list<string> */
    private function missingDeliveryFields(array $delivery): array
    {
        $missing = [];

        foreach (self::REQUIRED_DELIVERY_FIELDS as $field) {
            if (blank($delivery[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        $cnpj = preg_replace('/\D/', '', (string) ($delivery['cnpj'] ?? '')) ?: '';
        $cpf = preg_replace('/\D/', '', (string) ($delivery['cpf'] ?? '')) ?: '';

        if (($cnpj === '') === ($cpf === '')) {
            $missing[] = 'cnpj';
        } elseif ($cnpj !== '' && ! preg_match('/^\d{14}$/', $cnpj)) {
            $missing[] = 'cnpj';
        } elseif ($cpf !== '' && ! preg_match('/^\d{11}$/', $cpf)) {
            $missing[] = 'cpf';
        }

        if (! blank($delivery['ibge'] ?? null) && ! preg_match('/^\d{7}$/', (string) $delivery['ibge'])) {
            $missing[] = 'ibge';
        }

        if (! blank($delivery['zipcode'] ?? null) && ! preg_match('/^\d{8}$/', (string) $delivery['zipcode'])) {
            $missing[] = 'zipcode';
        }

        if (! blank($delivery['state'] ?? null) && ! preg_match('/^[A-Z]{2}$/', (string) $delivery['state'])) {
            $missing[] = 'state';
        }

        return array_values(array_unique($missing));
    }

    /**
     * @param  list<array<string, mixed>>  $deliveryCandidates
     * @return array<string, string>
     */
    private function mergeDelivery(array $deliveryCandidates): array
    {
        $delivery = [];

        foreach ($deliveryCandidates as $candidate) {
            foreach ($candidate['delivery'] as $field => $value) {
                if (blank($delivery[$field] ?? null) && filled($value)) {
                    $delivery[$field] = (string) $value;
                }
            }
        }

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $consolidated
     * @param  array<string, string>  $delivery
     * @param  list<CteDocumentNfe>  $nfeDocuments
     * @return array<string, mixed>
     */
    private function snapshot(
        array $consolidated,
        array $delivery,
        int $freightCents,
        string $deliveryDate,
        array $nfeDocuments,
        CteDocument $document,
    ): array {
        $nfeDocumentsByKey = collect($nfeDocuments)->keyBy('access_key');
        $documents = [];
        $sourceFiles = [];
        $operatorComplements = [];

        foreach ($consolidated['documents'] as $nfe) {
            $nfeDocument = $nfeDocumentsByKey->get($nfe['access_key']);
            $documents[] = [
                'access_key' => $nfe['access_key'],
                'number' => $nfe['number'],
                'series' => $nfe['series'],
                'cargo_value' => $nfe['cargo_value'],
                'liters' => $nfe['cargo_liters'],
                'net_weight_kg' => $nfe['net_weight_kg'],
                'issued_at' => $nfe['issued_at'],
                'source_filename' => $nfe['source_filename'],
            ];
            $sourceFiles[] = $nfe['source_filename'];

            if ($nfeDocument?->operator_complements !== null) {
                $operatorComplements[$nfe['access_key']] = [
                    'values' => $nfeDocument->operator_complements,
                    'user_id' => $nfeDocument->operator_complements_user_id,
                ];
            }
        }

        $destination = $this->citySnapshot($delivery);
        $warnings = $this->reuseWarnings(array_column($documents, 'access_key'), $document->id);

        return [
            'schema_version' => 3,
            'line' => 'tank_alcohol',
            'modal' => (string) config('dfe.cte.modal'),
            'value' => ExactDecimal::format($freightCents, 2),
            'dprev' => $deliveryDate,
            'origin' => $this->citySnapshot($consolidated['rem']),
            'destination' => $destination,
            'rem' => $this->partySnapshot($consolidated['rem']),
            'dest' => $this->partySnapshot($consolidated['recipient']),
            'delivery_party' => $this->partySnapshot($delivery),
            'delivery_missing_fields' => $consolidated['delivery_missing_fields'],
            'delivery_candidates' => $consolidated['delivery_candidates'],
            'products' => $consolidated['products'],
            'cargo_value' => $consolidated['cargo_value'],
            'cargo_liters' => $consolidated['cargo_liters'],
            'cargo_weight_kg' => $consolidated['net_weight_kg'],
            'nfe_documents' => $documents,
            'source_files' => $sourceFiles,
            'operator_complements' => $operatorComplements,
            'reuse_warnings' => $warnings,
        ];
    }

    /** @param array<string, string> $party @return array{ibge: string, name: string, uf: string} */
    private function citySnapshot(array $party): array
    {
        return [
            'ibge' => (string) ($party['ibge'] ?? ''),
            'name' => (string) ($party['city'] ?? ''),
            'uf' => (string) ($party['state'] ?? ''),
        ];
    }

    /** @param array<string, string> $party @return array<string, string> */
    private function partySnapshot(array $party): array
    {
        return [
            'cnpj' => (string) ($party['cnpj'] ?? ''),
            'cpf' => (string) ($party['cpf'] ?? ''),
            'ie' => (string) ($party['ie'] ?? ''),
            'xNome' => (string) ($party['name'] ?? ''),
            'xLgr' => (string) ($party['street'] ?? ''),
            'nro' => (string) ($party['number'] ?? ''),
            'xCpl' => (string) ($party['complement'] ?? ''),
            'xBairro' => (string) ($party['district'] ?? ''),
            'cMun' => (string) ($party['ibge'] ?? ''),
            'xMun' => (string) ($party['city'] ?? ''),
            'cep' => (string) ($party['zipcode'] ?? ''),
            'uf' => (string) ($party['state'] ?? ''),
        ];
    }

    /**
     * @param  list<string>  $accessKeys
     * @return list<array<string, mixed>>
     */
    private function reuseWarnings(array $accessKeys, int $currentDocumentId): array
    {
        $warnings = [];
        $linkedDocuments = CteDocumentNfe::query()
            ->with('document')
            ->whereIn('access_key', $accessKeys)
            ->where('cte_document_id', '!=', $currentDocumentId)
            ->get();

        foreach ($linkedDocuments as $nfeDocument) {
            if ($nfeDocument->document !== null) {
                $warnings[$nfeDocument->access_key.':'.$nfeDocument->document->id] = $this->reuseWarning(
                    $nfeDocument->access_key,
                    $nfeDocument->document,
                );
            }
        }

        $legacyDocuments = CteDocument::query()
            ->whereKeyNot($currentDocumentId)
            ->whereIn('snapshot->nfe_key', $accessKeys)
            ->get(['id', 'snapshot', 'cte_number', 'access_key', 'status', 'fiscal_status', 'authorized_at']);

        foreach ($legacyDocuments as $legacyDocument) {
            $accessKey = (string) ($legacyDocument->snapshot['nfe_key'] ?? '');
            $warnings[$accessKey.':'.$legacyDocument->id] = $this->reuseWarning($accessKey, $legacyDocument);
        }

        return array_values($warnings);
    }

    /** @return array<string, mixed> */
    private function reuseWarning(string $accessKey, CteDocument $document): array
    {
        return [
            'access_key' => $accessKey,
            'cte_document_id' => $document->id,
            'cte_number' => $document->cte_number,
            'cte_access_key' => $document->access_key,
            'status' => $document->status instanceof CteDocumentStatusEnum ? $document->status->value : (string) $document->status,
            'fiscal_status' => $document->fiscal_status instanceof FiscalDocumentStatusEnum
                ? $document->fiscal_status->value
                : ($document->fiscal_status === null ? null : (string) $document->fiscal_status),
            'authorized_at' => $document->authorized_at?->toIso8601String(),
        ];
    }
}
