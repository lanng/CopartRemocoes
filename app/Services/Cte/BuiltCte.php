<?php

namespace App\Services\Cte;

/**
 * CT-e pronto para assinatura/transmissão (F3): o XML sem Signature e os
 * dados derivados para persistir no CteDocument.
 */
class BuiltCte
{
    public function __construct(
        public readonly string $xml,
        public readonly string $accessKey,
        public readonly int $number,
        public readonly int $serie,
        public readonly string $cct,
    ) {}
}
