<?php

namespace App\Enums;

enum CiotEmissionOutcome: string
{
    case Issued = 'issued';
    case Failed = 'failed';
    case Invalid = 'invalid';
    case Queued = 'queued';
    case Enqueued = 'enqueued';
    case Errored = 'errored';
}
