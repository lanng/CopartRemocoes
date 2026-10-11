<?php

namespace App\Enums;

enum FiscalDocumentStatusEnum: string
{
    case NOT_AUTHORIZED = 'not_authorized';
    case AUTHORIZED = 'authorized';
    case CANCELLED = 'cancelled';
    case CLOSED = 'closed';
}
