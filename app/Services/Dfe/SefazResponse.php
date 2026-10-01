<?php

namespace App\Services\Dfe;

use DOMDocument;
use DOMElement;
use Throwable;

/**
 * Resultado normalizado dos serviços SEFAZ (spec §2.3): cStat/xMotivo da raiz
 * do ret* (retConsStatServCte, retCTe…) e os detalhes do protocolo (infProt)
 * quando presentes — nProt/digVal/chCTe/dhRecbto da recepção síncrona.
 */
class SefazResponse
{
    public function __construct(
        public readonly ?string $cStat,
        public readonly ?string $xMotivo,
        public readonly ?string $protocol = null,
        public readonly ?string $digestValue = null,
        public readonly ?string $accessKey = null,
        public readonly ?string $receivedAt = null,
        public readonly int $httpStatus = 200,
        public readonly ?string $rawXml = null,
        public readonly ?string $faultCode = null,
        public readonly ?string $faultString = null,
    ) {}

    public function isAuthorized(): bool
    {
        return $this->cStat === '100';
    }

    public function isServiceOnline(): bool
    {
        return $this->cStat === '107';
    }

    public function isFault(): bool
    {
        return $this->faultCode !== null;
    }

    /**
     * Parse tolerante do envelope SOAP 1.2: o ret* pode chegar como elemento
     * real dentro do Body ou como XML escapado dentro do elemento de resultado
     * do WSDL — e o payload pode nem estar em SOAP (resposta crua).
     */
    public static function fromSoapXml(string $soapXml, int $httpStatus = 200): self
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($soapXml);
        } catch (Throwable) {
            $loaded = false;
        }

        if (! $loaded) {
            throw new SefazException('Resposta ilegível da SEFAZ: '.self::libxmlErrors());
        }

        $fault = self::firstByLocalName($document->documentElement, 'Fault');

        if ($fault !== null) {
            $faultCode = self::childText($fault, 'faultcode');

            if ($faultCode === null) {
                $code = self::firstByLocalName($fault, 'Code');
                $faultCode = $code !== null ? self::childText($code, 'Value') : null;
            }

            $faultString = self::childText($fault, 'faultstring') ?? self::reasonText($fault);

            return new self(
                cStat: null,
                xMotivo: $faultString,
                httpStatus: $httpStatus,
                rawXml: $soapXml,
                faultCode: $faultCode,
                faultString: $faultString,
            );
        }

        $payload = self::payloadElement($document);

        if ($payload === null) {
            throw new SefazException('Resposta da SEFAZ sem o documento ret* esperado.');
        }

        $infProt = self::firstByLocalName($payload, 'infProt');

        return new self(
            cStat: $infProt !== null ? self::childText($infProt, 'cStat') : self::childText($payload, 'cStat'),
            xMotivo: $infProt !== null ? self::childText($infProt, 'xMotivo') : self::childText($payload, 'xMotivo'),
            protocol: $infProt !== null ? self::childText($infProt, 'nProt') : null,
            digestValue: $infProt !== null ? self::childText($infProt, 'digVal') : null,
            accessKey: $infProt !== null ? self::childText($infProt, 'chCTe') : null,
            receivedAt: $infProt !== null ? self::childText($infProt, 'dhRecbto') : self::childText($payload, 'dhRecbto'),
            httpStatus: $httpStatus,
            // Evidência fiscal persistida: o documento ret* extraído (com
            // declaração XML), não o envelope SOAP que o carregou.
            rawXml: self::payloadXml($payload),
        );
    }

    /**
     * Serializa o documento ret* (a partir de qualquer documento onde ele
     * tenha sido encontrado, inclusive o parse do texto escapado) com a
     * declaração XML à frente.
     */
    protected static function payloadXml(DOMElement $payload): string
    {
        $xml = (string) $payload->ownerDocument->saveXML($payload);

        if (! str_starts_with($xml, '<?xml')) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'.$xml;
        }

        return $xml;
    }

    /**
     * Localiza o documento ret* (retConsStatServCte, retCTe…), resolvido tanto
     * como elemento no SOAP quanto como texto XML escapado no elemento de
     * resultado do WSDL (padrão legado dos .asmx).
     */
    protected static function payloadElement(DOMDocument $document): ?DOMElement
    {
        $root = $document->documentElement;

        if ($root !== null && str_starts_with((string) $root->localName, 'ret')) {
            return $root;
        }

        $direct = self::firstByLocalNameStarting($root, 'ret');

        if ($direct !== null) {
            return $direct;
        }

        // XML escapado: primeiro elemento com conteúdo de texto que comece com '<'.
        $elements = $root?->getElementsByTagName('*') ?? [];

        foreach ($elements as $element) {
            $text = trim($element->textContent);

            if (str_starts_with($text, '<')) {
                $inner = new DOMDocument;

                if ($inner->loadXML($text)) {
                    $innerRoot = $inner->documentElement;

                    if ($innerRoot !== null && str_starts_with((string) $innerRoot->localName, 'ret')) {
                        return $innerRoot;
                    }

                    return self::firstByLocalNameStarting($innerRoot, 'ret');
                }
            }
        }

        return null;
    }

    protected static function firstByLocalNameStarting(?DOMElement $scope, string $prefix): ?DOMElement
    {
        $elements = $scope?->getElementsByTagName('*') ?? [];

        foreach ($elements as $element) {
            if (str_starts_with((string) $element->localName, $prefix)) {
                return $element;
            }
        }

        return null;
    }

    protected static function firstByLocalName(?DOMElement $scope, string $localName): ?DOMElement
    {
        $elements = $scope?->getElementsByTagName('*') ?? [];

        foreach ($elements as $element) {
            if ($element->localName === $localName) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Texto do filho direto com o local name dado (busca por profundidade
     * limitada evita capturar um campo homônimo de um subgrupo).
     */
    protected static function childText(DOMElement $parent, string $localName): ?string
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                $text = trim($child->textContent);

                return $text === '' ? null : $text;
            }
        }

        return null;
    }

    protected static function reasonText(DOMElement $fault): ?string
    {
        $reason = self::firstByLocalName($fault, 'Reason');
        $text = $reason !== null ? self::childText($reason, 'Text') : null;

        return $text !== null ? trim($text) : null;
    }

    protected static function libxmlErrors(): string
    {
        return implode('; ', array_map(
            fn (\LibXMLError $error): string => trim($error->message),
            libxml_get_errors(),
        )) ?: 'erro desconhecido';
    }
}
