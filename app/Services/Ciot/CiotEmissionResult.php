<?php

namespace App\Services\Ciot;

use App\Enums\CiotEmissionOutcome;
use App\Models\Ciot;

/**
 * Resultado de `EmitCiotDeclaration::emit()`/`enqueue()`: o outcome da
 * tentativa, o registro fresco após os side-effects e a mensagem de erro
 * quando houver (rejeição da ANTT ou violação de regra).
 */
class CiotEmissionResult
{
    public function __construct(
        public readonly CiotEmissionOutcome $outcome,
        public readonly Ciot $ciot,
        public readonly ?string $message = null,
    ) {}

    public function isIssued(): bool
    {
        return $this->outcome === CiotEmissionOutcome::Issued;
    }

    /**
     * Número completo de 16 dígitos do CIOT emitido (delega ao registro).
     */
    public function fullNumber(): ?string
    {
        return $this->ciot->fullNumber();
    }
}
