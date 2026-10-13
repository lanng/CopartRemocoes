<?php

namespace App\Services\Cte;

use App\Enums\RegisterStatusEnum;
use App\Models\CteDocument;
use App\Models\Register;

class MarkRegisterInvoicedForCte
{
    public function handle(CteDocument $document): void
    {
        if ($document->register_id === null) {
            return;
        }

        $register = Register::query()->lockForUpdate()->find($document->register_id);

        if ($register === null || $register->status === RegisterStatusEnum::INVOICED) {
            return;
        }

        $register->status = RegisterStatusEnum::INVOICED;
        $register->save();
    }
}
