<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Models\MicrosoftGraphConnection;
use App\Services\MicrosoftGraph\MicrosoftGraphClient;
use App\Services\PdfExtractorService;
use DomainException;
use Throwable;

class RemovalRequestPdfPreparer
{
    public function __construct(
        private readonly MicrosoftGraphClient $graphClient,
        private readonly PdfExtractorService $pdfExtractor,
        private readonly RemovalRequestNormalizer $normalizer,
    ) {}

    public function prepare(
        MicrosoftGraphConnection $connection,
        string $messageId,
        string $plate,
    ): PreparedRemovalPdf {
        $maxPdfBytes = (int) config('services.removal_requests.max_pdf_bytes');
        $attachments = $this->graphClient->listMessageAttachments($connection, $messageId);
        $matchingAttachments = array_values(array_filter(
            $attachments,
            fn (array $attachment): bool => $this->isValidAttachment($attachment, $maxPdfBytes),
        ));

        if (count($matchingAttachments) !== 1) {
            throw new DomainException('A mensagem deve conter exatamente uma CartaDeRemoção.pdf válida.');
        }

        $attachment = $matchingAttachments[0];
        $contents = $this->graphClient->downloadMessageAttachment(
            $connection,
            $messageId,
            $attachment['id'],
        );

        if (strlen($contents) === 0 || strlen($contents) > $maxPdfBytes || ! str_starts_with($contents, '%PDF-')) {
            throw new DomainException('O anexo baixado não é um PDF válido dentro do limite configurado.');
        }

        $normalizedPlate = $this->normalizer->plate($plate);

        if ($normalizedPlate === null || ! preg_match('/^[A-Z]{3}(?:\d{4}|\d[A-Z]\d{2})$/', $normalizedPlate)) {
            throw new DomainException('A placa da solicitação é inválida.');
        }

        $fileName = 'CartaDeRemoção '.$normalizedPlate.'.pdf';
        $temporaryPath = tempnam(sys_get_temp_dir(), 'removal_request_pdf_');

        if ($temporaryPath === false) {
            throw new \RuntimeException('Não foi possível criar o arquivo temporário do PDF.');
        }

        try {
            $writtenBytes = file_put_contents($temporaryPath, $contents);

            if ($writtenBytes !== strlen($contents)) {
                throw new \RuntimeException('Não foi possível gravar integralmente o arquivo temporário do PDF.');
            }

            $extractedData = $this->pdfExtractor->extractData($temporaryPath);

            return new PreparedRemovalPdf(
                temporaryPath: $temporaryPath,
                sha256: hash('sha256', $contents),
                fileName: $fileName,
                extractedData: $extractedData,
            );
        } catch (Throwable $exception) {
            @unlink($temporaryPath);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attachment */
    private function isValidAttachment(array $attachment, int $maxPdfBytes): bool
    {
        $type = $attachment['type'] ?? null;

        return is_string($attachment['id'] ?? null)
            && $attachment['id'] !== ''
            && is_string($type)
            && preg_replace('/^#?microsoft\.graph\./', '', $type) === 'fileAttachment'
            && ($attachment['is_inline'] ?? null) === false
            && ($attachment['name'] ?? null) === 'CartaDeRemoção.pdf'
            && ($attachment['content_type'] ?? null) === 'application/pdf'
            && is_int($attachment['size'] ?? null)
            && $attachment['size'] > 0
            && $attachment['size'] <= $maxPdfBytes;
    }
}
