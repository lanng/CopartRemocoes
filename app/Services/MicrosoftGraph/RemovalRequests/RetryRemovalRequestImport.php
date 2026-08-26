<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Jobs\ProcessRemovalRequestEmail;
use App\Models\IntegrationInboxItem;
use DomainException;
use Illuminate\Support\Facades\DB;

class RetryRemovalRequestImport
{
    public function handle(IntegrationInboxItem $item): IntegrationInboxItem
    {
        return DB::transaction(function () use ($item): IntegrationInboxItem {
            $lockedItem = IntegrationInboxItem::query()->lockForUpdate()->findOrFail($item->id);

            if ($lockedItem->message_type !== 'removal_request' || ! in_array($lockedItem->status, ['pending', 'alert'], true)) {
                throw new DomainException('Somente importações abertas de pedidos de remoção podem ser reprocessadas.');
            }

            $lockedItem->forceFill([
                'status' => 'queued',
                'failure_reason' => null,
                'resolved_at' => null,
            ])->save();

            dispatch(new ProcessRemovalRequestEmail($lockedItem->id))->afterCommit();

            return $lockedItem->refresh();
        });
    }
}
