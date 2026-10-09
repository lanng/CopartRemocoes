<?php

namespace App\Services\Cte;

use App\Models\CteDocument;
use App\Services\Dfe\DfeDocumentStore;
use DomainException;

class AssertCteNfeOriginalsArchived
{
    public function __construct(private DfeDocumentStore $documentStore) {}

    public function handle(CteDocument $document): void
    {
        $snapshot = (array) $document->snapshot;

        if (($snapshot['line'] ?? null) !== 'tank_alcohol' || (int) ($snapshot['schema_version'] ?? 1) < 3) {
            return;
        }

        $references = $snapshot['nfe_documents'] ?? null;
        $maxFilesPerImport = (int) config('dfe.nfe_xml_max_files_per_import', 10);

        if (! is_array($references)
            || ! array_is_list($references)
            || $references === []
            || count($references) > $maxFilesPerImport) {
            throw new DomainException('O snapshot do CT-e de álcool deve conter uma lista válida de NF-es antes da emissão.');
        }

        $snapshotKeys = [];

        foreach ($references as $reference) {
            $accessKey = (string) ($reference['access_key'] ?? '');

            if (! preg_match('/^\d{44}$/', $accessKey) || isset($snapshotKeys[$accessKey])) {
                throw new DomainException('O snapshot do CT-e contém uma chave de NF-e inválida ou repetida.');
            }

            $snapshotKeys[$accessKey] = true;
        }

        $linkedDocuments = $document->nfeDocuments()->get()->keyBy('access_key');
        $linkedKeys = $linkedDocuments->keys()->all();
        $expectedKeys = array_keys($snapshotKeys);
        sort($linkedKeys);
        sort($expectedKeys);

        if ($linkedKeys !== $expectedKeys) {
            throw new DomainException('Os vínculos arquivados das NF-es não correspondem ao snapshot do CT-e.');
        }

        foreach ($expectedKeys as $accessKey) {
            $nfeDocument = $linkedDocuments->get($accessKey);

            if ($nfeDocument === null
                || ($nfeDocument->extracted_data['access_key'] ?? null) !== $accessKey
                || blank($nfeDocument->xml_path)
                || ! preg_match('/^[a-f0-9]{64}$/', (string) $nfeDocument->sha256)
                || ! $this->documentStore->exists($nfeDocument->xml_path)) {
                throw new DomainException("A NF-e {$accessKey} não possui XML original arquivado válido para emissão.");
            }

            $contents = $this->documentStore->read($nfeDocument->xml_path);

            if (! hash_equals($nfeDocument->sha256, hash('sha256', $contents))) {
                throw new DomainException("O hash do XML original arquivado da NF-e {$accessKey} não corresponde ao vínculo salvo.");
            }
        }
    }
}
