<?php

namespace App\Services\Dfe;

use Carbon\CarbonImmutable;

class BuiltMdfeEvent
{
    public function __construct(
        public readonly string $xml,
        public readonly string $eventId,
        public readonly CarbonImmutable $eventDate,
        public readonly string $cUf,
        public readonly string $cMun,
    ) {}
}
