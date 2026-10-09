<?php

namespace App\Services\Ciot;

use App\Models\Ciot;
use App\Models\MdfeDocument;

class PrepareMdfeDocumentResult
{
    public function __construct(
        public readonly Ciot $ciot,
        public readonly ?MdfeDocument $mdfe,
        public readonly ?CiotEmissionResult $ciotEmissionResult = null,
        public readonly ?string $message = null,
    ) {}
}
