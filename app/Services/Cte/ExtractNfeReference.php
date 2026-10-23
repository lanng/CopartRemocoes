<?php

namespace App\Services\Cte;

use App\Services\Dfe\AccessKey;
use DomainException;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

class ExtractNfeReference
{
    private const NAMESPACE = 'http://www.portalfiscal.inf.br/nfe';

    public function fromKey(string $accessKey): string
    {
        $accessKey = trim($accessKey);

        if (! preg_match('/^\d{44}$/', $accessKey)
            || substr($accessKey, 20, 2) !== '55'
            || AccessKey::mod11(substr($accessKey, 0, 43)) !== substr($accessKey, -1)) {
            throw new DomainException('Informe uma chave de NF-e modelo 55 com 44 dígitos e dígito verificador válido.');
        }

        return $accessKey;
    }

    public function fromXml(string $xml, string $filename): string
    {
        return $this->extractFromXml($xml, $filename)['access_key'];
    }

    /**
     * @return array{
     *     access_key: string,
     *     issuer: array<string, string>,
     *     recipient: array<string, string>,
     *     products: list<array{code: string, name: string, ncm: string, unit: string, quantity: string, value: string}>,
     *     cargo_value: ?string,
     *     gross_weight_kg: ?string,
     *     net_weight_kg: ?string,
     *     volume_count: ?string,
     *     measurements: list<array{unit: string, measure: string, quantity: string}>,
     *     warnings: list<string>
     * }
     */
    public function extractFromXml(string $xml, string $filename): array
    {
        $maxFileSizeBytes = (int) config('dfe.nfe_xml_max_file_size_bytes', 10 * 1024 * 1024);

        if (strlen($xml) > $maxFileSizeBytes) {
            throw new DomainException("O arquivo {$filename} excede o limite de {$maxFileSizeBytes} bytes por XML.");
        }

        if (blank($xml) || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new DomainException("O arquivo {$filename} está vazio ou contém declarações XML não permitidas.");
        }

        $xpath = $this->xpath($xml, $filename);
        $info = $xpath->query('/n:nfeProc/n:NFe/n:infNFe | /n:NFe/n:infNFe')?->item(0);

        if (! $info instanceof DOMElement || $this->value($xpath, './n:ide/n:mod', $info) !== '55') {
            throw new DomainException("O arquivo {$filename} não contém uma NF-e modelo 55 válida.");
        }

        $id = $info->getAttribute('Id');
        $accessKey = str_starts_with($id, 'NFe') ? substr($id, 3) : '';

        try {
            $accessKey = $this->fromKey($accessKey);
        } catch (DomainException $exception) {
            throw new DomainException("A NF-e {$filename} não possui chave modelo 55 válida no Id de infNFe.", previous: $exception);
        }

        $issuer = $this->party($xpath, $info, './n:emit', './n:enderEmit');
        $recipient = $this->party($xpath, $info, './n:dest', './n:enderDest');
        $warnings = [];
        $products = [];
        $commercialQuantities = [];

        foreach ($xpath->query('./n:det/n:prod', $info) ?: [] as $product) {
            if (! $product instanceof DOMElement) {
                continue;
            }

            $unit = strtoupper(trim($this->value($xpath, './n:uCom', $product)));
            $quantity = $this->formatOptionalDecimal($this->value($xpath, './n:qCom', $product), 4, $filename, 'qCom', $warnings);
            $value = $this->formatOptionalDecimal($this->value($xpath, './n:vProd', $product), 2, $filename, 'vProd', $warnings);
            $products[] = [
                'code' => trim($this->value($xpath, './n:cProd', $product)),
                'name' => trim($this->value($xpath, './n:xProd', $product)),
                'ncm' => preg_replace('/\D/', '', $this->value($xpath, './n:NCM', $product)) ?: '',
                'unit' => $unit,
                'quantity' => $quantity ?? '',
                'value' => $value ?? '',
            ];

            $cteUnit = $this->cteUnit($unit);

            if ($cteUnit !== null && $quantity !== null) {
                $commercialQuantities[$cteUnit] = ExactDecimal::add(
                    $commercialQuantities[$cteUnit] ?? 0,
                    ExactDecimal::parse($quantity, 4, $filename, 'qCom'),
                    $filename,
                    'qCom',
                );
            } elseif ($unit !== '' && $quantity !== null) {
                $warnings[] = "Unidade comercial {$unit} requer escolha manual de medida CT-e.";
            }
        }

        if ($products === []) {
            $warnings[] = 'Nenhum item de produto foi encontrado no XML.';
        }

        $cargoValue = $this->formatOptionalDecimal(
            $this->value($xpath, './n:total/n:ICMSTot/n:vNF', $info),
            2,
            $filename,
            'vNF',
            $warnings,
        );
        $volumeGroups = $xpath->query('./n:transp/n:vol', $info);
        $volumeGroups = $volumeGroups === false ? [] : iterator_to_array($volumeGroups);
        $grossWeight = $this->sumVolumeValues($xpath, $volumeGroups, 'pesoB', $filename, 'PESO BRUTO');
        $netWeight = $this->sumVolumeValues($xpath, $volumeGroups, 'pesoL', $filename, 'PESO LÍQUIDO');
        $volumeCount = $this->sumVolumeValues($xpath, $volumeGroups, 'qVol', $filename, 'qVol');

        if ($netWeight === null) {
            $warnings[] = 'PESO LÍQUIDO não encontrado em transp/vol/pesoL.';
        }

        if ($volumeCount === null) {
            $warnings[] = 'volume qVol não encontrado em transp/vol.';
        }

        $measurements = [];

        if ($grossWeight !== null) {
            $measurements[] = ['unit' => '01', 'measure' => 'PESO BRUTO', 'quantity' => $grossWeight];
        }

        if ($netWeight !== null) {
            $measurements[] = ['unit' => '01', 'measure' => 'PESO LÍQUIDO', 'quantity' => $netWeight];
        }

        foreach ($commercialQuantities as $unit => $quantity) {
            $measurements[] = ['unit' => $unit, 'measure' => 'QTD COMERCIAL', 'quantity' => ExactDecimal::format($quantity, 4)];
        }

        if ($volumeCount !== null) {
            $measurements[] = ['unit' => '03', 'measure' => 'VOLUMES', 'quantity' => $volumeCount];
        }

        return [
            'access_key' => $accessKey,
            'issuer' => $issuer,
            'recipient' => $recipient,
            'products' => $products,
            'cargo_value' => $cargoValue,
            'gross_weight_kg' => $grossWeight,
            'net_weight_kg' => $netWeight,
            'volume_count' => $volumeCount,
            'measurements' => $measurements,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function xpath(string $xml, string $filename): DOMXPath
    {
        $document = new DOMDocument;
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
            $errors = libxml_get_errors();
        } catch (Throwable) {
            $loaded = false;
            $errors = [true];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        if (! $loaded || $errors !== []) {
            throw new DomainException("Não foi possível ler o XML da NF-e {$filename}.");
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('n', self::NAMESPACE);

        return $xpath;
    }

    /** @return array<string, string> */
    private function party(DOMXPath $xpath, DOMElement $info, string $partyPath, string $addressPath): array
    {
        $party = $xpath->query($partyPath, $info)?->item(0);
        $party = $party instanceof DOMElement ? $party : null;

        if ($party === null) {
            return [];
        }

        $address = $xpath->query($addressPath, $party)?->item(0);
        $address = $address instanceof DOMElement ? $address : null;
        $value = fn (string $path): string => trim($this->value($xpath, $path, $party));
        $addressValue = fn (string $path): string => $address === null ? '' : trim($this->value($xpath, $path, $address));

        return [
            'cnpj' => preg_replace('/\D/', '', $value('./n:CNPJ')) ?: '',
            'cpf' => preg_replace('/\D/', '', $value('./n:CPF')) ?: '',
            'name' => $value('./n:xNome'),
            'ie' => $value('./n:IE'),
            'street' => $addressValue('./n:xLgr'),
            'number' => $addressValue('./n:nro'),
            'complement' => $addressValue('./n:xCpl'),
            'district' => $addressValue('./n:xBairro'),
            'ibge' => preg_replace('/\D/', '', $addressValue('./n:cMun')) ?: '',
            'city' => $addressValue('./n:xMun'),
            'uf' => strtoupper($addressValue('./n:UF')),
            'zipcode' => preg_replace('/\D/', '', $addressValue('./n:CEP')) ?: '',
        ];
    }

    private function value(DOMXPath $xpath, string $query, ?DOMElement $context = null): string
    {
        $value = $xpath->evaluate('string('.$query.')', $context);

        return is_string($value) ? $value : '';
    }

    /** @param list<string> $warnings */
    private function formatOptionalDecimal(string $value, int $scale, string $filename, string $field, array &$warnings): ?string
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return ExactDecimal::format(ExactDecimal::parse(trim($value), $scale, $filename, $field), $scale);
        } catch (DomainException) {
            $warnings[] = "Campo {$field} possui número inválido e precisa de revisão manual.";

            return null;
        }
    }

    /**
     * @param  list<DOMElement>  $volumeGroups
     */
    private function sumVolumeValues(DOMXPath $xpath, array $volumeGroups, string $field, string $filename, string $label): ?string
    {
        if ($volumeGroups === []) {
            return null;
        }

        $total = 0;

        foreach ($volumeGroups as $volume) {
            $value = trim($this->value($xpath, './n:'.$field, $volume));

            if ($value === '') {
                return null;
            }

            try {
                $total = ExactDecimal::add($total, ExactDecimal::parse($value, 4, $filename, $field), $filename, $field);
            } catch (DomainException) {
                return null;
            }
        }

        return ExactDecimal::format($total, 4);
    }

    private function cteUnit(string $nfeUnit): ?string
    {
        return match (strtoupper(trim($nfeUnit))) {
            'M3' => '00',
            'KG' => '01',
            'TON', 'T' => '02',
            'UN', 'UND', 'UNID', 'UNIDADE', 'UNIDADES' => '03',
            'L', 'LT', 'LITRO', 'LITROS' => '04',
            'MMBTU' => '05',
            default => null,
        };
    }
}
