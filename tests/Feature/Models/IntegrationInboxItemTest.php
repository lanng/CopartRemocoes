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

    public function test_delivery_alert_snapshot_fields_are_persisted_and_reloaded(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'previous_register_status' => 'delivered',
            'delivery_alert' => 'missing_authorized_cte',
            'authorized_cte_number_at_delivery' => '35260812345678901234550010000000011000000010',
        ]);

        $reloadedItem = IntegrationInboxItem::query()->findOrFail($item->id);

        $this->assertSame('delivered', $reloadedItem->previous_register_status);
        $this->assertSame('missing_authorized_cte', $reloadedItem->delivery_alert);
        $this->assertSame('35260812345678901234550010000000011000000010', $reloadedItem->authorized_cte_number_at_delivery);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('knownDeliveryAlerts')]
    public function test_known_delivery_alerts_have_expected_labels_and_colors(string $deliveryAlert, string $expectedLabel, string $expectedColor): void
    {
        $item = new IntegrationInboxItem(['delivery_alert' => $deliveryAlert]);

        $this->assertTrue($item->hasDeliveryAlert());
        $this->assertSame($expectedLabel, $item->deliveryAlertLabel());
        $this->assertSame($expectedColor, $item->deliveryAlertColor());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function knownDeliveryAlerts(): array
    {
        return [
            'unexpected status' => ['unexpected_status', 'Fluxo inesperado', 'warning'],
            'missing authorized cte' => ['missing_authorized_cte', 'Entrega sem CT-e', 'danger'],
        ];
    }

    public function test_null_delivery_alert_has_no_alert_label_and_gray_color(): void
    {
        $item = new IntegrationInboxItem(['delivery_alert' => null]);

        $this->assertFalse($item->hasDeliveryAlert());
        $this->assertNull($item->deliveryAlertLabel());
        $this->assertSame('gray', $item->deliveryAlertColor());
    }

    public function test_unknown_delivery_alert_keeps_its_value_as_label_and_uses_gray_color(): void
    {
        $item = new IntegrationInboxItem(['delivery_alert' => 'future_alert']);

        $this->assertTrue($item->hasDeliveryAlert());
        $this->assertSame('future_alert', $item->deliveryAlertLabel());
        $this->assertSame('gray', $item->deliveryAlertColor());
    }
}
