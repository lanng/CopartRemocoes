<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Jobs\ProcessRemovalRequestEmail;
use App\Models\IntegrationInboxItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class QueueRemovalRequestEmail
{
    public function __construct(
        private readonly RemovalRequestSubjectParser $subjectParser,
        private readonly RemovalRequestBodyParser $bodyParser,
    ) {}

    /** @param array<string, mixed> $message */
    public function handle(array $message): IntegrationInboxItem
    {
        return DB::transaction(function () use ($message): IntegrationInboxItem {
            $subject = $this->subjectParser->parse(trim((string) ($message['subject'] ?? ''))) ?? [];
            $body = $this->bodyParser->parse((string) ($message['body'] ?? ''));

            $item = IntegrationInboxItem::query()->firstOrCreate(
                [
                    'source' => 'microsoft_graph',
                    'external_id' => $message['external_id'],
                ],
                [
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
                ],
            );

            if ($item->wasRecentlyCreated) {
                ProcessRemovalRequestEmail::dispatch($item->id)->afterCommit();
            }

            return $item;
        });
    }
}
