<?php

namespace App\Services\MicrosoftGraph;

use App\Models\MicrosoftGraphConnection;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestMessageRouter;

class SyncChecklistEmailsService
{
    public function __construct(
        private readonly MicrosoftGraphClient $client,
        private readonly RemovalRequestMessageRouter $router,
    ) {}

    /** @return array{processed: int, ignored: int} */
    public function handle(): array
    {
        $connection = MicrosoftGraphConnection::query()->where('is_active', true)->first();

        if (! $connection) {
            return ['processed' => 0, 'ignored' => 0];
        }

        try {
            $result = $this->client->fetchNewMessages($connection);
            $processed = 0;
            $ignored = 0;

            foreach ($result['messages'] as $message) {
                if ($this->router->handle($message)) {
                    $processed++;
                } else {
                    $ignored++;
                }
            }

            $connection->update([
                'delta_link' => null,
                'last_synced_at' => $result['checkpoint_at'],
                'last_error' => null,
            ]);
        } catch (\Throwable $exception) {
            $connection->update(['last_error' => $exception->getMessage()]);

            throw $exception;
        }

        return compact('processed', 'ignored');
    }
}
