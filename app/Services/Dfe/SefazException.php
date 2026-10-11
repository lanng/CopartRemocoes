<?php

namespace App\Services\Dfe;

use RuntimeException;

class SefazException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $cStat = null,
        public readonly ?string $xMotivo = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Falha de rede/5xx melhora com nova tentativa do job (spec §4.3);
     * rejeição de conteúdo da SEFAZ é terminal.
     */
    public function retryable(): bool
    {
        return $this->httpStatus === null || $this->httpStatus === 0 || $this->httpStatus >= 500;
    }

    public function isBusinessRejection(): bool
    {
        return $this->cStat !== null;
    }
}
