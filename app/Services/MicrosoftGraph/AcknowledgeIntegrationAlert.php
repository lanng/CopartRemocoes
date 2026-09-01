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

            if ($lockedItem->status === 'pending') {
                $this->keepRegisterStatus($lockedItem, $user);

                return $lockedItem->refresh();
            }

            $lockedItem->forceFill([
                'acknowledged_by' => $user->id,
                'acknowledged_at' => now(),
            ])->save();

            return $lockedItem->refresh();
        });
    }

    /**
     * Mantém o status atual do registro e grava a data da entrega,
     * deixando a baixa manual (status Entregue) a cargo do escritório.
     */
    private function keepRegisterStatus(IntegrationInboxItem $item, User $user): void
    {
        $register = $item->register()->lockForUpdate()->firstOrFail();

        $register->forceFill([
            'delivery_confirmed_at' => $register->delivery_confirmed_at ?? $item->received_at,
        ])->save();

        $item->forceFill([
            'status' => 'processed',
            'failure_reason' => 'status_kept_by_user',
            'acknowledged_by' => $user->id,
            'acknowledged_at' => now(),
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ])->save();
    }
}
