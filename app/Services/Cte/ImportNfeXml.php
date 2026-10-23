<?php

namespace App\Services\Cte;

use DomainException;
use DOMDocument;
use DOMElement;
use DOMXPath;

class ImportNfeXml
{
    private const NAMESPACE = 'http://www.portalfiscal.inf.br/nfe';

    /**
     * @return array{
     *     access_key: string,
     *     number: string,
     *     series: string,
     *     issued_at: string,
     *     rem: array<string, string>,
     *     recipient: array<string, string>,
     *     delivery: array<string, string>,
     *     delivery_missing_fields: list<string>,
     *     products: list<array<string, string>>,
     *     cargo_value: string,
     *     cargo_liters: string,
     *     net_weight_kg: string,
     *     protocol: string,
     *     source_filename: string
     * }
     */
    public function handle(string $xml, string $filename): array
    {
        $maxFileSizeBytes = (int) config('dfe.nfe_xml_max_file_size_bytes', 10 * 1024 * 1024);

        if (strlen($xml) > $maxFileSizeBytes) {
            throw new DomainException("O arquivo {$filename} excede o limite de {$maxFileSizeBytes} bytes por XML.");
        }

        if (blank($xml) || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new DomainException("O arquivo {$filename} está vazio ou contém declarações XML não permitidas.");
        }

        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
            $errors = libxml_get_errors();
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $loaded || $errors !== []) {
            throw new DomainException("Não foi possível ler o XML da NF-e {$filename}.");
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('n', self::NAMESPACE);
        $info = $this->requiredElement($xpath, '/n:nfeProc/n:NFe/n:infNFe | /n:NFe/n:infNFe', null, $filename, 'infNFe');
        $id = $info->getAttribute('Id');
        $accessKey = str_starts_with($id, 'NFe') ? substr($id, 3) : '';

        if (! preg_match('/^\d{44}$/', $accessKey)
            || substr($accessKey, 20, 2) !== '55'
            || ! $this->hasValidAccessKeyCheckDigit($accessKey)) {
            throw new DomainException("A NF-e {$filename} não possui chave de acesso válida no Id de infNFe.");
        }

        if ($this->value($xpath, './n:ide/n:mod', $info) !== '55') {
            throw new DomainException("O arquivo {$filename} não é uma NF-e modelo 55.");
        }

        $protocol = $this->requiredElement($xpath, '/n:nfeProc/n:protNFe/n:infProt', null, $filename, 'protocolo');
        if (! in_array($this->value($xpath, './n:cStat', $protocol), ['100', '150'], true)
            || $this->value($xpath, './n:chNFe', $protocol) !== $accessKey) {
            throw new DomainException("A NF-e {$filename} não contém protocolo de autorização válido correspondente à chave.");
        }

        $protocolNumber = $this->value($xpath, './n:nProt', $protocol);
        if (! preg_match('/^\d{15}$/', $protocolNumber)) {
            throw new DomainException("A NF-e {$filename} não contém número de protocolo de autorização válido.");
        }

        $issuer = $this->party($xpath, $this->requiredElement($xpath, './n:emit', $info, $filename, 'emitente'), 'emitente', $filename);
        $recipient = $this->party($xpath, $this->requiredElement($xpath, './n:dest', $info, $filename, 'destinatário'), 'destinatário', $filename);
        $deliveryElement = $xpath->query('./n:entrega', $info)?->item(0);
        $delivery = $deliveryElement instanceof DOMElement
            ? $this->party($xpath, $deliveryElement, 'entrega', $filename)
            : $recipient;
        $deliveryMissingFields = $deliveryElement instanceof DOMElement
            ? $this->missingPartyFields($delivery)
            : [];

        $products = [];
        $liters = 0;
        $productValueCents = 0;
        $details = $xpath->query('./n:det', $info);

        foreach ($details ?: [] as $detail) {
            if (! $detail instanceof DOMElement) {
                continue;
            }

            $commercialUnit = $this->normalizedVolumeUnit($this->value($xpath, './n:prod/n:uCom', $detail));
            $taxedUnit = $this->normalizedVolumeUnit($this->value($xpath, './n:prod/n:uTrib', $detail));

            if ($commercialUnit === null || $taxedUnit === null || $commercialUnit !== $taxedUnit) {
                throw new DomainException("A NF-e {$filename} possui unidades comerciais e tributárias incompatíveis; revisão manual necessária.");
            }

            $commercialQuantity = trim($this->value($xpath, './n:prod/n:qCom', $detail));
            $taxedQuantity = trim($this->value($xpath, './n:prod/n:qTrib', $detail));
            $quantity = ExactDecimal::parse($commercialQuantity, 4, $filename, 'qCom');
            $taxedQuantity = ExactDecimal::parse($taxedQuantity, 4, $filename, 'qTrib');

            if ($quantity !== $taxedQuantity) {
                throw new DomainException("A NF-e {$filename} possui quantidade comercial e tributária divergentes; revisão manual necessária.");
            }

            if ($commercialUnit === 'M3') {
                if ($quantity > intdiv(PHP_INT_MAX, 1000)) {
                    throw new DomainException("A NF-e {$filename} excede o limite para conversão exata de m³ para litros no campo qCom.");
                }

                $quantity *= 1000;
            }

            $liters = ExactDecimal::add($liters, $quantity, $filename, 'qCom');
            $productValue = ExactDecimal::parse(trim($this->value($xpath, './n:prod/n:vProd', $detail)), 2, $filename, 'vProd');
            $productValueCents = ExactDecimal::add($productValueCents, $productValue, $filename, 'vProd');
            $products[] = [
                'code' => $this->value($xpath, './n:prod/n:cProd', $detail),
                'name' => trim($this->value($xpath, './n:prod/n:xProd', $detail)),
                'ncm' => preg_replace('/\D/', '', $this->value($xpath, './n:prod/n:NCM', $detail)) ?: '',
                'anp' => $this->value($xpath, './n:prod/n:comb/n:cProdANP', $detail),
                'anp_name' => trim($this->value($xpath, './n:prod/n:comb/n:descANP', $detail)),
                'liters' => ExactDecimal::format($quantity, 4),
                'value' => ExactDecimal::format($productValue, 2),
            ];
        }

        if ($products === []) {
            throw new DomainException("A NF-e {$filename} não contém itens de carga.");
        }

        $volumeGroups = $xpath->query('./n:transp/n:vol', $info);
        $weight = 0;

        if ($volumeGroups === false || $volumeGroups->length === 0) {
            throw new DomainException("A NF-e {$filename} não informa peso líquido estruturado em transp/vol/pesoL.");
        }

        foreach ($volumeGroups as $position => $volumeGroup) {
            if (! $volumeGroup instanceof DOMElement) {
                continue;
            }

            $volumeWeights = $xpath->query('./n:pesoL', $volumeGroup);
            $weightNode = $volumeWeights === false ? null : $volumeWeights->item(0);

            if ($volumeWeights === false || $volumeWeights->length !== 1 || ! $weightNode instanceof DOMElement) {
                $volumeNumber = $position + 1;
                throw new DomainException("A NF-e {$filename} possui volume {$volumeNumber} sem peso líquido válido no campo pesoL.");
            }

            $volumeWeight = ExactDecimal::parse(trim($weightNode->textContent), 3, $filename, 'pesoL');

            if ($volumeWeight <= 0) {
                $volumeNumber = $position + 1;
                throw new DomainException("A NF-e {$filename} possui peso não positivo no campo pesoL do volume {$volumeNumber}.");
            }

            $weight = ExactDecimal::add($weight, $volumeWeight, $filename, 'pesoL');
        }

        if ($weight <= 0) {
            throw new DomainException("A NF-e {$filename} não informa peso líquido estruturado em transp/vol/pesoL.");
        }

        $cargoValue = trim($this->value($xpath, './n:total/n:ICMSTot/n:vNF', $info));
        $cargoValueCents = ExactDecimal::parse($cargoValue, 2, $filename, 'vNF');
        if ($cargoValueCents <= 0) {
            throw new DomainException("A NF-e {$filename} não informa valor total de nota válido.");
        }

        if ($cargoValueCents !== $productValueCents) {
            throw new DomainException("A NF-e {$filename} possui vNF diferente da soma de vProd; revisão manual necessária.");
        }

        return [
            'access_key' => $accessKey,
            'number' => $this->value($xpath, './n:ide/n:nNF', $info),
            'series' => $this->value($xpath, './n:ide/n:serie', $info),
            'issued_at' => $this->value($xpath, './n:ide/n:dhEmi', $info),
            'rem' => $issuer,
            'recipient' => $recipient,
            'delivery' => $delivery,
            'delivery_missing_fields' => $deliveryMissingFields,
            'products' => $products,
            'cargo_value' => ExactDecimal::format($cargoValueCents, 2),
            'cargo_liters' => ExactDecimal::format($liters, 4),
            'net_weight_kg' => ExactDecimal::format($weight, 3),
            'protocol' => $protocolNumber,
            'source_filename' => $filename,
        ];
    }

    private function hasValidAccessKeyCheckDigit(string $accessKey): bool
    {
        $sum = 0;
        $weight = 2;

        for ($position = 42; $position >= 0; $position--) {
            $sum += (int) $accessKey[$position] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $remainder = $sum % 11;
        $checkDigit = $remainder < 2 ? 0 : 11 - $remainder;

        return $checkDigit === (int) $accessKey[43];
    }

    private function normalizedVolumeUnit(string $unit): ?string
    {
        return match (strtoupper(trim($unit))) {
            'L', 'LT' => 'L',
            'M3' => 'M3',
            default => null,
        };
    }

    /** @return array<string, string> */
    private function party(DOMXPath $xpath, DOMElement $party, string $role, string $filename): array
    {
        $addressPath = $role === 'destinatário' ? './n:enderDest' : './n:enderEmit';
        if ($role === 'entrega') {
            $addressPath = '.';
        }

        $result = [
            'cnpj' => preg_replace('/\D/', '', $this->value($xpath, './n:CNPJ', $party)) ?: '',
            'cpf' => preg_replace('/\D/', '', $this->value($xpath, './n:CPF', $party)) ?: '',
            'name' => trim($this->value($xpath, './n:xNome', $party)),
            'ie' => trim($this->value($xpath, './n:IE', $party)),
            'street' => trim($this->value($xpath, "{$addressPath}/n:xLgr", $party)),
            'number' => trim($this->value($xpath, "{$addressPath}/n:nro", $party)),
            'complement' => trim($this->value($xpath, "{$addressPath}/n:xCpl", $party)),
            'district' => trim($this->value($xpath, "{$addressPath}/n:xBairro", $party)),
            'ibge' => preg_replace('/\D/', '', $this->value($xpath, "{$addressPath}/n:cMun", $party)) ?: '',
            'city' => trim($this->value($xpath, "{$addressPath}/n:xMun", $party)),
            'state' => strtoupper(trim($this->value($xpath, "{$addressPath}/n:UF", $party))),
            'zipcode' => preg_replace('/\D/', '', $this->value($xpath, "{$addressPath}/n:CEP", $party)) ?: '',
        ];

        if ($result['cnpj'] !== '' && $result['cpf'] !== '') {
            throw new DomainException("A NF-e {$filename} possui CNPJ e CPF simultâneos no cadastro de {$role}.");
        }

        $missingFields = $this->missingPartyFields($result);
        if ($role !== 'entrega' && $missingFields !== []) {
            throw new DomainException("A NF-e {$filename} possui {$role} incompleto: falta {$missingFields[0]}.");
        }

        return $result;
    }

    /** @param array<string, string> $party @return list<string> */
    private function missingPartyFields(array $party): array
    {
        $missingFields = [];

        foreach (['name', 'street', 'number', 'district', 'ibge', 'city', 'state', 'zipcode'] as $field) {
            if (blank($party[$field] ?? null)) {
                $missingFields[] = $field;
            }
        }

        $cnpj = (string) ($party['cnpj'] ?? '');
        $cpf = (string) ($party['cpf'] ?? '');

        if ($cnpj === '' && $cpf === '') {
            $missingFields[] = 'cnpj';
        }

        if ($cnpj !== '' && ! preg_match('/^\d{14}$/', $cnpj)) {
            $missingFields[] = 'cnpj';
        }

        if ($cpf !== '' && ! preg_match('/^\d{11}$/', $cpf)) {
            $missingFields[] = 'cpf';
        }

        if (! blank($party['ibge'] ?? null) && ! preg_match('/^\d{7}$/', $party['ibge'])) {
            $missingFields[] = 'ibge';
        }

        return array_values(array_unique($missingFields));
    }

    private function requiredElement(DOMXPath $xpath, string $query, ?DOMElement $context, string $filename, string $label): DOMElement
    {
        $node = $xpath->query($query, $context)?->item(0);

        if (! $node instanceof DOMElement) {
            throw new DomainException("A NF-e {$filename} não contém {$label} estruturado.");
        }

        return $node;
    }

    private function value(DOMXPath $xpath, string $query, ?DOMElement $context = null): string
    {
        $node = $xpath->query($query, $context)?->item(0);

        return $node instanceof DOMElement ? trim($node->textContent) : '';
    }
}
