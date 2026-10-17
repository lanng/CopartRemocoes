<?php

namespace Tests\Feature\Console;

use App\Models\IntegrationInboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CleanupIntegrationInboxItemsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_only_resolved_items_older_than_thirty_days(): void
    {
        $processed = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'delivery_alert' => 'missing_authorized_cte',
            'resolved_at' => Carbon::now()->subDays(31),
            'acknowledged_at' => Carbon::now()->subDays(31),
        ]);
        $unacknowledgedAlert = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'delivery_alert' => 'unexpected_status',
            'resolved_at' => Carbon::now()->subDays(31),
        ]);
        $duplicate = IntegrationInboxItem::factory()->create([
            'status' => 'duplicate',
            'resolved_at' => Carbon::now()->subDays(31),
        ]);
        $pending = IntegrationInboxItem::factory()->create([
            'status' => 'pending',
            'updated_at' => Carbon::now()->subDays(31),
        ]);
        $recent = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'resolved_at' => Carbon::now()->subDays(29),
        ]);

        $this->artisan('app:cleanup-integration-inbox-items')
            ->assertSuccessful();

        $this->assertModelMissing($processed);
        $this->assertModelMissing($duplicate);
        $this->assertModelExists($unacknowledgedAlert);
        $this->assertModelExists($pending);
        $this->assertModelExists($recent);
    }

    public function test_it_uses_updated_at_when_a_resolved_item_has_no_resolution_timestamp(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'resolved_at' => null,
            'updated_at' => Carbon::now()->subDays(31),
        ]);

        $this->artisan('app:cleanup-integration-inbox-items')
            ->assertSuccessful();

        $this->assertModelMissing($item);
    }

    public function test_it_keeps_an_old_delivery_alert_until_it_is_acknowledged(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'status' => 'processed',
            'delivery_alert' => 'missing_authorized_cte',
            'resolved_at' => Carbon::now()->subDays(31),
            'acknowledged_at' => null,
        ]);

        $this->artisan('app:cleanup-integration-inbox-items')
            ->assertSuccessful();

        $this->assertModelExists($item);
    }

    public function test_it_retains_canonical_cte_errors_for_deduplication_but_deletes_old_duplicates(): void
    {
        $original = IntegrationInboxItem::factory()->create([
            'message_type' => 'cte_error',
            'status' => 'processed',
            'resolved_at' => Carbon::now()->subDays(31),
            'extracted_data' => ['cte_document_id' => 1, 'error_fingerprint' => 'fingerprint'],
        ]);
        $duplicate = IntegrationInboxItem::factory()->create([
            'message_type' => 'cte_error',
            'status' => 'duplicate',
            'resolved_at' => Carbon::now()->subDays(31),
            'extracted_data' => ['duplicate_of' => $original->id],
        ]);

        $this->artisan('app:cleanup-integration-inbox-items')->assertSuccessful();

        $this->assertModelExists($original);
        $this->assertModelMissing($duplicate);
    }
}
