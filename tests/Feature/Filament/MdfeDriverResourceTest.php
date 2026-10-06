<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\MdfeDriverResource\Pages\CreateMdfeDriver;
use App\Models\MdfeDriver;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MdfeDriverResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
    }

    public function test_creates_a_driver_with_fiscal_identity_and_emission_line(): void
    {
        Livewire::test(CreateMdfeDriver::class)
            ->fillForm([
                'name' => 'Marcio da Silva',
                'cpf' => '12345678909',
                'line' => 'vehicle_removal',
                'is_default' => true,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas(MdfeDriver::class, [
            'name' => 'Marcio da Silva',
            'cpf' => '12345678909',
            'line' => 'vehicle_removal',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_requires_an_eleven_digit_cpf(): void
    {
        Livewire::test(CreateMdfeDriver::class)
            ->fillForm([
                'name' => 'Motorista Incompleto',
                'cpf' => '1234567890',
                'line' => 'tank_alcohol',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasFormErrors(['cpf']);

        $this->assertDatabaseMissing(MdfeDriver::class, [
            'name' => 'Motorista Incompleto',
        ]);
    }

    public function test_only_one_active_vehicle_removal_driver_can_be_fixed(): void
    {
        $firstDriver = MdfeDriver::factory()->create([
            'line' => 'vehicle_removal',
            'is_default' => true,
        ]);

        MdfeDriver::factory()->create([
            'line' => 'vehicle_removal',
            'is_default' => true,
        ]);

        $this->assertFalse($firstDriver->refresh()->is_default);
        $this->assertSame(1, MdfeDriver::query()->where('is_default', true)->count());
    }
}
