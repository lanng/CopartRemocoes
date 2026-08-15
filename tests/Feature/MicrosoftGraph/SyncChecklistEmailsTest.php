<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Enums\RegisterStatusEnum;
use App\Jobs\SyncChecklistEmails;
use App\Models\MicrosoftGraphConnection;
use App\Models\Register;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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
            'status' => RegisterStatusEnum::COLLECTED,
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

    public function test_the_job_has_bounded_runtime_and_prevents_overlapping_syncs(): void
    {
        $job = new SyncChecklistEmails;

        $this->assertSame(1, $job->tries);
        $this->assertSame(45, $job->timeout);
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }
}
