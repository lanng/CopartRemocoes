<?php

namespace App\Services\Dfe;

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
        $this->validate($xml, 'cte_v4.00.xsd');
    }

    public function validateProcCte(string $xml): void
    {
        $this->validate($xml, 'procCTe_v4.00.xsd');
    }

    protected function validate(string $xml, string $schemaFile): void
    {
        $schemaPath = rtrim((string) config('dfe.xsd_path'), '/').'/'.$schemaFile;

        if (! is_readable($schemaPath)) {
            throw new SefazException("XSD local não encontrado: {$schemaPath} (config dfe.xsd_path).");
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

            throw new SefazException("XML do CT-e inválido contra {$schemaFile}: ".($errors ?: 'erro desconhecido'));
        }

        libxml_clear_errors();
    }
}
