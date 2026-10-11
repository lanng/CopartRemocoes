<?php

namespace App\Services\Dfe;

use Carbon\CarbonImmutable;

class BuiltCancellationEvent
{
    public function __construct(
        public readonly string $xml,
        public readonly string $eventId,
        public readonly CarbonImmutable $eventDate,
    ) {}
}
