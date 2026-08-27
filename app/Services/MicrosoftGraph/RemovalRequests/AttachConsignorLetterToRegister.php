<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Enums\RegisterStatusEnum;
use App\Models\IntegrationInboxItem;
use App\Models\MicrosoftGraphConnection;
use App\Models\Register;
use Illuminate\Support\Facades\DB;
use Throwable;

class AttachConsignorLetterToRegister
{
    public function __construct(
        private readonly RemovalRequestPdfPreparer $preparer,
        private readonly RemovalRequestPdfStorage $storage,
    ) {}

    public function handle(IntegrationInboxItem $item, MicrosoftGraphConnection $connection): IntegrationInboxItem
    {
        $item = $item->refresh();

        if ($item->message_type !== 'removal_request' || ! in_array($item->status, ['processed', 'alert', 'no_changes'], true)) {
            return $item;
        }

        $register = $item->register()->first();

        if (! $register instanceof Register || ! $this->shouldAttach($register)) {
            return $item;
        }

        $letter = null;
        $uploadedPath = null;
        $persisted = false;

        try {
            $letter = $this->preparer->prepareConsignorLetter(
                $connection,
                $item->external_id,
                (string) $item->extracted_vehicle_plate,
            );

            if ($letter === null) {
                return $item;
            }

            $uploadedPath = $this->storage->storeConsignorLetter($letter, (string) $register->vehicle_id);

            DB::transaction(function () use ($item, $uploadedPath, $letter, &$persisted): void {
                $lockedItem = IntegrationInboxItem::query()->lockForUpdate()->findOrFail($item->id);
                $lockedRegister = Register::query()->lockForUpdate()->findOrFail($lockedItem->register_id);

                if (! $this->shouldAttach($lockedRegister)) {
                    return;
                }

                $lockedRegister->forceFill([
                    'consignor_letter_path' => $uploadedPath,
                    'consignor_letter_sha256' => $letter->sha256,
                ])->save();
                $persisted = true;
            });

            if (! $persisted && $uploadedPath !== null) {
                $this->storage->delete($uploadedPath);
            }
        } catch (Throwable) {
            if ($uploadedPath !== null && ! $persisted) {
                try {
                    $this->storage->delete($uploadedPath);
                } catch (Throwable) {
                }
            }

            $this->markFailure($item);
        } finally {
            if ($letter !== null) {
                @unlink($letter->temporaryPath);
            }
        }

        return $item->refresh();
    }

    private function shouldAttach(Register $register): bool
    {
        if ($register->consignor_letter_path !== null && trim($register->consignor_letter_path) !== '') {
            return false;
        }

        $status = $register->status instanceof RegisterStatusEnum
            ? $register->status->value
            : (string) $register->status;

        return in_array($status, [
            RegisterStatusEnum::PENDING->value,
            RegisterStatusEnum::COLLECTED->value,
        ], true);
    }

    private function markFailure(IntegrationInboxItem $item): void
    {
        $item->refresh();
        $alerts = is_array($item->alerts) ? $item->alerts : [];
        $alerts[] = 'consignor_letter_failed';
        $alerts = array_values(array_unique($alerts));
        $data = is_array($item->extracted_data) ? $item->extracted_data : [];
        $technicalAlerts = is_array($data['technical_alerts'] ?? null) ? $data['technical_alerts'] : [];
        $technicalAlerts[] = ['type' => 'consignor_letter_failed'];

        $item->forceFill([
            'status' => 'alert',
            'failure_reason' => null,
            'alerts' => $alerts,
            'extracted_data' => array_merge($data, ['technical_alerts' => $technicalAlerts]),
            'resolved_at' => null,
        ])->save();
    }
}
