<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CteAgentSettings;
use App\Models\CteAgent;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CteAgentSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    public function test_the_agent_settings_page_starts_with_no_agent(): void
    {
        Livewire::test(CteAgentSettings::class)
            ->assertStatus(200)
            ->assertSee('Agente CT-e')
            ->assertSee('Salvar configuração')
            ->assertSee('Gerar novo token')
            ->assertSee('Não configurado');

        $this->assertDatabaseCount('cte_agents', 0);
    }

    public function test_the_agent_configuration_can_be_created_and_updated_without_duplicates(): void
    {
        $component = Livewire::test(CteAgentSettings::class)
            ->fillForm([
                'name' => 'Agente da host',
                'hostname' => 'HOST-01',
                'is_dry_run' => false,
                'is_active' => true,
            ])
            ->call('save')
            ->assertNotified('Configuração do agente salva.');

        $agent = CteAgent::query()->firstOrFail();

        $this->assertSame('Agente da host', $agent->name);
        $this->assertSame('HOST-01', $agent->hostname);
        $this->assertFalse($agent->is_dry_run);
        $this->assertTrue($agent->is_active);

        $component
            ->fillForm([
                'name' => 'Agente da host atualizado',
                'hostname' => 'HOST-02',
                'is_dry_run' => true,
                'is_active' => false,
            ])
            ->call('save')
            ->assertNotified('Configuração do agente salva.');

        $this->assertDatabaseCount('cte_agents', 1);
        $this->assertDatabaseHas('cte_agents', [
            'id' => $agent->id,
            'name' => 'Agente da host atualizado',
            'hostname' => 'HOST-02',
            'is_dry_run' => true,
            'is_active' => false,
        ]);
    }

    public function test_generating_a_token_revokes_the_previous_token(): void
    {
        $agent = CteAgent::factory()->create();
        $component = Livewire::test(CteAgentSettings::class);

        $component->callAction('generateToken');

        $firstToken = $component->instance()->generatedToken;
        $this->assertIsString($firstToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => CteAgent::class,
            'tokenable_id' => $agent->id,
            'name' => 'cte-agent-host',
        ]);
        $this->assertSame(['cte-agent'], $agent->tokens()->firstOrFail()->abilities);

        $component->callAction('generateToken');

        $secondToken = $component->instance()->generatedToken;
        $this->assertIsString($secondToken);
        $this->assertNotSame($firstToken, $secondToken);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $heartbeatPayload = [
            'api_version' => '1',
            'agent_version' => '1.0.0',
            'hostname' => 'HOST-01',
            'dry_run' => true,
            'capabilities' => [],
        ];

        auth()->logout();

        $this->withToken($firstToken)
            ->postJson('/api/v1/cte-agent/heartbeat', $heartbeatPayload)
            ->assertUnauthorized();

        $this->withToken($secondToken)
            ->postJson('/api/v1/cte-agent/heartbeat', $heartbeatPayload)
            ->assertOk();
    }

    public function test_an_inactive_agent_cannot_use_the_generated_token(): void
    {
        $agent = CteAgent::factory()->inactive()->create();
        $component = Livewire::test(CteAgentSettings::class)
            ->callAction('generateToken');
        $token = $component->instance()->generatedToken;

        $this->assertIsString($token);

        auth()->logout();

        $this->withToken($token)
            ->postJson('/api/v1/cte-agent/heartbeat', [
                'api_version' => '1',
                'agent_version' => '1.0.0',
                'hostname' => 'HOST-01',
                'dry_run' => true,
                'capabilities' => [],
            ])
            ->assertForbidden();
    }
}
