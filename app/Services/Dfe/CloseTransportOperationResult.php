<?php

namespace App\Services\Dfe;

use App\Models\MdfeDocument;
use App\Models\MdfeEvent;

class CloseTransportOperationResult
{
    public const COMPLETED = 'completed';

    public const MDFE_PENDING = 'mdfe_pending';

    public const CIOT_PENDING = 'ciot_pending';

    public function __construct(
        public readonly string $status,
        public readonly MdfeDocument $mdfe,
        public readonly ?MdfeEvent $event = null,
        public readonly ?string $message = null,
    ) {}
}
