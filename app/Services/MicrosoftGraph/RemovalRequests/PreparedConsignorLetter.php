<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

/**
 * The caller owns the temporary file and must remove it after use. Storage never removes it.
 */
final readonly class PreparedConsignorLetter
{
    public function __construct(
        public string $temporaryPath,
        public string $sha256,
        public string $fileName,
    ) {}
}
