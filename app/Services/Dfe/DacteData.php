<?php

namespace App\Services\Dfe;

use DomainException;
use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Campos do DACTE extraídos do cteProc autorizado (fonte de verdade — o XML
 * de guarda, spec dfe §5). Parser por local-name, tolerante aos dois formatos
 * de doc originário (infOutros × infNFe).
 */
class DacteData
{
    /**
     * @param  array<string, string>  $emit
     * @param  array<string, string>  $rem
     * @param  array<string, string>  $exped
     * @param  array<string, string>  $receb
     * @param  array<string, string>  $dest
     * @param  array<string, string>  $taker
     * @param  list<array{xNome: string, vComp: string}>  $components
     * @param  array{vCarga: ?string, proPred: ?string, xOutCat: ?string, qCarga: ?string, tpMed: ?string, cUnid: ?string, measurements: list<array{cUnid: ?string, tpMed: ?string, qCarga: ?string}>}|null  $cargo
     * @param  array{kind: string, chave: ?string, dPrev: ?string, tpDoc: ?string, descOutros: ?string, nDoc: ?string, dEmi: ?string}|null  $document
     * @param  list<array{kind: string, chave: ?string, dPrev: ?string, tpDoc: ?string, descOutros: ?string, nDoc: ?string, dEmi: ?string}>  $documents
     */
    public function __construct(
        public readonly string $accessKey,
        public readonly string $cteNumber,
        public readonly string $serie,
        public readonly string $dhEmi,
        public readonly string $tpAmb,
        public readonly string $natOp,
        public readonly string $tpCte,
        public readonly string $tpServ,
        public readonly string $cst,
        public readonly string $indSn,
        public readonly string $protocol,
        public readonly ?string $receivedAt,
        public readonly string $cStat,
        public readonly ?string $xMotivo,
        public readonly array $emit,
        public readonly array $rem,
        public readonly array $exped,
        public readonly array $receb,
        public readonly array $dest,
        public readonly array $taker,
        public readonly string $takerCode,
        public readonly string $cfop,
        public readonly string $vTPrest,
        public readonly string $vRec,
        public readonly array $components,
        public readonly ?array $cargo,
        public readonly ?array $document,
        public readonly array $documents,
        public readonly ?string $rntrc,
        public readonly ?string $qrUrl,
        public readonly ?string $originCity,
        public readonly string $originUf,
        public readonly ?string $destinationCity,
        public readonly string $destinationUf,
        public readonly ?string $originIbge,
        public readonly ?string $destinationIbge,
    ) {}

    public static function fromProc(string $cteProcXml): self
    {
        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($cteProcXml);
        } catch (Throwable) {
            $loaded = false;
        }

        $root = $loaded ? $document->documentElement : null;

        // Aceita o cteProc completo (CTe + protocolo) OU o CTe isolado (o
        // protocolo fica null — DACTE do documento sem proc é incomplete).
        if ($root === null || ! in_array($root->localName, ['cteProc', 'CTe'], true)) {
            throw new DomainException('DACTE exige o cteProc completo (CTe + protocolo).');
        }

        $cte = $root->localName === 'CTe' ? $root : self::first($root, 'CTe');
        $infCte = $cte !== null ? self::first($cte, 'infCte') : null;

        if ($cte === null || $infCte === null) {
            throw new DomainException('cteProc sem CTe/infCte.');
        }

        $ide = self::first($infCte, 'ide');
        $emit = self::first($infCte, 'emit');
        $rem = self::first($infCte, 'rem');
        $exped = self::first($infCte, 'exped');
        $receb = self::first($infCte, 'receb');
        $dest = self::first($infCte, 'dest');
        $toma3 = $ide !== null ? self::first($ide, 'toma3') : null;
        $toma4 = $ide !== null ? self::first($ide, 'toma4') : null;
        $takerCode = $toma4 !== null ? '4' : ($toma3 !== null ? (self::optional($toma3, 'toma') ?? '3') : '3');
        $taker = match ($takerCode) {
            '0' => self::party($rem, 'enderReme'),
            '1' => self::party($exped, 'enderExped'),
            '2' => self::party($receb, 'enderReceb'),
            '3' => self::party($dest, 'enderDest'),
            '4' => self::party($toma4, 'enderToma'),
            default => [],
        };
        $vPrest = self::first($infCte, 'vPrest');
        $infCteNorm = self::first($infCte, 'infCTeNorm');
        $infCarga = $infCteNorm !== null ? self::first($infCteNorm, 'infCarga') : null;
        $infQ = $infCarga !== null ? self::first($infCarga, 'infQ') : null;
        $infDoc = $infCteNorm !== null ? self::first($infCteNorm, 'infDoc') : null;
        $infModal = $infCteNorm !== null ? self::first($infCteNorm, 'infModal') : null;
        $rodo = $infModal !== null ? self::first($infModal, 'rodo') : null;
        $imp = self::first($infCte, 'imp');
        $icmsSn = $imp !== null ? self::first(self::first($imp, 'ICMS') ?? $imp, 'ICMSSN') : null;

        $components = [];

        foreach ($vPrest?->getElementsByTagName('*') ?? [] as $element) {
            if ($element->localName === 'Comp') {
                $components[] = [
                    'xNome' => (string) self::textOf($element, 'xNome'),
                    'vComp' => (string) self::textOf($element, 'vComp'),
                ];
            }
        }

        $documents = [];

        foreach ($infDoc?->childNodes ?? [] as $originDocument) {
            if ($originDocument instanceof DOMElement && in_array($originDocument->localName, ['infNFe', 'infOutros'], true)) {
                $documents[] = self::originDocument($originDocument);
            }
        }

        $measurements = [];

        foreach ($infCarga?->childNodes ?? [] as $measurement) {
            if ($measurement instanceof DOMElement && $measurement->localName === 'infQ') {
                $measurements[] = [
                    'cUnid' => self::optional($measurement, 'cUnid'),
                    'tpMed' => self::optional($measurement, 'tpMed'),
                    'qCarga' => self::optional($measurement, 'qCarga'),
                ];
            }
        }

        $originMun = $ide !== null ? self::first($ide, 'xMunIni') : null;
        $destinationMun = $ide !== null ? self::first($ide, 'xMunFim') : null;

        return new self(
            accessKey: self::accessKey($infCte, $root),
            cteNumber: (string) ($ide !== null ? self::textOf($ide, 'nCT') : ''),
            serie: (string) ($ide !== null ? self::textOf($ide, 'serie') : ''),
            dhEmi: (string) ($ide !== null ? self::textOf($ide, 'dhEmi') : ''),
            tpAmb: (string) ($ide !== null ? self::textOf($ide, 'tpAmb') : ''),
            natOp: (string) ($ide !== null ? self::textOf($ide, 'natOp') : ''),
            tpCte: (string) ($ide !== null ? self::textOf($ide, 'tpCTe') : ''),
            tpServ: (string) ($ide !== null ? self::textOf($ide, 'tpServ') : ''),
            cst: $icmsSn !== null ? (string) self::textOf($icmsSn, 'CST') : '',
            indSn: $icmsSn !== null ? (string) self::textOf($icmsSn, 'indSN') : '1',
            protocol: (string) self::protText($root, 'nProt'),
            receivedAt: self::protText($root, 'dhRecbto'),
            cStat: (string) self::protText($root, 'cStat'),
            xMotivo: self::protText($root, 'xMotivo'),
            emit: self::party($emit, 'enderEmit'),
            rem: self::party($rem, 'enderReme'),
            exped: self::party($exped, 'enderExped'),
            receb: self::party($receb, 'enderReceb'),
            dest: self::party($dest, 'enderDest'),
            taker: $taker,
            takerCode: $takerCode,
            cfop: (string) ($ide !== null ? self::textOf($ide, 'CFOP') : ''),
            vTPrest: (string) ($vPrest !== null ? self::textOf($vPrest, 'vTPrest') : ''),
            vRec: (string) ($vPrest !== null ? self::textOf($vPrest, 'vRec') : ''),
            components: $components,
            cargo: $infCarga !== null ? [
                'vCarga' => self::optional($infCarga, 'vCarga'),
                'proPred' => self::optional($infCarga, 'proPred'),
                'xOutCat' => self::optional($infCarga, 'xOutCat'),
                'qCarga' => $infQ !== null ? self::optional($infQ, 'qCarga') : null,
                'tpMed' => $infQ !== null ? self::optional($infQ, 'tpMed') : null,
                'cUnid' => $infQ !== null ? self::optional($infQ, 'cUnid') : null,
                'measurements' => $measurements,
            ] : null,
            document: $documents[0] ?? null,
            documents: $documents,
            rntrc: $rodo !== null ? self::optional($rodo, 'RNTRC') : null,
            qrUrl: self::qrUrl($cte),
            originCity: $originMun !== null ? trim((string) $originMun->textContent) : null,
            originUf: $ide !== null ? (string) self::textOf($ide, 'UFIni') : '',
            destinationCity: $destinationMun !== null ? trim((string) $destinationMun->textContent) : null,
            destinationUf: $ide !== null ? (string) self::textOf($ide, 'UFFim') : '',
            originIbge: $ide !== null ? self::optional($ide, 'cMunIni') : null,
            destinationIbge: $ide !== null ? self::optional($ide, 'cMunFim') : null,
        );
    }

    public function isHomolog(): bool
    {
        return $this->tpAmb === '2';
    }

    /**
     * @return list<string>
     */
    public function groupedAccessKey(): array
    {
        return str_split($this->accessKey, 4);
    }

    protected static function accessKey(DOMElement $infCte, DOMElement $root): string
    {
        $id = (string) $infCte->getAttribute('Id');

        if (str_starts_with($id, 'CTe')) {
            return substr($id, 3);
        }

        $infProt = self::firstProt($root);

        if ($infProt !== null) {
            $chCTe = self::childByName($infProt, 'chCTe');

            if ($chCTe !== null) {
                return trim($chCTe->textContent);
            }
        }

        throw new DomainException('cteProc sem chave de acesso.');
    }

    protected static function qrUrl(DOMElement $infCte): ?string
    {
        $supl = self::first($infCte, 'infCTeSupl');

        if ($supl === null) {
            return null;
        }

        $qr = self::first($supl, 'qrCodCTe');

        if ($qr === null) {
            return null;
        }

        return trim($qr->textContent);
    }

    protected static function protText(DOMElement $root, string $localName): ?string
    {
        $infProt = self::firstProt($root);

        return $infProt !== null ? self::optional($infProt, $localName) : null;
    }

    protected static function firstProt(DOMElement $root): ?DOMElement
    {
        $protCte = self::first($root, 'protCTe');

        return $protCte !== null ? self::first($protCte, 'infProt') : null;
    }

    /**
     * Bloco de parte fiscal para o DACTE (nome, documento, IE, endereço).
     *
     * @return array<string, string>
     */
    protected static function party(?DOMElement $parent, string $addressTag): array
    {
        if ($parent === null) {
            return [];
        }

        $address = self::first($parent, $addressTag);

        return [
            'xNome' => (string) self::optional($parent, 'xNome'),
            'xFant' => self::optional($parent, 'xFant') ?? '',
            'CNPJ' => (string) self::optional($parent, 'CNPJ'),
            'CPF' => (string) self::optional($parent, 'CPF'),
            'IE' => (string) self::optional($parent, 'IE'),
            'xLgr' => $address !== null ? (string) self::textOf($address, 'xLgr') : '',
            'nro' => $address !== null ? (string) self::textOf($address, 'nro') : '',
            'xCpl' => $address !== null ? (string) self::optional($address, 'xCpl') : '',
            'xBairro' => $address !== null ? (string) self::textOf($address, 'xBairro') : '',
            'cMun' => $address !== null ? (string) self::textOf($address, 'cMun') : '',
            'xMun' => $address !== null ? (string) self::textOf($address, 'xMun') : '',
            'UF' => $address !== null ? (string) self::textOf($address, 'UF') : '',
            'CEP' => $address !== null ? (string) self::textOf($address, 'CEP') : '',
        ];
    }

    /**
     * @return array{kind: string, chave: ?string, dPrev: ?string, tpDoc: ?string, descOutros: ?string, nDoc: ?string, dEmi: ?string}
     */
    protected static function originDocument(DOMElement $document): array
    {
        if ($document->localName === 'infOutros') {
            return [
                'kind' => 'outros',
                'tpDoc' => self::optional($document, 'tpDoc'),
                'descOutros' => self::optional($document, 'descOutros'),
                'nDoc' => self::optional($document, 'nDoc'),
                'dEmi' => self::optional($document, 'dEmi'),
                'chave' => null,
                'dPrev' => null,
            ];
        }

        return [
            'kind' => 'nfe',
            'chave' => self::optional($document, 'chave'),
            'dPrev' => self::optional($document, 'dPrev'),
            'tpDoc' => null,
            'descOutros' => null,
            'nDoc' => null,
            'dEmi' => null,
        ];
    }

    protected static function first(?DOMElement $scope, string $localName): ?DOMElement
    {
        if ($scope === null) {
            return null;
        }

        if ($scope->localName === $localName) {
            return $scope;
        }

        foreach ($scope->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }

    protected static function childByName(DOMElement $parent, string $localName): ?DOMElement
    {
        return self::first($parent, $localName);
    }

    protected static function textOf(DOMElement $parent, string $localName): ?string
    {
        $child = self::first($parent, $localName);

        return $child !== null ? trim($child->textContent) : null;
    }

    protected static function optional(DOMElement $parent, string $localName): ?string
    {
        $text = self::textOf($parent, $localName);

        return $text === '' ? null : $text;
    }
}
