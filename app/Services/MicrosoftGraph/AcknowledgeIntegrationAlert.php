<?php

namespace App\Services\MicrosoftGraph;

use App\Models\IntegrationInboxItem;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

class AcknowledgeIntegrationAlert
{
    public function handle(IntegrationInboxItem $item, User $user): IntegrationInboxItem
    {
        return DB::transaction(function () use ($item, $user): IntegrationInboxItem {
            $lockedItem = IntegrationInboxItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($lockedItem->message_type !== 'checklist'
                || $lockedItem->delivery_alert === null
                || $lockedItem->acknowledged_at !== null) {
                throw new DomainException('Somente alertas de baixa ainda não reconhecidos podem ser reconhecidos.');
            }

            $lockedItem->forceFill([
                'acknowledged_by' => $user->id,
                'acknowledged_at' => now(),
            ])->save();

            return $lockedItem->refresh();
        });
    }
}
