<?php

namespace App\Services\MicrosoftGraph;

use App\Models\MicrosoftGraphConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class MicrosoftGraphClient
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    /**
     * @return array{messages: list<array<string, mixed>>, checkpoint_at: Carbon}
     */
    public function fetchNewMessages(MicrosoftGraphConnection $connection): array
    {
        $accessToken = $this->accessToken($connection);
        $scanStartedAt = Carbon::now('UTC');
        $checkpoint = ($connection->last_synced_at ?? $connection->activated_at)->utc();
        $response = Http::withToken($accessToken)
            ->connectTimeout(10)
            ->timeout(30)
            ->acceptJson()
            ->withHeaders(['Prefer' => 'outlook.body-content-type="text"'])
            ->get(self::GRAPH_BASE_URL.'/me/mailFolders/inbox/messages', [
                '$select' => 'id,subject,sender,receivedDateTime,body',
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
