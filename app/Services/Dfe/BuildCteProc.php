<?php

namespace App\Services\Dfe;

use DomainException;
use DOMDocument;
use DOMNode;
use Throwable;

/**
 * Monta o cteProc (CTe assinado + protCTe do protocolo) — o arquivo fiscal
 * de guarda (spec dfe §3), validado contra procCTe_v4.00.xsd na montagem.
 */
class BuildCteProc
{
    public function __construct(protected XsdValidator $validator) {}

    public function handle(string $signedCteXml, string $responseRawXml): string
    {
        $cte = $this->extractCte($signedCteXml);
        $protCte = $this->extractProtCte($responseRawXml);

        $proc = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<cteProc versao="'.(string) config('dfe.cte_version', '4.00').'" xmlns="http://www.portalfiscal.inf.br/cte">'
            .$cte
            .$protCte
            .'</cteProc>';

        // Autochecagem: o proc de guarda tem de validar contra o XSD oficial.
        $this->validator->validateProcCte($proc);

        return $proc;
    }

    /**
     * Recorte cru do <CTe> assinado — os bytes que transitaram na SEFAZ.
     */
    protected function extractCte(string $signedCteXml): string
    {
        $start = strpos($signedCteXml, '<CTe ');
        $end = $start === false ? false : strrpos($signedCteXml, '</CTe>');

        if ($start === false || $end === false) {
            throw new DomainException('XML assinado sem o elemento <CTe> raiz.');
        }

        return substr($signedCteXml, $start, $end + strlen('</CTe>') - $start);
    }

    /**
     * protCTe serializado da resposta SOAP — tolerante aos dois formatos
     * observados nos serviços SEFAZ: elemento direto no Body ou documento
     * XML escapado dentro do elemento de resultado do WSDL. O libxml re-emite
     * os xmlns herdados na serialização do nó.
     */
    protected function extractProtCte(string $responseRawXml): string
    {
        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($responseRawXml);
        } catch (Throwable) {
            $loaded = false;
        }

        if (! $loaded) {
            throw new DomainException('Resposta da SEFAZ ilegível para extrair o protCTe.');
        }

        $direct = $this->findByLocalName($document->documentElement, 'protCTe');

        if ($direct !== null) {
            return (string) $document->saveXML($direct);
        }

        // XML escapado: primeiro elemento com conteúdo de texto que comece com '<'.
        foreach ($document->getElementsByTagName('*') as $element) {
            $text = trim($element->textContent);

            if (! str_starts_with($text, '<')) {
                continue;
            }

            $inner = new DOMDocument;

            if (! $inner->loadXML($text)) {
                continue;
            }

            $protCte = $this->findByLocalName($inner->documentElement, 'protCTe');

            if ($protCte !== null) {
                return (string) $inner->saveXML($protCte);
            }
        }

        throw new DomainException('Resposta da SEFAZ sem protCTe para montar o cteProc.');
    }

    protected function findByLocalName(?DOMNode $scope, string $localName): ?DOMNode
    {
        if ($scope === null) {
            return null;
        }

        if ($scope->localName === $localName) {
            return $scope;
        }

        foreach ($scope->getElementsByTagName('*') as $element) {
            if ($element->localName === $localName) {
                return $element;
            }
        }

        return null;
    }
}
