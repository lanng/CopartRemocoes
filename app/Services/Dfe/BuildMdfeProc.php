<?php

namespace App\Services\Dfe;

use DomainException;
use DOMDocument;
use DOMElement;
use Throwable;

class BuildMdfeProc
{
    public function __construct(private readonly XsdValidator $validator) {}

    public function handle(string $signedMdfeXml, string $responseRawXml): string
    {
        $mdfe = $this->extractRoot($signedMdfeXml, 'MDFe');
        $protocol = $this->extractProtocol($responseRawXml);
        $proc = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<mdfeProc versao="'.e((string) config('dfe.mdfe.version')).'" xmlns="http://www.portalfiscal.inf.br/mdfe">'
            .$mdfe.$protocol.'</mdfeProc>';

        $this->validator->validateProcMdfe($proc);

        return $proc;
    }

    protected function extractRoot(string $xml, string $rootName): string
    {
        $start = strpos($xml, '<'.$rootName.' ');
        $end = $start === false ? false : strrpos($xml, '</'.$rootName.'>');

        if ($start === false || $end === false) {
            throw new DomainException("XML assinado sem o elemento <{$rootName}> raiz.");
        }

        return substr($xml, $start, $end + strlen('</'.$rootName.'>') - $start);
    }

    protected function extractProtocol(string $xml): string
    {
        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($xml);
        } catch (Throwable) {
            $loaded = false;
        }

        if (! $loaded) {
            throw new DomainException('Resposta da SEFAZ ilegível para extrair o protMDFe.');
        }

        $protocol = $this->find($document->documentElement, 'protMDFe');

        if ($protocol === null) {
            throw new DomainException('Resposta da SEFAZ sem protMDFe para montar o mdfeProc.');
        }

        return (string) $document->saveXML($protocol);
    }

    protected function find(?DOMElement $scope, string $name): ?DOMElement
    {
        if ($scope === null) {
            return null;
        }

        if ($scope->localName === $name) {
            return $scope;
        }

        foreach ($scope->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $found = $this->find($child, $name);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
