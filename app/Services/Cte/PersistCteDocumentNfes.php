<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\CteDocumentNfe;
use App\Models\User;
use App\Services\Dfe\DfeDocumentStore;
use DomainException;
use Illuminate\Support\Facades\DB;

class PersistCteDocumentNfes
{
    public function __construct(
        private ImportNfeXml $importer,
        private ConsolidateAlcoholNfes $consolidator,
        private DfeDocumentStore $documentStore,
    ) {}

    /**
     * @param  list<array{filename: string, xml: string, operator_complements?: array<string, mixed>|null}>  $files
     * @return array{
     *     documents: list<array<string, mixed>>,
     *     consolidated: array<string, mixed>,
     *     nfe_documents: list<CteDocumentNfe>
     * }
     */
    public function handle(CteDocument $document, array $files, ?User $operator = null): array
    {
        $draftDocument = $this->findDraftDocument($document);
        $maxFilesPerImport = (int) config('dfe.nfe_xml_max_files_per_import', 10);

        if ($files === []) {
            throw new DomainException('Selecione ao menos uma NF-e para importar ao CT-e.');
        }

        if (count($files) > $maxFilesPerImport) {
            throw new DomainException("A seleção excede o limite de {$maxFilesPerImport} NF-es por importação.");
        }

        $preparedFiles = [];
        $importedDocuments = [];

        foreach (array_values($files) as $file) {
            if (! is_string($file['filename'] ?? null) || ! is_string($file['xml'] ?? null)) {
                throw new DomainException('Cada arquivo importado deve conter nome e conteúdo XML.');
            }

            $filename = $this->normalizeFilename($file['filename']);
            $operatorComplements = $file['operator_complements'] ?? null;

            if ($operatorComplements !== null && ! is_array($operatorComplements)) {
                throw new DomainException("Os complementos do arquivo {$filename} devem ser informados como uma lista de campos.");
            }

            if (array_key_exists('operator_complements', $file) && $operatorComplements !== null && $operator === null) {
                throw new DomainException("Informe o usuário responsável pelos complementos da NF-e {$filename}.");
            }

            $extractedData = $this->importer->handle($file['xml'], $filename);
            $importedDocuments[] = $extractedData;
            $preparedFiles[] = [
                'filename' => $filename,
                'xml' => $file['xml'],
                'extracted_data' => $extractedData,
                'operator_complements' => $operatorComplements,
                'operator_user_id' => $operator?->id,
                'has_operator_complements' => array_key_exists('operator_complements', $file),
            ];
        }

        $consolidated = $this->consolidator->handle($importedDocuments);
        $storedOriginals = [];

        foreach ($preparedFiles as $file) {
            $storedOriginals[] = $this->documentStore->storeNfeOriginal(
                $file['extracted_data']['access_key'],
                $file['xml'],
            );
        }

        $nfeDocuments = DB::transaction(function () use ($draftDocument, $preparedFiles, $storedOriginals): array {
            $lockedDocument = CteDocument::query()
                ->lockForUpdate()
                ->find($draftDocument->id);

            if (! $lockedDocument instanceof CteDocument || $lockedDocument->status !== CteDocumentStatusEnum::DRAFT) {
                throw new DomainException('Somente um CT-e persistido em rascunho pode receber NF-es.');
            }

            $existingDocuments = $lockedDocument->nfeDocuments()->get()->keyBy('access_key');
            $accessKeys = [];
            $persistedDocuments = [];

            foreach ($preparedFiles as $position => $file) {
                $extractedData = $file['extracted_data'];
                $accessKey = $extractedData['access_key'];
                $accessKeys[] = $accessKey;
                $existingDocument = $existingDocuments->get($accessKey);
                $operatorComplements = $file['has_operator_complements']
                    ? $file['operator_complements']
                    : $existingDocument?->operator_complements;
                $storedOriginal = $storedOriginals[$position];

                $persistedDocuments[] = $lockedDocument->nfeDocuments()->updateOrCreate(
                    ['access_key' => $accessKey],
                    [
                        'number' => $extractedData['number'],
                        'series' => $extractedData['series'],
                        'source_filename' => $file['filename'],
                        'xml_path' => $storedOriginal['path'],
                        'sha256' => $storedOriginal['sha256'],
                        'extracted_data' => $extractedData,
                        'operator_complements' => $operatorComplements,
                        'operator_complements_user_id' => $file['has_operator_complements']
                            ? ($operatorComplements === null ? null : $file['operator_user_id'])
                            : $existingDocument?->operator_complements_user_id,
                    ],
                );
            }

            $lockedDocument->nfeDocuments()
                ->whereNotIn('access_key', $accessKeys)
                ->delete();

            return $persistedDocuments;
        });

        return [
            'documents' => $importedDocuments,
            'consolidated' => $consolidated,
            'nfe_documents' => $nfeDocuments,
        ];
    }

    private function findDraftDocument(CteDocument $document): CteDocument
    {
        if (! $document->exists || $document->getKey() === null) {
            throw new DomainException('Somente um CT-e persistido em rascunho pode receber NF-es.');
        }

        $draftDocument = CteDocument::query()->find($document->getKey());

        if (! $draftDocument instanceof CteDocument || $draftDocument->status !== CteDocumentStatusEnum::DRAFT) {
            throw new DomainException('Somente um CT-e persistido em rascunho pode receber NF-es.');
        }

        return $draftDocument;
    }

    private function normalizeFilename(string $filename): string
    {
        $filename = basename(str_replace('\\', '/', trim($filename)));

        if ($filename === '' || mb_strlen($filename) > 255) {
            throw new DomainException('O nome do arquivo NF-e está vazio ou excede 255 caracteres.');
        }

        return $filename;
    }
}
