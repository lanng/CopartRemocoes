<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

final readonly class PreparedRemovalPdf
{
    /**
     * @param  array<string, mixed>  $extractedData
     */
    public function __construct(
        public string $temporaryPath,
        public string $sha256,
        public string $fileName,
        public array $extractedData,
    ) {}
}
