<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Models\MicrosoftGraphConnection;
use App\Services\MicrosoftGraph\MicrosoftGraphClient;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicrosoftGraphClientTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fetches_at_most_fifty_messages_after_the_utc_checkpoint(): void
    {
        Carbon::setTestNow('2026-08-13 20:55:00');
        $connection = MicrosoftGraphConnection::factory()->create([
            'last_synced_at' => '2026-08-13 20:50:00',
        ]);
        Http::fake([
            'https://graph.microsoft.com/*' => Http::response([
                'value' => [[
                    'id' => 'message-1',
                    'subject' => 'Checklist digital - 1146609',
                    'sender' => ['emailAddress' => ['address' => 'remocao@copart.com.br']],
                    'receivedDateTime' => '2026-08-13T20:52:00Z',
                    'body' => ['content' => 'Veículo 1146609 - ESN4A20.', 'contentType' => 'text'],
                    'hasAttachments' => true,
                ]],
            ]),
        ]);

        $result = app(MicrosoftGraphClient::class)->fetchNewMessages($connection);

        $this->assertSame('message-1', $result['messages'][0]['external_id']);
        $this->assertTrue($result['messages'][0]['hasAttachments']);
        $this->assertSame('2026-08-13 20:55:00', $result['checkpoint_at']->format('Y-m-d H:i:s'));
        Http::assertSent(function (Request $request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages?')
                && $request->header('Prefer') === ['IdType="ImmutableId", outlook.body-content-type="text"']
                && $query['$select'] === 'id,subject,sender,receivedDateTime,body,hasAttachments'
                && $query['$top'] === '50'
                && $query['$orderby'] === 'receivedDateTime asc'
                && $query['$filter'] === 'receivedDateTime gt 2026-08-13T20:50:00Z';
        });
        Http::assertSentCount(1);
    }

    public function test_it_lists_and_downloads_message_attachments_with_encoded_immutable_ids(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create();
        $messageId = 'AAMk/message+id==';
        $attachmentId = 'attachment/id==';
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/messages/*/attachments' => Http::response([
                'value' => [[
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'id' => $attachmentId,
                    'name' => 'CartaDeRemoção.pdf',
                    'contentType' => 'application/pdf',
                    'size' => 1234,
                    'isInline' => false,
                ]],
            ]),
            'https://graph.microsoft.com/v1.0/me/messages/*/attachments/*/$value' => Http::response('%PDF-test'),
        ]);

        $client = app(MicrosoftGraphClient::class);
        $attachments = $client->listMessageAttachments($connection, $messageId);
        $contents = $client->downloadMessageAttachment($connection, $messageId, $attachmentId);

        $this->assertSame([[
            'id' => $attachmentId,
            'name' => 'CartaDeRemoção.pdf',
            'content_type' => 'application/pdf',
            'size' => 1234,
            'is_inline' => false,
            'type' => '#microsoft.graph.fileAttachment',
        ]], $attachments);
        $this->assertSame('%PDF-test', $contents);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request) use ($messageId): bool {
            $encodedMessageId = rawurlencode($messageId);

            return $request->url() === 'https://graph.microsoft.com/v1.0/me/messages/'.$encodedMessageId.'/attachments'
                && $request->header('Prefer') === ['IdType="ImmutableId", outlook.body-content-type="text"'];
        });
        Http::assertSent(function (Request $request) use ($messageId, $attachmentId): bool {
            $encodedMessageId = rawurlencode($messageId);
            $encodedAttachmentId = rawurlencode($attachmentId);

            return $request->url() === 'https://graph.microsoft.com/v1.0/me/messages/'.$encodedMessageId.'/attachments/'.$encodedAttachmentId.'/$value'
                && $request->header('Prefer') === ['IdType="ImmutableId", outlook.body-content-type="text"'];
        });
    }

    public function test_downloading_an_attachment_throws_when_graph_returns_an_http_error(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create();
        Http::fake([
            'https://graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Attachment unavailable']], 404),
        ]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);

        app(MicrosoftGraphClient::class)->downloadMessageAttachment($connection, 'message-id', 'attachment-id');
    }

    public function test_it_streams_an_attachment_to_a_file_without_exceeding_the_limit(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create();
        $bytes = '%PDF-streamed-content';
        $destinationPath = tempnam(sys_get_temp_dir(), 'graph_attachment_');
        $this->assertIsString($destinationPath);
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/messages/*/attachments/*/$value' => Http::response($bytes),
        ]);

        try {
            $copiedBytes = app(MicrosoftGraphClient::class)->downloadMessageAttachmentToPath(
                $connection,
                'message-id',
                'attachment-id',
                $destinationPath,
                strlen($bytes),
            );

            $this->assertSame(strlen($bytes), $copiedBytes);
            $this->assertSame(strlen($bytes), filesize($destinationPath));
            $this->assertSame($bytes, file_get_contents($destinationPath));
        } finally {
            @unlink($destinationPath);
        }
    }

    public function test_streaming_an_attachment_aborts_when_the_body_exceeds_the_limit(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create();
        $bytes = '%PDF-streamed-content';
        $destinationPath = tempnam(sys_get_temp_dir(), 'graph_attachment_');
        $this->assertIsString($destinationPath);
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/messages/*/attachments/*/$value' => Http::response($bytes),
        ]);

        try {
            $this->expectException(DomainException::class);
            app(MicrosoftGraphClient::class)->downloadMessageAttachmentToPath(
                $connection,
                'message-id',
                'attachment-id',
                $destinationPath,
                strlen($bytes) - 1,
            );
        } finally {
            $this->assertTrue(unlink($destinationPath));
        }
    }

    public function test_streaming_an_attachment_propagates_graph_http_errors(): void
    {
        $destinationPath = tempnam(sys_get_temp_dir(), 'graph_attachment_');
        $this->assertIsString($destinationPath);
        Http::fake([
            'https://graph.microsoft.com/*' => Http::response(['error' => ['message' => 'Attachment unavailable']], 404),
        ]);

        try {
            $this->expectException(\Illuminate\Http\Client\RequestException::class);

            app(MicrosoftGraphClient::class)->downloadMessageAttachmentToPath(
                MicrosoftGraphConnection::factory()->create(),
                'message-id',
                'attachment-id',
                $destinationPath,
                1024,
            );
        } finally {
            @unlink($destinationPath);
        }
    }

    public function test_a_full_page_advances_only_to_the_last_message(): void
    {
        Carbon::setTestNow('2026-08-13 21:00:00');
        $connection = MicrosoftGraphConnection::factory()->create([
            'last_synced_at' => '2026-08-13 20:50:00',
        ]);
        $messages = collect(range(1, 50))->map(fn (int $index): array => [
            'id' => "message-{$index}",
            'subject' => 'Irrelevante',
            'sender' => ['emailAddress' => ['address' => 'other@example.com']],
            'receivedDateTime' => Carbon::parse('2026-08-13 20:50:00')->addSeconds($index)->toIso8601ZuluString(),
            'body' => ['content' => '', 'contentType' => 'text'],
        ])->all();
        Http::fake(['https://graph.microsoft.com/*' => Http::response(['value' => $messages])]);

        $result = app(MicrosoftGraphClient::class)->fetchNewMessages($connection);

        $this->assertCount(50, $result['messages']);
        $this->assertSame('2026-08-13 20:50:50', $result['checkpoint_at']->format('Y-m-d H:i:s'));
        Http::assertSentCount(1);
    }

    public function test_it_refreshes_an_expired_access_token_before_querying_graph(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create([
            'expires_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/*' => Http::response(['value' => []]),
        ]);

        app(MicrosoftGraphClient::class)->fetchNewMessages($connection);

        $this->assertSame('new-access-token', $connection->refresh()->access_token);
        $this->assertSame('new-refresh-token', $connection->refresh()->refresh_token);
    }
}
