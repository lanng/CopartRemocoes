<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Models\MicrosoftGraphConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MicrosoftGraphAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_user_is_redirected_to_microsoft_authorization(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/microsoft/graph/connect');

        $response->assertRedirect();
        $this->assertStringContainsString('login.microsoftonline.com/consumers/oauth2/v2.0/authorize', $response->headers->get('Location'));
    }

    public function test_callback_stores_encrypted_tokens_and_account_email(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/microsoft/graph/connect');
        $state = session('microsoft_graph_oauth_state');

        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token' => 'access-secret',
                'refresh_token' => 'refresh-secret',
                'expires_in' => 3600,
            ]),
            'https://graph.microsoft.com/v1.0/me' => Http::response([
                'mail' => 'victor@hotmail.com',
                'userPrincipalName' => 'victor@hotmail.com',
            ]),
        ]);

        $this->actingAs($user)
            ->get('/microsoft/graph/callback?code=test-code&state='.$state)
            ->assertRedirect('/admin');

        $connection = MicrosoftGraphConnection::query()->firstOrFail();
        $this->assertSame('victor@hotmail.com', $connection->account_email);
        $this->assertSame('access-secret', $connection->access_token);
        $this->assertSame('refresh-secret', $connection->refresh_token);
        $this->assertNotSame('access-secret', $connection->getRawOriginal('access_token'));
    }
}
