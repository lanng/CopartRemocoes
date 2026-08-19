<?php

namespace App\Services\Payments;

use App\Enums\PaymentBatchStatusEnum;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Register;
use Illuminate\Support\Facades\DB;

class DetachPaymentBatchItemsFromRegister
{
    public function handle(Register $register): void
    {
        DB::transaction(function () use ($register): void {
            $lockedRegister = Register::query()->lockForUpdate()->findOrFail($register->id);
            $items = PaymentBatchItem::query()
                ->where('register_id', $lockedRegister->id)
                ->get();

            foreach ($items as $item) {
                $batch = PaymentBatch::query()
                    ->lockForUpdate()
                    ->find($item->payment_batch_id);

                if (! $batch) {
                    continue;
                }

                $lockedItem = PaymentBatchItem::query()
                    ->lockForUpdate()
                    ->find($item->id);

                if (! $lockedItem || $lockedItem->register_id !== $lockedRegister->id) {
                    continue;
                }

                $lockedItem->delete();

                if ($batch->status !== PaymentBatchStatusEnum::PENDING) {
                    continue;
                }

                if (! $batch->items()->exists()) {
                    $batch->delete();

                    continue;
                }

                $batch->update([
                    'total_amount' => $batch->items()->sum('amount'),
                ]);
            }
        });
    }
}
