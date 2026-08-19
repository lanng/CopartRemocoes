<?php

namespace App\Services\Payments;

use App\Enums\RegisterStatusEnum;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchRun;
use Illuminate\Support\Facades\DB;

class CreatePaymentBatchForWindow
{
    public function handle(PaymentBatchWindow $window, bool $outlookSyncFailed = false, ?string $outlookSyncError = null): ?PaymentBatch
    {
        return DB::transaction(function () use ($outlookSyncError, $outlookSyncFailed, $window): ?PaymentBatch {
            $windowStart = $window->start->toDateString();
            $windowEnd = $window->end->toDateString();
            PaymentBatchRun::query()->upsert([
                [
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ], ['window_start', 'window_end'], []);

            $run = PaymentBatchRun::query()
                ->whereDate('window_start', $windowStart)
                ->whereDate('window_end', $windowEnd)
                ->lockForUpdate()
                ->first();

            if ($run->processed_at) {
                return PaymentBatch::query()
                    ->whereDate('window_start', $windowStart)
                    ->whereDate('window_end', $windowEnd)
                    ->first();
            }

            $registers = \App\Models\Register::query()
                ->with('latestAuthorizedCteDocument')
                ->where('company', 'copart')
                ->where('status', RegisterStatusEnum::DELIVERED)
                ->where(function ($query) use ($window): void {
                    $query
                        ->whereBetween('delivery_confirmed_at', [$window->start->utc(), $window->end->utc()])
                        ->orWhereNotNull('payment_deferred_at');
                })
                ->whereDoesntHave('paymentBatchItems')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($registers->isEmpty()) {
                $run->update([
                    'processed_at' => now(),
                    'result' => 'empty',
                    'item_count' => 0,
                    'outlook_sync_failed' => $outlookSyncFailed,
                    'outlook_sync_error' => $outlookSyncError,
                ]);

                return null;
            }

            $batch = PaymentBatch::query()->create([
                'status' => 'pending',
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'generated_at' => now(),
                'total_amount' => $registers->sum(fn ($register): string => (string) $register->value),
                'outlook_sync_failed' => $outlookSyncFailed,
                'outlook_sync_error' => $outlookSyncError,
            ]);

            foreach ($registers as $register) {
                $batch->items()->create([
                    'register_id' => $register->id,
                    'vehicle_plate' => $register->vehicle_plate,
                    'amount' => $register->value,
                    'cte_number' => $register->latestAuthorizedCteDocument?->cte_number,
                    'delivery_confirmed_at' => $register->delivery_confirmed_at,
                ]);

                if ($register->payment_deferred_at) {
                    $register->update(['payment_deferred_at' => null]);
                }
            }

            $run->update([
                'processed_at' => now(),
                'result' => 'created',
                'item_count' => $registers->count(),
                'outlook_sync_failed' => $outlookSyncFailed,
                'outlook_sync_error' => $outlookSyncError,
            ]);

            return $batch->refresh();
        });
    }
}
