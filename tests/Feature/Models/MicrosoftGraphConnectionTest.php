<?php

namespace Tests\Feature\Models;

use App\Models\MicrosoftGraphConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MicrosoftGraphConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_tokens_are_encrypted_in_the_model_cast(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create([
            'access_token' => 'access-token-secret',
            'refresh_token' => 'refresh-token-secret',
            'delta_link' => 'https://graph.microsoft.com/v1.0/delta-token',
        ]);

        $raw = $connection->getRawOriginal('access_token');

        $this->assertNotSame('access-token-secret', $raw);
        $this->assertSame('access-token-secret', $connection->refresh()->access_token);
        $this->assertSame('refresh-token-secret', $connection->refresh()->refresh_token);
    }
}
