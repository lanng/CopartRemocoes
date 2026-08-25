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
        $normalizedVehicleId = $this->normalizer->identifier($vehicleId);

        if ($normalizedVehicleId === null || preg_match('/^\d+$/D', $normalizedVehicleId) !== 1) {
            throw new DomainException('O ID do veículo é inválido.');
        }

        if (! is_file($pdf->temporaryPath) || ! is_readable($pdf->temporaryPath)) {
            throw new RuntimeException('O arquivo temporário do PDF não está disponível.');
        }

        $stream = fopen($pdf->temporaryPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo temporário do PDF.');
        }

        $path = 'registros/copart/'.$normalizedVehicleId.'/'.Str::uuid().'/'.$pdf->fileName;

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

        Storage::disk('s3')->delete($path);
    }
}
