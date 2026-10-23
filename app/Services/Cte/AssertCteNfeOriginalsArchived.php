<?php

namespace App\Services\Cte;

use App\Models\CteDocument;
use App\Services\Dfe\DfeDocumentStore;
use DomainException;

class AssertCteNfeOriginalsArchived
{
    public function __construct(
        private DfeDocumentStore $documentStore,
        private ExtractNfeReference $nfeReference,
    ) {}

    public function handle(CteDocument $document): void
    {
        $snapshot = (array) $document->snapshot;

        if (($snapshot['line'] ?? null) === 'general_cargo') {
            if ((int) ($snapshot['schema_version'] ?? 0) >= 5
                && ($snapshot['origin_document_mode'] ?? null) === 'other') {
                $this->assertCartaOriginDocument($document, $snapshot);

                return;
            }

            $this->assertGeneralCargoReferences($document, $snapshot);

            return;
        }

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

    /** @param array<string, mixed> $snapshot */
    private function assertGeneralCargoReferences(CteDocument $document, array $snapshot): void
    {
        $references = $snapshot['nfe_documents'] ?? null;
        $maxFilesPerImport = (int) config('dfe.nfe_xml_max_files_per_import', 10);

        if (! is_array($references)
            || ! array_is_list($references)
            || $references === []
            || count($references) > $maxFilesPerImport) {
            throw new DomainException('O CT-e avulso deve conter uma lista válida de referências de NF-e.');
        }

        $snapshotKeys = [];

        foreach ($references as $reference) {
            try {
                $accessKey = $this->nfeReference->fromKey((string) ($reference['access_key'] ?? ''));
            } catch (DomainException $exception) {
                throw new DomainException('O snapshot do CT-e avulso contém chave de NF-e inválida.', previous: $exception);
            }

            if (isset($snapshotKeys[$accessKey])) {
                throw new DomainException('O snapshot do CT-e avulso contém referência de NF-e duplicada.');
            }

            $snapshotKeys[$accessKey] = true;
        }

        $linkedDocuments = $document->nfeDocuments()->get()->keyBy('access_key');
        $linkedKeys = $linkedDocuments->keys()->all();
        $expectedKeys = array_keys($snapshotKeys);
        sort($linkedKeys);
        sort($expectedKeys);

        if ($linkedKeys !== $expectedKeys) {
            throw new DomainException('Os vínculos de NF-e do CT-e avulso não correspondem ao snapshot salvo.');
        }

        foreach ($expectedKeys as $accessKey) {
            $nfeDocument = $linkedDocuments->get($accessKey);

            if ($nfeDocument === null) {
                throw new DomainException("A referência de NF-e {$accessKey} não está vinculada ao CT-e avulso.");
            }

            $path = trim((string) $nfeDocument->xml_path);
            $sha256 = trim((string) $nfeDocument->sha256);
            $isManualKey = $path === ''
                && $sha256 === ''
                && $nfeDocument->extracted_data === null
                && blank($nfeDocument->source_filename);

            if ($isManualKey) {
                continue;
            }

            if ($path === ''
                || ! preg_match('/^[a-f0-9]{64}$/', $sha256)
                || ($nfeDocument->extracted_data['access_key'] ?? null) !== $accessKey
                || ! $this->documentStore->exists($path)) {
                throw new DomainException("A NF-e {$accessKey} não possui arquivo original arquivado válido.");
            }

            if (! hash_equals($sha256, hash('sha256', $this->documentStore->read($path)))) {
                throw new DomainException("O hash do XML original arquivado da NF-e {$accessKey} não corresponde ao vínculo salvo.");
            }
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function assertCartaOriginDocument(CteDocument $document, array $snapshot): void
    {
        if ((array) ($snapshot['nfe_documents'] ?? []) !== [] || $document->nfeDocuments()->exists()) {
            throw new DomainException('Um CT-e originado por Carta de remoção não pode manter vínculos de NF-e.');
        }

        $attachment = data_get($snapshot, 'other_document.attachment');

        if ($attachment === null) {
            return;
        }

        $path = (string) data_get($attachment, 'path', '');
        $sha256 = (string) data_get($attachment, 'sha256', '');
        $expectedPrefix = 'cte-drafts/'.$document->public_id.'/carta-remocao/';

        if (! str_starts_with($path, $expectedPrefix)
            || ! preg_match('/^[a-f0-9]{64}$/', $sha256)
            || ! $this->documentStore->exists($path)) {
            throw new DomainException('O anexo da Carta de remoção não está arquivado no armazenamento fiscal esperado.');
        }

        if (! hash_equals($sha256, hash('sha256', $this->documentStore->read($path)))) {
            throw new DomainException('O hash do anexo da Carta de remoção não corresponde ao arquivo arquivado.');
        }
    }
}
