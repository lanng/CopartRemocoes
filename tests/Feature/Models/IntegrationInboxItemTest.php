<?php

namespace Tests\Feature\Models;

use App\Models\IntegrationInboxItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntegrationInboxItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_external_message_id_is_unique_per_source(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'source' => 'microsoft_graph',
            'external_id' => 'message-1',
        ]);

        $this->assertSame('microsoft_graph', $item->source);
        $this->assertSame('message-1', $item->external_id);
    }
}
