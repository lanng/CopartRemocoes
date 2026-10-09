<?php

namespace App\Services\Cte;

use DomainException;
use Illuminate\Support\Str;

class ConsolidateAlcoholNfes
{
    /**
     * @param  list<array<string, mixed>>  $documents
     * @return array{
     *     documents: list<array<string, mixed>>,
     *     rem: array<string, string>,
     *     recipient: array<string, string>,
     *     delivery: array<string, string>,
     *     delivery_candidates: list<array{
     *         access_key: string,
     *         source_filename: string,
     *         delivery: array<string, string>,
     *         missing_fields: list<string>
     *     }>,
     *     delivery_missing_fields: list<string>,
     *     products: list<array<string, string>>,
     *     cargo_value: string,
     *     cargo_liters: string,
     *     net_weight_kg: string
     * }
     */
    public function handle(array $documents): array
    {
        if ($documents === []) {
            throw new DomainException('Selecione ao menos uma NF-e para consolidar.');
        }

        $maxFilesPerImport = (int) config('dfe.nfe_xml_max_files_per_import', 10);

        if (count($documents) > $maxFilesPerImport) {
            throw new DomainException("A seleção excede o limite de {$maxFilesPerImport} NF-es por importação.");
        }

        $seenKeys = [];
        $first = $documents[0];
        $productGroups = $this->productGroups($first);
        foreach ($productGroups as &$productGroup) {
            $productGroup['liters_scaled'] = 0;
            $productGroup['value_cents'] = 0;
        }
        unset($productGroup);

        $valueCents = 0;
        $litersScaled = 0;
        $weightScaled = 0;

        foreach ($documents as $document) {
            $key = (string) ($document['access_key'] ?? '');

            if (isset($seenKeys[$key])) {
                $filename = (string) ($document['source_filename'] ?? $key);
                throw new DomainException("A NF-e {$filename} ({$key}) está repetida na seleção.");
            }

            $seenKeys[$key] = true;
        }

        $this->assertSameDelivery($documents);

        foreach ($documents as $document) {
            $this->assertSameParty($first, $document, 'rem', 'remetente');
            $this->assertSameParty($first, $document, 'recipient', 'destinatário fiscal');
            $this->assertSameProducts($productGroups, $this->productGroups($document), $document);

            $valueCents = $this->addScaledIntegers(
                $valueCents,
                $this->scaledInteger((string) ($document['cargo_value'] ?? ''), 2, $document, 'cargo_value'),
                $document,
                'cargo_value',
            );
            $litersScaled = $this->addScaledIntegers(
                $litersScaled,
                $this->scaledInteger((string) ($document['cargo_liters'] ?? ''), 4, $document, 'cargo_liters'),
                $document,
                'cargo_liters',
            );
            $weightScaled = $this->addScaledIntegers(
                $weightScaled,
                $this->scaledInteger((string) ($document['net_weight_kg'] ?? ''), 3, $document, 'net_weight_kg'),
                $document,
                'net_weight_kg',
            );

            foreach ($document['products'] as $product) {
                $productKey = $this->productKey($product, $document);
                $productGroups[$productKey]['liters_scaled'] = $this->addScaledIntegers(
                    $productGroups[$productKey]['liters_scaled'],
                    $this->scaledInteger((string) $product['liters'], 4, $document, 'products.liters'),
                    $document,
                    'products.liters',
                );
                $productGroups[$productKey]['value_cents'] = $this->addScaledIntegers(
                    $productGroups[$productKey]['value_cents'],
                    $this->scaledInteger((string) $product['value'], 2, $document, 'products.value'),
                    $document,
                    'products.value',
                );
            }
        }

        $products = [];
        foreach ($productGroups as $product) {
            $product['liters'] = ExactDecimal::format($product['liters_scaled'], 4);
            $product['value'] = ExactDecimal::format($product['value_cents'], 2);
            unset($product['liters_scaled'], $product['value_cents']);
            $products[] = $product;
        }

        $deliveryCandidates = $this->deliveryCandidates($documents);
        $deliveryMissingFields = [];

        foreach ($deliveryCandidates as $candidate) {
            foreach ($candidate['missing_fields'] as $missingField) {
                if (! in_array($missingField, $deliveryMissingFields, true)) {
                    $deliveryMissingFields[] = $missingField;
                }
            }
        }

        return [
            'documents' => array_values($documents),
            'rem' => $first['rem'],
            'recipient' => $first['recipient'],
            'delivery' => $first['delivery'],
            'delivery_candidates' => $deliveryCandidates,
            'delivery_missing_fields' => $deliveryMissingFields,
            'products' => $products,
            'cargo_value' => ExactDecimal::format($valueCents, 2),
            'cargo_liters' => ExactDecimal::format($litersScaled, 4),
            'net_weight_kg' => ExactDecimal::format($weightScaled, 3),
        ];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $candidate
     */
    private function assertSameParty(array $first, array $candidate, string $field, string $label): void
    {
        $firstIdentity = $this->taxIdentity($first[$field] ?? []);
        $candidateIdentity = $this->taxIdentity($candidate[$field] ?? []);

        if ($firstIdentity['value'] === '' || $firstIdentity !== $candidateIdentity) {
            $filename = (string) ($candidate['source_filename'] ?? $candidate['access_key'] ?? 'NF-e');
            throw new DomainException("A NF-e {$filename} diverge na identidade fiscal do {$label}.");
        }
    }

    /** @param list<array<string, mixed>> $documents */
    private function assertSameDelivery(array $documents): void
    {
        $knownIdentity = null;

        foreach ($documents as $document) {
            $delivery = $document['delivery'] ?? $document['recipient'] ?? [];
            $candidateIdentity = $this->taxIdentity($delivery);

            if ($candidateIdentity['value'] === '') {
                continue;
            }

            if ($knownIdentity !== null && $knownIdentity !== $candidateIdentity) {
                $filename = (string) ($document['source_filename'] ?? $document['access_key'] ?? 'NF-e');
                throw new DomainException("A NF-e {$filename} diverge na identidade fiscal do local efetivo de entrega.");
            }

            $knownIdentity = $candidateIdentity;
        }

        foreach (['street', 'number', 'complement', 'district', 'ibge', 'city', 'state', 'zipcode'] as $field) {
            $knownValue = null;

            foreach ($documents as $document) {
                $delivery = $document['delivery'] ?? $document['recipient'] ?? [];
                $candidateValue = $this->normalize((string) ($delivery[$field] ?? ''));

                if ($candidateValue === '') {
                    continue;
                }

                if ($knownValue !== null && $knownValue !== $candidateValue) {
                    $filename = (string) ($document['source_filename'] ?? $document['access_key'] ?? 'NF-e');
                    throw new DomainException("A NF-e {$filename} diverge no campo {$field} do local efetivo de entrega.");
                }

                $knownValue = $candidateValue;
            }
        }
    }

    /** @param array<string, mixed> $party @return array{type: string, value: string} */
    private function taxIdentity(array $party): array
    {
        $cnpj = preg_replace('/\D/', '', (string) ($party['cnpj'] ?? '')) ?: '';
        $cpf = preg_replace('/\D/', '', (string) ($party['cpf'] ?? '')) ?: '';

        if ($cnpj !== '') {
            return ['type' => 'cnpj', 'value' => $cnpj];
        }

        return ['type' => $cpf === '' ? '' : 'cpf', 'value' => $cpf];
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     * @return list<array{
     *     access_key: string,
     *     source_filename: string,
     *     delivery: array<string, string>,
     *     missing_fields: list<string>
     * }>
     */
    private function deliveryCandidates(array $documents): array
    {
        return array_map(fn (array $document): array => [
            'access_key' => (string) ($document['access_key'] ?? ''),
            'source_filename' => (string) ($document['source_filename'] ?? ''),
            'delivery' => $document['delivery'] ?? $document['recipient'] ?? [],
            'missing_fields' => array_values(array_unique(array_map(
                'strval',
                $document['delivery_missing_fields'] ?? [],
            ))),
        ], array_values($documents));
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, string>  $actual
     * @param  array<string, mixed>  $document
     */
    private function assertSameProducts(array $expected, array $actual, array $document): void
    {
        $expectedKeys = array_keys($expected);
        $actualKeys = array_keys($actual);
        sort($expectedKeys);
        sort($actualKeys);

        if ($expectedKeys !== $actualKeys) {
            $filename = (string) ($document['source_filename'] ?? $document['access_key'] ?? 'NF-e');
            throw new DomainException("A NF-e {$filename} diverge na identidade do produto (NCM/código ANP/cProd/descrição normalizada).");
        }
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, array<string, mixed>>
     */
    private function productGroups(array $document): array
    {
        $groups = [];

        foreach ($document['products'] ?? [] as $product) {
            $key = $this->productKey($product, $document);

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'code' => (string) ($product['code'] ?? ''),
                    'name' => trim((string) ($product['name'] ?? '')),
                    'ncm' => (string) ($product['ncm'] ?? ''),
                    'anp' => (string) ($product['anp'] ?? ''),
                    'anp_name' => trim((string) ($product['anp_name'] ?? '')),
                    'liters_scaled' => 0,
                    'value_cents' => 0,
                ];
            }

            $groups[$key]['liters_scaled'] = $this->addScaledIntegers(
                $groups[$key]['liters_scaled'],
                $this->scaledInteger((string) ($product['liters'] ?? ''), 4, $document, 'products.liters'),
                $document,
                'products.liters',
            );
            $groups[$key]['value_cents'] = $this->addScaledIntegers(
                $groups[$key]['value_cents'],
                $this->scaledInteger((string) ($product['value'] ?? ''), 2, $document, 'products.value'),
                $document,
                'products.value',
            );
            $groups[$key]['liters'] = ExactDecimal::format($groups[$key]['liters_scaled'], 4);
            $groups[$key]['value'] = ExactDecimal::format($groups[$key]['value_cents'], 2);
        }

        ksort($groups);

        return $groups;
    }

    /** @param array<string, mixed> $product */
    private function productKey(array $product, array $document): string
    {
        $ncm = preg_replace('/\D/', '', (string) ($product['ncm'] ?? '')) ?: '';
        $anp = preg_replace('/\D/', '', (string) ($product['anp'] ?? '')) ?: '';
        $alcoholNcm = preg_replace('/\D/', '', (string) config('dfe.mdfe.ncm_tank_alcohol', '2207.10.90')) ?: '';

        if ($ncm !== $alcoholNcm || $anp === '') {
            $filename = (string) ($document['source_filename'] ?? $document['access_key'] ?? 'NF-e');
            throw new DomainException("A NF-e {$filename} não possui NCM e cProdANP de álcool compatíveis com a configuração.");
        }

        $productIdentity = $this->normalize((string) ($product['name'] ?? ''));
        $productCode = mb_strtoupper(trim((string) ($product['code'] ?? '')));

        if ($productIdentity === '') {
            $filename = (string) ($document['source_filename'] ?? $document['access_key'] ?? 'NF-e');
            throw new DomainException("A NF-e {$filename} não informa a descrição do produto necessária para validar sua identidade.");
        }

        return "{$ncm}:{$anp}:{$productCode}:{$productIdentity}";
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(Str::ascii(trim($value)));
        $value = str_replace(['.', ',', '-', '/', '\\'], '', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /** @param array<string, mixed> $document */
    private function scaledInteger(string $value, int $scale, array $document, string $field): int
    {
        return ExactDecimal::parse($value, $scale, $this->filename($document), $field);
    }

    /** @param array<string, mixed> $document */
    private function addScaledIntegers(int $total, int $value, array $document, string $field): int
    {
        return ExactDecimal::add($total, $value, $this->filename($document), $field);
    }

    /** @param array<string, mixed> $document */
    private function filename(array $document): string
    {
        return (string) ($document['source_filename'] ?? $document['access_key'] ?? 'NF-e');
    }
}
