<?php

namespace App\Services\Payments;

use App\Enums\PaymentBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\PaymentBatch;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class ConfirmPaymentBatch
{
    public function handle(PaymentBatch $batch, User $user): PaymentBatch
    {
        return DB::transaction(function () use ($batch, $user): PaymentBatch {
            $batch = PaymentBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($batch->status !== PaymentBatchStatusEnum::PENDING) {
                throw new DomainException('Este lote de pagamento já foi confirmado.');
            }

            $items = $batch->items()->orderBy('register_id')->get();
            $registers = \App\Models\Register::query()
                ->whereIn('id', $items->pluck('register_id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($items as $item) {
                $register = $registers->get($item->register_id);

                if (! $register || $register->status !== RegisterStatusEnum::DELIVERED) {
                    throw new DomainException("O registro {$item->vehicle_plate} não está mais entregue.");
                }
            }

            foreach ($registers as $register) {
                $register->update(['status' => RegisterStatusEnum::PAID]);
            }

            $batch->update([
                'status' => PaymentBatchStatusEnum::CONFIRMED,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ]);

            return $batch->refresh();
        });
    }
}
