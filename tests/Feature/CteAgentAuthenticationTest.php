<?php

namespace Tests\Feature;

use App\Models\CteAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CteAgentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_active_agent_with_the_required_ability_can_send_a_heartbeat(): void
    {
        $agent = CteAgent::factory()->create();
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/cte-agent/heartbeat', [
            'api_version' => '1',
            'agent_version' => '1.0.0',
            'hostname' => 'TEST-PC',
            'dry_run' => true,
            'capabilities' => ['lab-ui', 'xml-read'],
        ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['server_time', 'poll_after_seconds']);

        $this->assertNotNull($agent->refresh()->last_seen_at);
    }

    public function test_an_unauthenticated_request_cannot_send_a_heartbeat(): void
    {
        $this->postJson('/api/v1/cte-agent/heartbeat')
            ->assertUnauthorized();
    }

    public function test_an_agent_without_the_required_ability_is_rejected(): void
    {
        $agent = CteAgent::factory()->create();
        $token = $agent->createToken('test-agent', ['other-ability'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/cte-agent/heartbeat')
            ->assertForbidden();
    }

    public function test_an_inactive_agent_is_rejected_even_with_a_valid_token(): void
    {
        $agent = CteAgent::factory()->inactive()->create();
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/cte-agent/heartbeat', [
                'api_version' => '1',
                'agent_version' => '1.0.0',
                'hostname' => 'TEST-PC',
                'dry_run' => true,
                'capabilities' => [],
            ])
            ->assertForbidden();
    }

    public function test_a_heartbeat_cannot_change_the_agents_execution_mode(): void
    {
        $agent = CteAgent::factory()->create(['is_dry_run' => false]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/cte-agent/heartbeat', [
                'api_version' => '1',
                'agent_version' => '1.0.0',
                'hostname' => 'TEST-PC',
                'dry_run' => true,
                'capabilities' => [],
            ])
            ->assertOk();

        $this->assertFalse($agent->refresh()->is_dry_run);
    }
}
