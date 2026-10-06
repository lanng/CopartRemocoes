<?php

namespace App\Services\Dfe;

class BuiltMdfe
{
    public function __construct(
        public readonly string $xml,
        public readonly string $accessKey,
        public readonly int $number,
        public readonly int $series,
        public readonly string $numericCode,
    ) {}
}
