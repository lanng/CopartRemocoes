<?php

namespace App\Services\MicrosoftGraph;

use App\Models\MicrosoftGraphConnection;
use DomainException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MicrosoftGraphClient
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    private const PREFER_HEADER = 'IdType="ImmutableId", outlook.body-content-type="text"';

    /**
     * @return array{messages: list<array<string, mixed>>, checkpoint_at: Carbon}
     */
    public function fetchNewMessages(MicrosoftGraphConnection $connection): array
    {
        $accessToken = $this->accessToken($connection);
        $scanStartedAt = Carbon::now('UTC');
        $checkpoint = ($connection->last_synced_at ?? $connection->activated_at)->utc();
        $response = $this->graphRequest($accessToken)
            ->acceptJson()
            ->get(self::GRAPH_BASE_URL.'/me/mailFolders/inbox/messages', [
                '$select' => 'id,subject,sender,receivedDateTime,body,hasAttachments',
                '$filter' => 'receivedDateTime gt '.$checkpoint->toIso8601ZuluString(),
                '$orderby' => 'receivedDateTime asc',
                '$top' => 50,
            ])
            ->throw();
        $messages = collect($response->json('value', []))
            ->map(fn (array $message): array => [
                'external_id' => $message['id'],
                'sender' => $message['sender']['emailAddress']['address'] ?? '',
                'subject' => $message['subject'] ?? '',
                'body' => $message['body']['content'] ?? '',
                'receivedDateTime' => $message['receivedDateTime'],
                'hasAttachments' => (bool) ($message['hasAttachments'] ?? false),
            ])
            ->values()
            ->all();

        $checkpointAt = count($messages) === 50
            ? Carbon::parse($messages[49]['receivedDateTime'])->utc()
            : $scanStartedAt;

        return [
            'messages' => $messages,
            'checkpoint_at' => $checkpointAt,
        ];
    }

    /**
     * @return list<array{id: string, name: string, content_type: ?string, size: int, is_inline: bool, type: ?string}>
     */
    public function listMessageAttachments(MicrosoftGraphConnection $connection, string $messageId): array
    {
        $response = $this->graphRequest($this->accessToken($connection))
            ->acceptJson()
            ->get(self::GRAPH_BASE_URL.'/me/messages/'.rawurlencode($messageId).'/attachments')
            ->throw();

        return collect($response->json('value', []))
            ->map(fn (array $attachment): array => [
                'id' => (string) ($attachment['id'] ?? ''),
                'name' => (string) ($attachment['name'] ?? ''),
                'content_type' => $attachment['contentType'] ?? null,
                'size' => (int) ($attachment['size'] ?? 0),
                'is_inline' => (bool) ($attachment['isInline'] ?? false),
                'type' => $attachment['@odata.type'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function downloadMessageAttachment(
        MicrosoftGraphConnection $connection,
        string $messageId,
        string $attachmentId,
    ): string {
        return $this->graphRequest($this->accessToken($connection))
            ->get(self::GRAPH_BASE_URL.'/me/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId).'/$value')
            ->throw()
            ->body();
    }

    /**
     * @throws \Illuminate\Http\Client\RequestException
     * @throws DomainException
     * @throws RuntimeException
     */
    public function downloadMessageAttachmentToPath(
        MicrosoftGraphConnection $connection,
        string $messageId,
        string $attachmentId,
        string $destinationPath,
        int $maxBytes,
    ): int {
        if ($maxBytes < 0) {
            throw new DomainException('O limite máximo do anexo não pode ser negativo.');
        }

        $response = $this->graphRequest($this->accessToken($connection))
            ->get(self::GRAPH_BASE_URL.'/me/messages/'.rawurlencode($messageId).'/attachments/'.rawurlencode($attachmentId).'/$value')
            ->throw();
        $source = $response->toPsrResponse()->getBody();
        $destination = null;

        try {
            $destination = fopen($destinationPath, 'wb');

            if ($destination === false) {
                throw new RuntimeException('Não foi possível abrir o arquivo de destino do anexo.');
            }

            $copiedBytes = 0;

            while (! $source->eof()) {
                $chunk = $source->read(8192);

                if ($chunk === '') {
                    break;
                }

                $chunkSize = strlen($chunk);

                if ($copiedBytes + $chunkSize > $maxBytes) {
                    throw new DomainException('O anexo excede o limite máximo configurado.');
                }

                $this->writeChunk($destination, $chunk);
                $copiedBytes += $chunkSize;
            }

            return $copiedBytes;
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }

            $source->close();
        }
    }

    /** @param resource $destination */
    private function writeChunk($destination, string $chunk): void
    {
        $offset = 0;
        $chunkSize = strlen($chunk);

        while ($offset < $chunkSize) {
            $writtenBytes = fwrite($destination, substr($chunk, $offset));

            if ($writtenBytes === false || $writtenBytes === 0) {
                throw new RuntimeException('Não foi possível gravar integralmente o anexo.');
            }

            $offset += $writtenBytes;
        }
    }

    private function graphRequest(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->connectTimeout(10)
            ->timeout(30)
            ->withHeaders(['Prefer' => self::PREFER_HEADER]);
    }

    private function accessToken(MicrosoftGraphConnection $connection): string
    {
        if ($connection->expires_at?->isFuture()) {
            return $connection->access_token;
        }

        $payload = Http::asForm()
            ->connectTimeout(10)
            ->timeout(30)
            ->post('https://login.microsoftonline.com/'.config('services.microsoft_graph.tenant').'/oauth2/v2.0/token', [
                'client_id' => config('services.microsoft_graph.client_id'),
                'client_secret' => config('services.microsoft_graph.client_secret'),
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'scope' => config('services.microsoft_graph.scopes'),
            ])
            ->throw()
            ->json();

        $connection->update([
            'access_token' => $payload['access_token'],
            'refresh_token' => $payload['refresh_token'] ?? $connection->refresh_token,
            'expires_at' => now()->addSeconds($payload['expires_in'] ?? 3600),
        ]);

        return $payload['access_token'];
    }
}
