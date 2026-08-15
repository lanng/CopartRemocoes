<?php

namespace Database\Factories;

use App\Models\MicrosoftGraphConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\MicrosoftGraphConnection>
 */
class MicrosoftGraphConnectionFactory extends Factory
{
    protected $model = MicrosoftGraphConnection::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_email' => fake()->safeEmail(),
            'access_token' => fake()->sha256(),
            'refresh_token' => fake()->sha256(),
            'expires_at' => now()->addHour(),
            'delta_link' => null,
            'activated_at' => now(),
            'last_synced_at' => null,
            'last_error' => null,
            'is_active' => true,
        ];
    }
}
