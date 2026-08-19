<?php

namespace App\Services\Payments;

use App\Enums\PaymentBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Register;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemovePaymentBatchItem
{
    public function handle(PaymentBatchItem $item): void
    {
        DB::transaction(function () use ($item): void {
            $register = Register::query()->lockForUpdate()->findOrFail($item->register_id);
            $batch = PaymentBatch::query()
                ->lockForUpdate()
                ->findOrFail($item->payment_batch_id);
            $lockedItem = PaymentBatchItem::query()
                ->lockForUpdate()
                ->findOrFail($item->id);

            if ($lockedItem->payment_batch_id !== $batch->id) {
                throw ValidationException::withMessages([
                    'item' => 'O item não pertence ao lote informado.',
                ]);
            }

            if ($batch->status !== PaymentBatchStatusEnum::PENDING) {
                throw ValidationException::withMessages([
                    'batch' => 'Somente itens de lotes pendentes podem ser retirados.',
                ]);
            }

            if ($lockedItem->register_id !== $register->id) {
                throw ValidationException::withMessages([
                    'item' => 'O item não pertence ao registro informado.',
                ]);
            }

            if ($register->status !== RegisterStatusEnum::DELIVERED) {
                throw ValidationException::withMessages([
                    'register' => 'Somente registros entregues podem ser adiados para pagamento.',
                ]);
            }

            $register->update(['payment_deferred_at' => now()]);
            $lockedItem->delete();

            if (! $batch->items()->exists()) {
                $batch->delete();

                return;
            }

            $batch->update([
                'total_amount' => $batch->items()->sum('amount'),
            ]);
        });
    }
}
