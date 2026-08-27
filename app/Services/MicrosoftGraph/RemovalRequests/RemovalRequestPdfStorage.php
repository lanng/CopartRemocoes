<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use DomainException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class RemovalRequestPdfStorage
{
    public function __construct(
        private readonly RemovalRequestNormalizer $normalizer,
    ) {}

    public function store(PreparedRemovalPdf $pdf, string $vehicleId): string
    {
        return $this->storeFile(
            $pdf->temporaryPath,
            $pdf->fileName,
            $vehicleId,
            '/^CartaDeRemoção [A-Z0-9]{7}\.pdf$/u',
        );
    }

    public function storeConsignorLetter(PreparedConsignorLetter $letter, string $vehicleId): string
    {
        return $this->storeFile(
            $letter->temporaryPath,
            $letter->fileName,
            $vehicleId,
            '/^CartaDoComitente [A-Z0-9]{7}\.pdf$/u',
        );
    }

    private function storeFile(
        string $temporaryPath,
        string $fileName,
        string $vehicleId,
        string $fileNamePattern,
    ): string {
        $normalizedVehicleId = $this->normalizer->identifier($vehicleId);

        if ($normalizedVehicleId === null || preg_match('/^\d+$/D', $normalizedVehicleId) !== 1) {
            throw new DomainException('O ID do veículo é inválido.');
        }

        if (preg_match($fileNamePattern, $fileName) !== 1) {
            throw new DomainException('O nome do arquivo PDF é inválido.');
        }

        if (! is_file($temporaryPath) || ! is_readable($temporaryPath)) {
            throw new RuntimeException('O arquivo temporário do PDF não está disponível.');
        }

        $stream = fopen($temporaryPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo temporário do PDF.');
        }

        $path = 'registros/copart/'.$normalizedVehicleId.'/'.Str::uuid().'/'.$fileName;

        try {
            if (! Storage::disk('s3')->put($path, $stream, ['visibility' => 'public'])) {
                throw new RuntimeException('Não foi possível armazenar o PDF no S3.');
            }

            return $path;
        } finally {
            fclose($stream);
        }
    }

    public function delete(?string $path): void
    {
        if ($path === null || trim($path) === '') {
            return;
        }

        if (! Storage::disk('s3')->delete($path)) {
            throw new RuntimeException('Não foi possível remover o PDF do S3: '.$path);
        }
    }
}
