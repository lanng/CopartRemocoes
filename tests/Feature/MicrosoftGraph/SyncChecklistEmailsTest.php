<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Enums\RegisterStatusEnum;
use App\Jobs\ProcessRemovalRequestEmail;
use App\Jobs\SyncChecklistEmails;
use App\Models\IntegrationInboxItem;
use App\Models\MicrosoftGraphConnection;
use App\Models\Register;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestImporter;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfPreparer;
use App\Services\MicrosoftGraph\SyncChecklistEmailsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SyncChecklistEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_processes_messages_before_persisting_the_utc_checkpoint(): void
    {
        Carbon::setTestNow('2026-08-13 20:55:00');
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => RegisterStatusEnum::INVOICED,
        ]);
        $connection = MicrosoftGraphConnection::factory()->create();

        Http::fake([
            'https://graph.microsoft.com/*' => Http::response([
                'value' => [[
                    'id' => 'message-1',
                    'subject' => 'Checklist digital - 1146609',
                    'sender' => ['emailAddress' => ['address' => 'remocao@copart.com.br']],
                    'receivedDateTime' => '2026-08-06T14:51:36-03:00',
                    'body' => ['content' => 'Veículo 1146609 - ESN4A20.', 'contentType' => 'html'],
                ]],
            ]),
        ]);

        $this->app->call([new SyncChecklistEmails, 'handle']);

        $this->assertSame(RegisterStatusEnum::DELIVERED, $register->refresh()->status);
        $this->assertNull($connection->refresh()->delta_link);
        $this->assertSame('2026-08-13 20:55:00', $connection->last_synced_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_it_preserves_the_checkpoint_and_records_the_error_when_graph_fails(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create([
            'last_synced_at' => '2026-08-13 20:50:00',
        ]);
        Http::fake([
            'https://graph.microsoft.com/*' => Http::failedConnection('Graph indisponível'),
        ]);

        try {
            $this->app->call([new SyncChecklistEmails, 'handle']);
            $this->fail('A falha do Graph deveria ser propagada.');
        } catch (\Throwable) {
        }

        $this->assertSame('2026-08-13 20:50:00', $connection->refresh()->last_synced_at->utc()->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('Graph indisponível', (string) $connection->last_error);
    }

    public function test_it_routes_a_mixed_page_without_downloading_attachments_and_keeps_checkpoint_rules(): void
    {
        Carbon::setTestNow('2026-08-13 20:55:00');
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => RegisterStatusEnum::INVOICED,
        ]);
        $connection = MicrosoftGraphConnection::factory()->create();
        Queue::fake();
        Http::fake([
            'https://graph.microsoft.com/*' => Http::response([
                'value' => [
                    [
                        'id' => 'checklist-message',
                        'subject' => 'Checklist digital - 1146609',
                        'sender' => ['emailAddress' => ['address' => 'remocao@copart.com.br']],
                        'receivedDateTime' => '2026-08-13T20:52:00Z',
                        'body' => ['content' => 'Veículo 1146609 - ESN4A20.', 'contentType' => 'text'],
                        'hasAttachments' => true,
                    ],
                    [
                        'id' => 'removal-message',
                        'subject' => 'Pedido de Remoção - ESN4A20 - 1146609 - ALLIANZ SEGUROS S/A',
                        'sender' => ['emailAddress' => ['address' => 'REMocao@copart.com.br']],
                        'receivedDateTime' => '2026-08-13T20:53:00Z',
                        'body' => ['content' => 'Corpo inválido para revisão.', 'contentType' => 'text'],
                        'hasAttachments' => true,
                    ],
                ],
            ]),
        ]);

        $result = app(SyncChecklistEmailsService::class)->handle();

        $this->assertSame(['processed' => 2, 'ignored' => 0], $result);
        $this->assertSame(RegisterStatusEnum::DELIVERED, $register->refresh()->status);
        $this->assertSame('2026-08-13 20:55:00', $connection->refresh()->last_synced_at->utc()->format('Y-m-d H:i:s'));
        Queue::assertPushed(ProcessRemovalRequestEmail::class);
        Http::assertSentCount(1);
    }

    public function test_it_completes_the_mixed_page_cycle_and_processes_only_the_original_removal_request(): void
    {
        Carbon::setTestNow('2026-08-13 20:55:00');
        Storage::fake('s3');
        Process::fake(fn () => Process::result($this->removalPdfText()));
        Queue::fake();

        $checklistRegister = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => RegisterStatusEnum::INVOICED,
        ]);
        $connection = MicrosoftGraphConnection::factory()->create();

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages*' => Http::response([
                'value' => [
                    [
                        'id' => 'checklist-message',
                        'subject' => 'Checklist digital - 1146609',
                        'sender' => ['emailAddress' => ['address' => 'remocao@copart.com.br']],
                        'receivedDateTime' => '2026-08-13T20:51:00Z',
                        'body' => ['content' => 'Veículo 1146609 - ESN4A20.', 'contentType' => 'text'],
                        'hasAttachments' => false,
                    ],
                    [
                        'id' => 'removal-message',
                        'subject' => 'Pedido de Remoção - ABC1D23 - 1156340 - ALLIANZ SEGUROS S/A',
                        'sender' => ['emailAddress' => ['address' => 'remocao@copart.com.br']],
                        'receivedDateTime' => '2026-08-13T20:52:00Z',
                        'body' => ['content' => $this->removalRequestBody(), 'contentType' => 'text'],
                        'hasAttachments' => true,
                    ],
                    [
                        'id' => 'reply-message',
                        'subject' => 'RE: Pedido de Remoção - ABC1D23 - 1156340 - ALLIANZ SEGUROS S/A',
                        'sender' => ['emailAddress' => ['address' => 'remocao@copart.com.br']],
                        'receivedDateTime' => '2026-08-13T20:53:00Z',
                        'body' => ['content' => $this->removalRequestBody(), 'contentType' => 'text'],
                        'hasAttachments' => true,
                    ],
                    [
                        'id' => 'irrelevant-message',
                        'subject' => 'Pagamento recebido',
                        'sender' => ['emailAddress' => ['address' => 'finance@example.com']],
                        'receivedDateTime' => '2026-08-13T20:54:00Z',
                        'body' => ['content' => 'Mensagem irrelevante.', 'contentType' => 'text'],
                        'hasAttachments' => true,
                    ],
                ],
            ]),
            'https://graph.microsoft.com/v1.0/me/messages/removal-message/attachments' => Http::response([
                'value' => [[
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'id' => 'attachment-id',
                    'name' => 'CartaDeRemoção.pdf',
                    'contentType' => 'application/pdf',
                    'size' => 12,
                    'isInline' => false,
                ]],
            ]),
            'https://graph.microsoft.com/v1.0/me/messages/removal-message/attachments/attachment-id/$value' => Http::response('%PDF-e2e-test'),
        ]);

        $result = app(SyncChecklistEmailsService::class)->handle();

        $this->assertSame(['processed' => 2, 'ignored' => 2], $result);
        $this->assertSame(RegisterStatusEnum::DELIVERED, $checklistRegister->refresh()->status);
        $this->assertSame('2026-08-13 20:55:00', $connection->refresh()->last_synced_at->utc()->format('Y-m-d H:i:s'));
        $this->assertDatabaseHas('integration_inbox_items', [
            'external_id' => 'removal-message',
            'status' => 'queued',
        ]);
        $this->assertDatabaseMissing('integration_inbox_items', ['external_id' => 'reply-message']);
        $this->assertDatabaseMissing('integration_inbox_items', ['external_id' => 'irrelevant-message']);

        $removalJob = null;
        Queue::assertPushed(ProcessRemovalRequestEmail::class, function (ProcessRemovalRequestEmail $job) use (&$removalJob): bool {
            $removalJob = $job;

            return true;
        });
        $this->assertInstanceOf(ProcessRemovalRequestEmail::class, $removalJob);

        $removalJob->handle(
            app(RemovalRequestPdfPreparer::class),
            app(RemovalRequestImporter::class),
        );

        $item = IntegrationInboxItem::query()->where('external_id', 'removal-message')->firstOrFail();
        $this->assertSame('processed', $item->status);
        $this->assertSame('ABC1D23', $item->refresh()->extracted_vehicle_plate);
        $this->assertCount(2, Register::query()->get());
        Http::assertSentCount(4);
        Http::assertSent(function ($request): bool {
            return str_ends_with($request->url(), '/attachments/attachment-id/$value');
        });
    }

    public function test_the_job_has_bounded_runtime_and_prevents_overlapping_syncs(): void
    {
        $job = new SyncChecklistEmails;

        $this->assertSame(1, $job->tries);
        $this->assertSame(45, $job->timeout);
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }

    private function removalRequestBody(): string
    {
        $body = file_get_contents(base_path('tests/Fixtures/MicrosoftGraph/removal-request-body.txt'));
        $this->assertIsString($body);

        return $body;
    }

    private function removalPdfText(): string
    {
        $text = file_get_contents(base_path('tests/Fixtures/Pdf/carta-de-remocao.txt'));
        $this->assertIsString($text);

        return $text;
    }
}
