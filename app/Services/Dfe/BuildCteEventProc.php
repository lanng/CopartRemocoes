<?php

namespace App\Services\Dfe;

use DomainException;
use DOMDocument;
use Throwable;

class BuildCteEventProc
{
    public function __construct(private readonly XsdValidator $validator) {}

    public function handle(string $signedEventXml, string $responseXml): string
    {
        $event = $this->rootElement($signedEventXml, 'eventoCTe');
        $response = $this->rootElement($responseXml, 'retEventoCTe');
        $proc = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<procEventoCTe versao="4.00" xmlns="http://www.portalfiscal.inf.br/cte">'
            .$event.$response.'</procEventoCTe>';

        $this->validator->validateProcCteEvent($proc);

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
            throw new DomainException("XML sem o elemento <{$expectedRoot}> esperado para montar o procEventoCTe.");
        }

        return (string) $document->saveXML($document->documentElement);
    }
}
