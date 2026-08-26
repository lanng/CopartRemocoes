<?php

namespace App\Services\MicrosoftGraph;

use App\Enums\RegisterStatusEnum;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ProcessChecklistEmail
{
    /** @param array<string, mixed> $message */
    public function handle(array $message): ?IntegrationInboxItem
    {
        $sender = strtolower(trim((string) ($message['sender'] ?? '')));
        $subject = trim((string) ($message['subject'] ?? ''));

        if ($sender !== 'remocao@copart.com.br'
            || ! str_starts_with(mb_strtolower($subject), mb_strtolower('Checklist digital'))) {
            return null;
        }

        return DB::transaction(function () use ($message): IntegrationInboxItem {
            $existing = IntegrationInboxItem::query()
                ->where('source', 'microsoft_graph')
                ->where('external_id', $message['external_id'])
                ->first();

            if ($existing) {
                return $existing;
            }

            $parsed = app(ChecklistEmailParser::class)->parse([
                'sender' => (string) ($message['sender'] ?? ''),
                'subject' => (string) ($message['subject'] ?? ''),
                'body' => (string) ($message['body'] ?? ''),
            ]);
            $receivedAt = Carbon::parse($message['receivedDateTime'])->utc();

            $item = IntegrationInboxItem::query()->create([
                'source' => 'microsoft_graph',
                'external_id' => $message['external_id'],
                'status' => 'pending',
                'sender' => $message['sender'] ?? null,
                'subject' => $message['subject'] ?? null,
                'received_at' => $receivedAt,
                'extracted_vehicle_id' => $parsed['vehicle_id'] ?? null,
                'extracted_vehicle_plate' => $parsed['vehicle_plate'] ?? null,
                'failure_reason' => $parsed['reason'] ?? null,
            ]);

            if (! $parsed['valid']) {
                return $item;
            }

            $registers = Register::query()
                ->where('company', 'copart')
                ->where('vehicle_id', $parsed['vehicle_id'])
                ->lockForUpdate()
                ->get();

            if ($registers->count() !== 1) {
                $item->update(['failure_reason' => 'register_not_found_or_ambiguous']);

                return $item->refresh();
            }

            $register = $registers->first();
            $storedPlate = strtoupper(str_replace('-', '', $register->vehicle_plate));

            if ($storedPlate !== $parsed['vehicle_plate']) {
                $item->update([
                    'register_id' => $register->id,
                    'failure_reason' => 'vehicle_plate_mismatch',
                ]);

                return $item->refresh();
            }

            if ($register->delivery_confirmed_at) {
                $item->update([
                    'register_id' => $register->id,
                    'status' => 'duplicate',
                    'failure_reason' => 'delivery_already_confirmed',
                    'resolved_at' => now(),
                ]);

                return $item->refresh();
            }

            $previousRegisterStatus = $register->status->value;
            $authorizedCteNumber = $register->latestAuthorizedCteDocument()->value('cte_number');
            $authorizedCteNumber = blank($authorizedCteNumber) ? null : $authorizedCteNumber;
            $deliveryAlert = in_array($register->status, [
                RegisterStatusEnum::INVOICED,
                RegisterStatusEnum::DELIVERED,
            ], true)
                ? null
                : ($authorizedCteNumber !== null ? 'unexpected_status' : 'missing_authorized_cte');

            $register->forceFill([
                'delivery_confirmed_at' => $receivedAt,
                'status' => RegisterStatusEnum::DELIVERED,
            ])->save();

            $item->update([
                'register_id' => $register->id,
                'status' => 'processed',
                'failure_reason' => null,
                'previous_register_status' => $previousRegisterStatus,
                'delivery_alert' => $deliveryAlert,
                'authorized_cte_number_at_delivery' => $authorizedCteNumber,
                'resolved_at' => now(),
            ]);

            return $item->refresh();
        });
    }
}
