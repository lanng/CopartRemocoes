<?php

namespace App\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\MdfeDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteDraftMdfeDocument
{
    public function handle(MdfeDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $lockedDocument = MdfeDocument::query()
                ->lockForUpdate()
                ->findOrFail($document->id);

            if (! $this->hasNoFiscalOrTransmissionEvidence($lockedDocument)) {
                throw ValidationException::withMessages([
                    'document' => 'Somente rascunhos sem número, chave ou evidência de comunicação fiscal podem ser excluídos.',
                ]);
            }

            if ($lockedDocument->events()->exists()) {
                throw ValidationException::withMessages([
                    'document' => 'O MDF-e possui eventos fiscais e não pode ser excluído.',
                ]);
            }

            $lockedDocument->reservations()->delete();
            $lockedDocument->cteDocuments()->detach();
            $lockedDocument->delete();
        });
    }

    public function canDelete(MdfeDocument $document): bool
    {
        $document = MdfeDocument::query()->find($document->id);

        return $document !== null
            && $this->hasNoFiscalOrTransmissionEvidence($document)
            && ! $document->events()->exists();
    }

    private function hasNoFiscalOrTransmissionEvidence(MdfeDocument $document): bool
    {
        if ($document->status !== CteDocumentStatusEnum::DRAFT
            || ! in_array($document->fiscal_status, [null, FiscalDocumentStatusEnum::NOT_AUTHORIZED], true)) {
            return false;
        }

        foreach ([
            'authorization_started_at', 'issued_at', 'authorized_at', 'mdfe_number', 'access_key',
            'protocol', 'fiscal_status_code', 'fiscal_status_message', 'payload_xml', 'response_xml',
            'digest_value', 'xml_path', 'pdf_path', 'sha256', 'error_stage', 'error_code',
            'error_message', 'result_payload_hash',
        ] as $attribute) {
            if (filled($document->{$attribute})) {
                return false;
            }
        }

        return true;
    }
}
