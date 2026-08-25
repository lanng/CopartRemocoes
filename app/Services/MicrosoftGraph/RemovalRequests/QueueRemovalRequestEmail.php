<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Jobs\ProcessRemovalRequestEmail;
use App\Models\IntegrationInboxItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class QueueRemovalRequestEmail
{
    private const DISPATCH_TTL = 900;

    public function __construct(
        private readonly RemovalRequestSubjectParser $subjectParser,
        private readonly RemovalRequestBodyParser $bodyParser,
    ) {}

    /** @param array<string, mixed> $message */
    public function handle(array $message): IntegrationInboxItem
    {
        $subject = $this->subjectParser->parse(trim((string) ($message['subject'] ?? ''))) ?? [];
        $body = $this->bodyParser->parse((string) ($message['body'] ?? ''));

        try {
            return DB::transaction(function () use ($message, $subject, $body): IntegrationInboxItem {
                $item = IntegrationInboxItem::query()
                    ->where('source', 'microsoft_graph')
                    ->where('external_id', $message['external_id'])
                    ->first();

                if ($item === null) {
                    $item = IntegrationInboxItem::query()->create([
                        'source' => 'microsoft_graph',
                        'external_id' => $message['external_id'],
                        'message_type' => 'removal_request',
                        'status' => 'queued',
                        'sender' => $message['sender'] ?? null,
                        'subject' => $message['subject'] ?? null,
                        'received_at' => Carbon::parse($message['receivedDateTime'])->utc(),
                        'extracted_vehicle_id' => $subject['vehicle_id'] ?? null,
                        'extracted_vehicle_plate' => $subject['vehicle_plate'] ?? null,
                        'extracted_data' => [
                            'subject' => $subject,
                            'body' => $body['data'],
                            'body_missing_fields' => $body['missing_fields'],
                        ],
                    ]);
                }

                $this->scheduleRecoveryAfterCommit($item);

                return $item;
            });
        } catch (QueryException $exception) {
            $item = IntegrationInboxItem::query()
                ->where('source', 'microsoft_graph')
                ->where('external_id', $message['external_id'])
                ->first();

            if ($item === null) {
                throw $exception;
            }

            $this->scheduleRecoveryAfterCommit($item);

            return $item;
        }
    }

    private function scheduleRecoveryAfterCommit(IntegrationInboxItem $item): void
    {
        if ($item->status !== 'queued') {
            return;
        }

        DB::afterCommit(function () use ($item): void {
            $cacheKey = 'removal-request-dispatch:'.$item->id;

            if (! Cache::add($cacheKey, true, self::DISPATCH_TTL)) {
                return;
            }

            try {
                ProcessRemovalRequestEmail::dispatch($item->id)->afterCommit();
            } catch (Throwable $exception) {
                Cache::forget($cacheKey);

                throw $exception;
            }
        });
    }
}
