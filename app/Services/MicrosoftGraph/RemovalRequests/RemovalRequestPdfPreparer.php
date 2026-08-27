<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Models\MicrosoftGraphConnection;
use App\Services\MicrosoftGraph\MicrosoftGraphClient;
use App\Services\PdfExtractorService;
use DomainException;
use RuntimeException;
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
        $normalizedPlate = $this->normalizer->plate($plate);

        if ($normalizedPlate === null || ! preg_match('/^[A-Z]{3}(?:\d{4}|\d[A-Z]\d{2})$/', $normalizedPlate)) {
            throw new DomainException('A placa da solicitação é inválida.');
        }

        $fileName = 'CartaDeRemoção '.$normalizedPlate.'.pdf';
        $temporaryPath = tempnam(sys_get_temp_dir(), 'removal_request_pdf_');

        if ($temporaryPath === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário do PDF.');
        }

        try {
            $downloadedBytes = $this->graphClient->downloadMessageAttachmentToPath(
                $connection,
                $messageId,
                $attachment['id'],
                $temporaryPath,
                $maxPdfBytes,
            );
            clearstatcache(true, $temporaryPath);
            $actualSize = filesize($temporaryPath);

            if ($actualSize === false || $actualSize !== $downloadedBytes) {
                throw new RuntimeException('O tamanho do arquivo temporário do PDF não pôde ser confirmado.');
            }

            if ($actualSize === 0 || $actualSize > $maxPdfBytes) {
                throw new DomainException('O anexo baixado não é um PDF válido dentro do limite configurado.');
            }

            $signatureStream = fopen($temporaryPath, 'rb');

            if ($signatureStream === false) {
                throw new RuntimeException('Não foi possível ler a assinatura do arquivo PDF.');
            }

            try {
                $signature = fread($signatureStream, 5);
            } finally {
                fclose($signatureStream);
            }

            if ($signature !== '%PDF-') {
                throw new DomainException('O anexo baixado não é um PDF válido dentro do limite configurado.');
            }

            $sha256 = hash_file('sha256', $temporaryPath);

            if ($sha256 === false) {
                throw new RuntimeException('Não foi possível calcular o hash do arquivo PDF.');
            }

            $extractedData = $this->pdfExtractor->extractData($temporaryPath);

            return new PreparedRemovalPdf(
                temporaryPath: $temporaryPath,
                sha256: $sha256,
                fileName: $fileName,
                extractedData: $extractedData,
            );
        } catch (Throwable $exception) {
            @unlink($temporaryPath);

            throw $exception;
        }
    }

    public function prepareConsignorLetter(
        MicrosoftGraphConnection $connection,
        string $messageId,
        string $plate,
    ): ?PreparedConsignorLetter {
        $maxPdfBytes = (int) config('services.removal_requests.max_pdf_bytes');
        $attachments = array_values(array_filter(
            $this->graphClient->listMessageAttachments($connection, $messageId),
            fn (array $attachment): bool => ($attachment['name'] ?? null) === 'CartaDoComitente.pdf',
        ));

        if ($attachments === []) {
            return null;
        }

        if (count($attachments) !== 1 || ! $this->isValidNamedAttachment($attachments[0], $maxPdfBytes, 'CartaDoComitente.pdf')) {
            throw new DomainException('A CartaDoComitente.pdf não é um anexo PDF válido.');
        }

        $normalizedPlate = $this->normalizer->plate($plate);

        if ($normalizedPlate === null || ! preg_match('/^[A-Z]{3}(?:\d{4}|\d[A-Z]\d{2})$/', $normalizedPlate)) {
            throw new DomainException('A placa da solicitação é inválida.');
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'consignor_letter_pdf_');

        if ($temporaryPath === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário da Carta do Comitente.');
        }

        try {
            $downloadedBytes = $this->graphClient->downloadMessageAttachmentToPath(
                $connection,
                $messageId,
                $attachments[0]['id'],
                $temporaryPath,
                $maxPdfBytes,
            );
            clearstatcache(true, $temporaryPath);
            $actualSize = filesize($temporaryPath);

            if ($actualSize === false || $actualSize !== $downloadedBytes || $actualSize === 0 || $actualSize > $maxPdfBytes) {
                throw new DomainException('A CartaDoComitente.pdf baixada não é válida dentro do limite configurado.');
            }

            $signatureStream = fopen($temporaryPath, 'rb');

            if ($signatureStream === false) {
                throw new RuntimeException('Não foi possível ler a assinatura da Carta do Comitente.');
            }

            try {
                $signature = fread($signatureStream, 5);
            } finally {
                fclose($signatureStream);
            }

            if ($signature !== '%PDF-') {
                throw new DomainException('A CartaDoComitente.pdf baixada não é um PDF válido.');
            }

            $sha256 = hash_file('sha256', $temporaryPath);

            if ($sha256 === false) {
                throw new RuntimeException('Não foi possível calcular o hash da Carta do Comitente.');
            }

            return new PreparedConsignorLetter(
                temporaryPath: $temporaryPath,
                sha256: $sha256,
                fileName: 'CartaDoComitente '.$normalizedPlate.'.pdf',
            );
        } catch (Throwable $exception) {
            @unlink($temporaryPath);

            throw $exception;
        }
    }

    /** @param array<string, mixed> $attachment */
    private function isValidAttachment(array $attachment, int $maxPdfBytes): bool
    {
        return $this->isValidNamedAttachment($attachment, $maxPdfBytes, 'CartaDeRemoção.pdf');
    }

    /** @param array<string, mixed> $attachment */
    private function isValidNamedAttachment(array $attachment, int $maxPdfBytes, string $name): bool
    {
        $type = $attachment['type'] ?? null;

        return is_string($attachment['id'] ?? null)
            && $attachment['id'] !== ''
            && is_string($type)
            && preg_replace('/^#?microsoft\.graph\./', '', $type) === 'fileAttachment'
            && ($attachment['is_inline'] ?? null) === false
            && ($attachment['name'] ?? null) === $name
            && in_array($attachment['content_type'] ?? null, ['application/pdf', 'application/octet-stream'], true)
            && is_int($attachment['size'] ?? null)
            && $attachment['size'] > 0
            && $attachment['size'] <= $maxPdfBytes;
    }
}
