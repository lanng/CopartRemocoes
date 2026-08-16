<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\MicrosoftGraphSettings;
use App\Models\MicrosoftGraphConnection;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MicrosoftGraphSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_displays_the_last_sync_in_sao_paulo_time(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);
        MicrosoftGraphConnection::factory()->create([
            'last_synced_at' => '2026-08-13 20:50:00',
        ]);

        Livewire::test(MicrosoftGraphSettings::class)
            ->assertSee('Última sincronização: 13/08/2026 17:50');
    }
}
