<?php

namespace App\Services\Dfe;

use DomainException;
use DOMDocument;
use DOMElement;
use Throwable;

class DamdfeData
{
    /**
     * @return array<string, mixed>
     */
    public static function fromProc(string $mdfeProcXml): array
    {
        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($mdfeProcXml);
        } catch (Throwable) {
            $loaded = false;
        }

        $root = $loaded ? $document->documentElement : null;

        if (! $root instanceof DOMElement || $root->localName !== 'mdfeProc') {
            throw new DomainException('DAMDFE exige o mdfeProc autorizado (MDF-e e protocolo).');
        }

        $mdfe = self::first($root, 'MDFe');
        $infMdfe = $mdfe !== null ? self::first($mdfe, 'infMDFe') : null;
        $protocol = self::first($root, 'protMDFe');
        $infProt = $protocol !== null ? self::first($protocol, 'infProt') : null;

        if ($infMdfe === null || $infProt === null || self::text($infProt, 'cStat') !== '100') {
            throw new DomainException('DAMDFE só pode ser gerado a partir de MDF-e autorizado.');
        }

        $accessKey = substr((string) $infMdfe->getAttribute('Id'), 4);

        if (! preg_match('/^\d{44}$/', $accessKey) || self::text($infProt, 'chMDFe') !== $accessKey) {
            throw new DomainException('A chave do MDF-e diverge entre o documento e o protocolo autorizado.');
        }

        $ide = self::required($infMdfe, 'ide');
        $emit = self::required($infMdfe, 'emit');
        $enderEmit = self::required($emit, 'enderEmit');
        $modal = self::required($infMdfe, 'infModal');
        $rodo = self::required($modal, 'rodo');
        $antt = self::required($rodo, 'infANTT');
        $tractor = self::required($rodo, 'veicTracao');
        $cargo = self::required($infMdfe, 'prodPred');
        $totals = self::required($infMdfe, 'tot');
        $infoDoc = self::required($infMdfe, 'infDoc');
        $municipalities = [];
        $cteAccessKeys = [];
        $nfeAccessKeys = [];

        foreach (self::children($infoDoc, 'infMunDescarga') as $unload) {
            $municipalities[] = [
                'ibge' => self::text($unload, 'cMunDescarga'),
                'name' => self::text($unload, 'xMunDescarga'),
            ];

            foreach (self::children($unload, 'infCTe') as $cte) {
                $cteAccessKeys[] = self::text($cte, 'chCTe');
            }

            foreach (self::children($unload, 'infNFe') as $nfe) {
                $nfeAccessKeys[] = self::text($nfe, 'chNFe');
            }
        }

        $drivers = array_map(fn (DOMElement $driver): array => [
            'name' => self::text($driver, 'xNome'),
            'cpf' => self::text($driver, 'CPF'),
        ], self::children($tractor, 'condutor'));
        $trailers = array_map(fn (DOMElement $trailer): array => [
            'plate' => self::text($trailer, 'placa'),
            'renavam' => self::optional($trailer, 'RENAVAM'),
        ], self::children($rodo, 'veicReboque'));
        $insuranceGroup = self::first($infMdfe, 'seg');
        $insurerGroup = $insuranceGroup !== null ? self::first($insuranceGroup, 'infSeg') : null;
        $supplementaryInfo = self::first($mdfe, 'infMDFeSupl');
        $qr = $supplementaryInfo !== null ? self::first($supplementaryInfo, 'qrCodMDFe') : null;
        $ciotGroup = self::first($antt, 'infCIOT');
        $additionalInfo = self::first($infMdfe, 'infAdic');
        $insuranceResponsible = $insuranceGroup !== null ? self::first($insuranceGroup, 'infResp') : null;
        $insuranceResponsibleCode = $insuranceResponsible !== null
            ? self::optional($insuranceResponsible, 'respSeg')
            : ($insuranceGroup !== null ? self::optional($insuranceGroup, 'respSeg') : null);

        return [
            'access_key' => $accessKey,
            'grouped_access_key' => implode(' ', str_split($accessKey, 4)),
            'number' => self::text($ide, 'nMDF'),
            'series' => self::text($ide, 'serie'),
            'issue_at' => self::text($ide, 'dhEmi'),
            'environment' => self::text($ide, 'tpAmb'),
            'protocol' => self::text($infProt, 'nProt'),
            'authorized_at' => self::optional($infProt, 'dhRecbto'),
            'emitter' => [
                'cnpj' => self::text($emit, 'CNPJ'),
                'name' => self::text($emit, 'xNome'),
                'ie' => self::text($emit, 'IE'),
                'street' => self::text($enderEmit, 'xLgr'),
                'number' => self::text($enderEmit, 'nro'),
                'district' => self::text($enderEmit, 'xBairro'),
                'city' => self::text($enderEmit, 'xMun'),
                'ibge' => self::text($enderEmit, 'cMun'),
                'uf' => self::text($enderEmit, 'UF'),
                'cep' => self::text($enderEmit, 'CEP'),
            ],
            'origin_uf' => self::text($ide, 'UFIni'),
            'destination_uf' => self::text($ide, 'UFFim'),
            'route_ufs' => array_map(fn (DOMElement $route): string => self::text($route, 'UFPer'), self::children($ide, 'infPercurso')),
            'loading_cities' => array_map(fn (DOMElement $city): array => [
                'ibge' => self::text($city, 'cMunCarrega'),
                'name' => self::text($city, 'xMunCarrega'),
            ], self::children($ide, 'infMunCarrega')),
            'unload_cities' => $municipalities,
            'cte_access_keys' => $cteAccessKeys,
            'nfe_access_keys' => $nfeAccessKeys,
            'rntrc' => self::text($antt, 'RNTRC'),
            'ciot' => $ciotGroup !== null ? self::text($ciotGroup, 'CIOT') : null,
            'vehicle' => [
                'plate' => self::text($tractor, 'placa'),
                'renavam' => self::optional($tractor, 'RENAVAM'),
                'tare_kg' => self::text($tractor, 'tara'),
                'capacity_kg' => self::optional($tractor, 'capKG'),
                'capacity_m3' => self::optional($tractor, 'capM3'),
                'type_rod' => self::text($tractor, 'tpRod'),
                'body_type' => self::text($tractor, 'tpCar'),
                'uf' => self::text($tractor, 'UF'),
                'drivers' => $drivers,
                'trailers' => $trailers,
            ],
            'cargo' => [
                'product' => self::text($cargo, 'xProd'),
                'type' => self::text($cargo, 'tpCarga'),
                'value' => self::text($totals, 'vCarga'),
                'quantity' => self::text($totals, 'qCarga'),
                'unit' => self::text($totals, 'cUnid'),
                'cte_count' => self::text($totals, 'qCTe'),
                'nfe_count' => self::optional($totals, 'qNFe') ?? (string) count($nfeAccessKeys),
            ],
            'insurance' => [
                'responsible' => $insuranceResponsibleCode,
                'insurer' => $insurerGroup !== null ? self::optional($insurerGroup, 'xSeg') : null,
                'insurer_cnpj' => $insurerGroup !== null ? self::optional($insurerGroup, 'CNPJ') : null,
                'policy' => $insuranceGroup !== null ? self::optional($insuranceGroup, 'nApol') : null,
                'endorsements' => $insuranceGroup !== null ? array_map(fn (DOMElement $node): string => trim($node->textContent), self::children($insuranceGroup, 'nAver')) : [],
            ],
            'qr_url' => $qr !== null ? trim($qr->textContent) : null,
            'additional_info' => $additionalInfo !== null ? self::optional($additionalInfo, 'infCpl') : null,
            'homologation' => self::text($ide, 'tpAmb') === '2',
        ];
    }

    private static function required(DOMElement $parent, string $name): DOMElement
    {
        return self::first($parent, $name) ?? throw new DomainException("mdfeProc autorizado sem {$name} para gerar o DAMDFE.");
    }

    private static function first(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                return $child;
            }
        }

        return null;
    }

    /** @return list<DOMElement> */
    private static function children(DOMElement $parent, string $name): array
    {
        $matching = [];

        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $name) {
                $matching[] = $child;
            }
        }

        return $matching;
    }

    private static function text(DOMElement $parent, string $name): string
    {
        $node = self::first($parent, $name);

        if ($node === null) {
            throw new DomainException("mdfeProc autorizado sem {$name} para gerar o DAMDFE.");
        }

        return trim($node->textContent);
    }

    private static function optional(DOMElement $parent, string $name): ?string
    {
        $node = self::first($parent, $name);

        return $node !== null ? trim($node->textContent) : null;
    }
}
