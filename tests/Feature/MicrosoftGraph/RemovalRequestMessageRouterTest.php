<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Jobs\ProcessRemovalRequestEmail;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestMessageRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RemovalRequestMessageRouterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_queues_an_original_removal_request_once_and_stores_only_extracted_data(): void
    {
        Queue::fake();
        $message = $this->removalRequestMessage();
        $message['body'] = 'Código do transporte: T123; Remetente: Comitente ALLIANZ SEGUROS S/A; Pátio destino: São Paulo - SP; Valor da FIPE: R$ 10.000,00; Valor frete: R$ 500,00; Data para retirar o veículo da oficina: 20/08/2026; Data limite de entrega no pátio: 22/08/2026; Código do veículo: 1146609';

        $router = app(RemovalRequestMessageRouter::class);
        $item = $router->handle($message);
        $duplicate = $router->handle($message);

        $this->assertSame($item->id, $duplicate->id);
        $this->assertSame('removal_request', $item->message_type);
        $this->assertSame('queued', $item->status);
        $this->assertSame('1146609', $item->extracted_vehicle_id);
        $this->assertSame('ESN4A20', $item->extracted_vehicle_plate);
        $this->assertSame('2026-08-13 20:52:00', $item->received_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('ALLIANZ SEGUROS S/A', $item->extracted_data['subject']['insurance']);
        $this->assertSame('T123', $item->extracted_data['body']['payment_code']);
        $this->assertArrayNotHasKey('raw', $item->extracted_data['body']);
        $this->assertSame([], $item->extracted_data['body_missing_fields']);
        $this->assertDatabaseCount('integration_inbox_items', 1);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, 1);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, function (ProcessRemovalRequestEmail $job) use ($item): bool {
            return $job->integrationInboxItemId === $item->id;
        });
    }

    public function test_an_invalid_body_still_queues_the_request_with_missing_fields(): void
    {
        Queue::fake();

        $item = app(RemovalRequestMessageRouter::class)->handle($this->removalRequestMessage());

        $this->assertSame('queued', $item->status);
        $this->assertNotEmpty($item->extracted_data['body_missing_fields']);
        $this->assertSame([], $item->extracted_data['body']);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, 1);
    }

    public function test_replies_other_senders_and_irrelevant_subjects_are_ignored(): void
    {
        Queue::fake();
        $router = app(RemovalRequestMessageRouter::class);

        foreach (['RE: ', 'RES: ', 'FW: ', 'ENC: '] as $prefix) {
            $message = $this->removalRequestMessage();
            $message['external_id'] = 'reply-'.trim($prefix, ': ');
            $message['subject'] = $prefix.$message['subject'];

            $this->assertNull($router->handle($message));
        }

        $message = $this->removalRequestMessage();
        $message['external_id'] = 'other-sender';
        $message['sender'] = 'other@example.com';
        $this->assertNull($router->handle($message));

        $message = $this->removalRequestMessage();
        $message['external_id'] = 'irrelevant';
        $message['subject'] = 'Pagamento recebido';
        $this->assertNull($router->handle($message));

        $this->assertDatabaseCount('integration_inbox_items', 0);
        Queue::assertNothingPushed();
    }

    public function test_checklist_messages_are_still_delegated_to_the_existing_processor(): void
    {
        Queue::fake();
        $message = $this->removalRequestMessage();
        $message['subject'] = 'Checklist digital - 1146609';
        $message['body'] = 'Veículo 1146609 - ESN4A20.';

        $item = app(RemovalRequestMessageRouter::class)->handle($message);

        $this->assertSame('pending', $item?->status);
        $this->assertDatabaseHas('integration_inbox_items', [
            'external_id' => $message['external_id'],
            'message_type' => 'checklist',
        ]);
        Queue::assertNothingPushed();
    }

    /** @return array<string, mixed> */
    private function removalRequestMessage(): array
    {
        return [
            'external_id' => 'removal-message-1',
            'sender' => ' REMOCAO@COPART.COM.BR ',
            'subject' => 'Pedido de Remoção - ESN4A20 - 1146609 - ALLIANZ SEGUROS S/A',
            'body' => 'Corpo inválido para revisão.',
            'receivedDateTime' => '2026-08-13T17:52:00-03:00',
        ];
    }
}
