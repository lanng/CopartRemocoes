<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Jobs\ProcessRemovalRequestEmail;
use App\Models\IntegrationInboxItem;
use App\Services\MicrosoftGraph\RemovalRequests\QueueRemovalRequestEmail;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestMessageRouter;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_it_dispatches_only_after_the_outer_transaction_commits(): void
    {
        Queue::fake();
        $message = $this->removalRequestMessage();
        $itemId = null;

        DB::transaction(function () use ($message, &$itemId): void {
            $itemId = app(RemovalRequestMessageRouter::class)->handle($message)->id;

            $this->assertDatabaseCount('integration_inbox_items', 1);
            Queue::assertNothingPushed();
        });

        $this->assertDatabaseCount('integration_inbox_items', 1);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, 1);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, function (ProcessRemovalRequestEmail $job) use ($itemId): bool {
            return $job->integrationInboxItemId === $itemId;
        });
    }

    public function test_it_does_not_persist_or_dispatch_when_the_outer_transaction_rolls_back(): void
    {
        Queue::fake();
        $message = $this->removalRequestMessage();

        try {
            DB::transaction(function () use ($message): void {
                app(RemovalRequestMessageRouter::class)->handle($message);

                throw new \RuntimeException('rollback removal request');
            });
            $this->fail('The transaction should have rolled back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback removal request', $exception->getMessage());
        }

        $this->assertDatabaseCount('integration_inbox_items', 0);
        Queue::assertNothingPushed();
    }

    public function test_it_recovers_dispatch_for_an_existing_queued_item(): void
    {
        Queue::fake();
        Cache::flush();
        $message = $this->removalRequestMessage();
        IntegrationInboxItem::factory()->create([
            'source' => 'microsoft_graph',
            'external_id' => $message['external_id'],
            'message_type' => 'removal_request',
            'status' => 'queued',
            'extracted_data' => [
                'subject' => [],
                'body' => [],
                'body_missing_fields' => [],
            ],
        ]);

        $item = app(RemovalRequestMessageRouter::class)->handle($message);

        $this->assertSame('queued', $item->status);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, 1);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, function (ProcessRemovalRequestEmail $job) use ($item): bool {
            return $job->uniqueId() === (string) $item->id;
        });
    }

    public function test_it_recovers_dispatch_for_an_existing_processing_item(): void
    {
        Queue::fake();
        Cache::flush();
        $message = $this->removalRequestMessage();
        $item = IntegrationInboxItem::factory()->create([
            'source' => 'microsoft_graph',
            'external_id' => $message['external_id'],
            'message_type' => 'removal_request',
            'status' => 'processing',
        ]);

        $result = app(RemovalRequestMessageRouter::class)->handle($message);

        $this->assertSame($item->id, $result->id);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, 1);
    }

    #[DataProvider('terminalStatusProvider')]
    public function test_it_does_not_recover_dispatch_for_terminal_items(string $status): void
    {
        Queue::fake();
        Cache::flush();
        $message = $this->removalRequestMessage();
        IntegrationInboxItem::factory()->create([
            'source' => 'microsoft_graph',
            'external_id' => $message['external_id'],
            'message_type' => 'removal_request',
            'status' => $status,
        ]);

        app(RemovalRequestMessageRouter::class)->handle($message);

        Queue::assertNothingPushed();
    }

    public static function terminalStatusProvider(): array
    {
        return [
            'processed' => ['processed'],
            'no changes' => ['no_changes'],
            'alert' => ['alert'],
            'rejected' => ['rejected'],
        ];
    }

    public function test_it_retries_dispatch_when_the_first_after_commit_dispatch_fails(): void
    {
        Queue::fake();
        Cache::flush();
        $realDispatcher = app(Dispatcher::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andReturnUsing(function (): never {
            throw new \RuntimeException('queue unavailable');
        });
        $this->app->instance(Dispatcher::class, $dispatcher);
        $message = $this->removalRequestMessage();
        $router = app(RemovalRequestMessageRouter::class);

        try {
            $router->handle($message);
            $this->fail('The first dispatch should fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('queue unavailable', $exception->getMessage());
        } finally {
            $this->app->instance(Dispatcher::class, $realDispatcher);
        }

        $item = $router->handle($message);

        $this->assertSame('queued', $item->status);
        $this->assertDatabaseCount('integration_inbox_items', 1);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, 1);
        $dispatcher->shouldHaveReceived('dispatch')->once();
    }

    public function test_unique_constraint_race_returns_existing_item_and_requeues_it(): void
    {
        Queue::fake();
        Cache::flush();
        $message = $this->removalRequestMessage();
        $existing = IntegrationInboxItem::factory()->create([
            'source' => 'microsoft_graph',
            'external_id' => $message['external_id'],
            'message_type' => 'removal_request',
            'status' => 'queued',
        ]);
        $service = $this->raceService(new UniqueConstraintViolationException(
            'sqlite',
            'insert into integration_inbox_items',
            [],
            new \RuntimeException('duplicate'),
        ));

        $item = $service->handle($message);

        $this->assertSame($existing->id, $item->id);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, 1);
    }

    public function test_non_unique_query_exception_is_not_treated_as_a_race(): void
    {
        Queue::fake();
        $message = $this->removalRequestMessage();
        IntegrationInboxItem::factory()->create([
            'source' => 'microsoft_graph',
            'external_id' => $message['external_id'],
            'message_type' => 'removal_request',
            'status' => 'queued',
        ]);
        $exception = new QueryException(
            'sqlite',
            'insert into integration_inbox_items',
            [],
            new \RuntimeException('constraint failure'),
        );
        $service = $this->raceService($exception);

        $this->expectException(QueryException::class);
        $service->handle($message);
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

    private function raceService(QueryException $exception): QueueRemovalRequestEmail
    {
        $subjectParser = app(\App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestSubjectParser::class);
        $bodyParser = app(\App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestBodyParser::class);
        $uniqueLock = app(UniqueLock::class);

        return new class($subjectParser, $bodyParser, $uniqueLock, $exception) extends QueueRemovalRequestEmail
        {
            private bool $firstLookup = true;

            public function __construct(
                $subjectParser,
                $bodyParser,
                $uniqueLock,
                private readonly QueryException $exception,
            ) {
                parent::__construct($subjectParser, $bodyParser, $uniqueLock);
            }

            protected function findItem(string $externalId): ?IntegrationInboxItem
            {
                if ($this->firstLookup) {
                    $this->firstLookup = false;

                    return null;
                }

                return IntegrationInboxItem::query()
                    ->where('source', 'microsoft_graph')
                    ->where('external_id', $externalId)
                    ->first();
            }

            protected function createItem(array $attributes): IntegrationInboxItem
            {
                throw $this->exception;
            }
        };
    }
}
