<?php

namespace App\Services\Dfe;

use DomainException;
use DOMDocument;
use Throwable;

class BuildMdfeEventProc
{
    public function __construct(private readonly XsdValidator $validator) {}

    public function handle(string $signedEventXml, string $responseXml): string
    {
        $event = $this->rootElement($signedEventXml, 'eventoMDFe');
        $response = $this->rootElement($responseXml, 'retEventoMDFe');
        $proc = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<procEventoMDFe versao="3.00" xmlns="http://www.portalfiscal.inf.br/mdfe">'
            .$event.$response.'</procEventoMDFe>';

        $this->validator->validateProcMdfeEvent($proc);

        return $proc;
    }

    private function rootElement(string $xml, string $expectedRoot): string
    {
        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($xml);
        } catch (Throwable) {
            $loaded = false;
        }

        if (! $loaded || $document->documentElement?->localName !== $expectedRoot) {
            throw new DomainException("XML sem o elemento <{$expectedRoot}> esperado para montar o procEventoMDFe.");
        }

        return (string) $document->saveXML($document->documentElement);
    }
}
