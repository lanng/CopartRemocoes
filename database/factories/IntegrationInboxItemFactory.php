<?php

namespace Database\Factories;

use App\Models\IntegrationInboxItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IntegrationInboxItem>
 */
class IntegrationInboxItemFactory extends Factory
{
    protected $model = IntegrationInboxItem::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source' => 'microsoft_graph',
            'message_type' => 'checklist',
            'external_id' => fake()->unique()->uuid(),
            'status' => 'pending',
            'sender' => 'remocao@copart.com.br',
            'subject' => 'Checklist digital - 1146609',
            'received_at' => now(),
            'extracted_vehicle_id' => '1146609',
            'extracted_vehicle_plate' => 'ESN4A20',
            'extracted_data' => null,
            'proposed_changes' => null,
            'alerts' => null,
            'candidate_pdf_path' => null,
            'candidate_pdf_sha256' => null,
            'register_id' => null,
            'previous_register_status' => null,
            'delivery_alert' => null,
            'authorized_cte_number_at_delivery' => null,
            'failure_reason' => null,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }
}
