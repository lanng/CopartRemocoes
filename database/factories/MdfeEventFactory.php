<?php

namespace Database\Factories;

use App\Models\MdfeDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MdfeEvent>
 */
class MdfeEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $accessKey = fake()->numerify(str_repeat('#', 44));

        return [
            'mdfe_document_id' => MdfeDocument::factory(),
            'event_type' => '110112',
            'sequence' => 1,
            'attempt' => 1,
            'status' => 'queued',
            'event_id' => 'ID110112'.$accessKey.'01',
        ];
    }
}
