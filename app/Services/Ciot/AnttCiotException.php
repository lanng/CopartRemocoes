<?php

namespace App\Services\Ciot;

use RuntimeException;

class AnttCiotException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        string $message,
        public readonly ?string $codigo = null,
        public readonly ?string $mensagem = null,
        public readonly ?int $httpStatus = null,
        public readonly array $body = [],
    ) {
        parent::__construct($message);
    }

    public function isBusinessRejection(): bool
    {
        return $this->codigo !== null && ! in_array($this->codigo, ['110', '111'], true);
    }

    public function retryable(): bool
    {
        return $this->httpStatus === null || $this->httpStatus === 0 || $this->httpStatus >= 500;
    }
}
