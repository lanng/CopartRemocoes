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

        if ($register === null) {
            return;
        }

        $status = $register->delivery_confirmed_at !== null ? RegisterStatusEnum::DELIVERED : RegisterStatusEnum::INVOICED;

        if ($register->status === $status) {
            return;
        }

        $register->status = $status;
        $register->save();
    }
}
