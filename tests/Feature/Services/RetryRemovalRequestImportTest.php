<?php

namespace Tests\Feature\Services;

use App\Jobs\ProcessRemovalRequestEmail;
use App\Models\IntegrationInboxItem;
use App\Services\MicrosoftGraph\RemovalRequests\RetryRemovalRequestImport;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryRemovalRequestImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_requeues_a_failed_removal_request_and_dispatches_its_job(): void
    {
        Queue::fake();
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'failure_reason' => 'domain_error',
            'resolved_at' => null,
        ]);

        $result = app(RetryRemovalRequestImport::class)->handle($item);

        $this->assertSame($item->id, $result->id);
        $this->assertSame('queued', $result->status);
        $this->assertNull($result->failure_reason);
        $this->assertNull($result->resolved_at);
        Queue::assertPushed(ProcessRemovalRequestEmail::class, fn (ProcessRemovalRequestEmail $job): bool => $job->integrationInboxItemId === $item->id);
    }

    public function test_it_rejects_checklists_and_terminal_removal_requests(): void
    {
        foreach ([
            ['message_type' => 'checklist', 'status' => 'pending'],
            ['message_type' => 'removal_request', 'status' => 'processed'],
        ] as $attributes) {
            $item = IntegrationInboxItem::factory()->create($attributes);

            $this->expectException(DomainException::class);
            app(RetryRemovalRequestImport::class)->handle($item);
        }
    }
}
