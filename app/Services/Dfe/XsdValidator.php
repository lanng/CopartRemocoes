<?php

namespace App\Services\Dfe;

use DomainException;
use DOMDocument;
use Throwable;

/**
 * Validação XSD local pré-transmissão (spec §2.5): cte_v4.00.xsd valida o CTe
 * isolado; procCTe_v4.00.xsd, o proc completo com protocolo.
 */
class XsdValidator
{
    public function validateCte(string $xml): void
    {
        $this->validate($xml, 'cte_v4.00.xsd', (string) config('dfe.xsd_path'));
    }

    public function validateProcCte(string $xml): void
    {
        $this->validate($xml, 'procCTe_v4.00.xsd', (string) config('dfe.xsd_path'));
    }

    public function validateCteEvent(string $xml): void
    {
        $this->validate($xml, 'eventoCTe_v4.00.xsd', (string) config('dfe.xsd_path'));
    }

    public function validateCteCancellationDetail(string $xml): void
    {
        $this->validate($xml, 'evCancCTe_v4.00.xsd', (string) config('dfe.xsd_path'));
    }

    public function validateProcCteEvent(string $xml): void
    {
        $this->validate($xml, 'procEventoCTe_v4.00.xsd', (string) config('dfe.xsd_path'));
    }

    public function validateMdfe(string $xml): void
    {
        $xsdPath = (string) config('dfe.mdfe.xsd_path');

        $this->validate($xml, 'mdfe_v3.00.xsd', $xsdPath);
        $this->validateRoadModal($xml, $xsdPath);
    }

    public function validateProcMdfe(string $xml): void
    {
        $this->validate($xml, 'procMDFe_v3.00.xsd', (string) config('dfe.mdfe.xsd_path'));
    }

    public function validateMdfeEvent(string $xml): void
    {
        $this->validate($xml, 'eventoMDFe_v3.00.xsd', (string) config('dfe.mdfe.xsd_path'));
    }

    public function validateMdfeClosureDetail(string $xml): void
    {
        $this->validate($xml, 'evEncMDFe_v3.00.xsd', (string) config('dfe.mdfe.xsd_path'));
    }

    public function validateMdfeCancellationDetail(string $xml): void
    {
        $this->validate($xml, 'evCancMDFe_v3.00.xsd', (string) config('dfe.mdfe.xsd_path'));
    }

    public function validateProcMdfeEvent(string $xml): void
    {
        $this->validate($xml, 'procEventoMDFe_v3.00.xsd', (string) config('dfe.mdfe.xsd_path'));
    }

    protected function validate(string $xml, string $schemaFile, string $xsdPath): void
    {
        $schemaPath = rtrim($xsdPath, '/').'/'.$schemaFile;

        if (! is_readable($schemaPath)) {
            throw new DomainException("XSD local não encontrado: {$schemaPath} (config dfe.xsd_path).");
        }

        $document = new DOMDocument;

        try {
            $loaded = $document->loadXML($xml);
        } catch (Throwable) {
            $loaded = false;
        }

        libxml_use_internal_errors(true);

        if (! $loaded || ! $document->schemaValidate($schemaPath)) {
            $errors = implode('; ', array_map(
                fn (\LibXMLError $error): string => sprintf('%s (linha %d)', trim($error->message), $error->line),
                libxml_get_errors(),
            ));

            libxml_clear_errors();

            throw new DomainException("XML inválido contra {$schemaFile}: ".($errors ?: 'erro desconhecido'));
        }

        libxml_clear_errors();
    }

    protected function validateRoadModal(string $xml, string $xsdPath): void
    {
        $document = new DOMDocument;

        if (! $document->loadXML($xml)) {
            throw new DomainException('XML inválido antes da validação específica do modal rodoviário.');
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('mdfe', 'http://www.portalfiscal.inf.br/mdfe');
        $rodo = $xpath->query('/mdfe:MDFe/mdfe:infMDFe/mdfe:infModal/mdfe:rodo')?->item(0);

        if ($rodo === null) {
            throw new DomainException('XML MDF-e rodoviário não contém infModal/rodo para validar contra o XSD específico.');
        }

        $modalDocument = new DOMDocument;
        $modalDocument->appendChild($modalDocument->importNode($rodo, true));
        $modalXml = $modalDocument->saveXML();

        if (! is_string($modalXml)) {
            throw new DomainException('Não foi possível preparar o XML do modal rodoviário para validação específica.');
        }

        $this->validate($modalXml, 'mdfeModalRodoviario_v3.00.xsd', $xsdPath);
    }
}
