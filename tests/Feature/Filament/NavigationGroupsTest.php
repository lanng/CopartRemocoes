<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\CteAgentSettings;
use App\Filament\Pages\MicrosoftGraphSettings;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\CiotResource;
use App\Filament\Resources\CiotVehicleResource;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CteEmissionBatchResource;
use App\Filament\Resources\CteEmissionResource;
use App\Filament\Resources\MdfeDocumentResource;
use App\Filament\Resources\MdfeDriverResource;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\TestCase;

class NavigationGroupsTest extends TestCase
{
    public function test_fiscal_navigation_items_are_grouped_under_emissions(): void
    {
        $this->assertSame('Emissões', CiotResource::getNavigationGroup());
        $this->assertSame('Emissões', MdfeDocumentResource::getNavigationGroup());
        $this->assertSame('Emissões', CteEmissionResource::getNavigationGroup());
        $this->assertSame('Emissões', CteEmissionBatchResource::getNavigationGroup());
        $this->assertSame('Emissões', CteAgentSettings::getNavigationGroup());
        $this->assertSame('CT-e', CteEmissionResource::getNavigationLabel());
    }

    public function test_emissions_items_follow_the_requested_order(): void
    {
        $items = collect([
            [CteAgentSettings::getNavigationSort(), CteAgentSettings::getNavigationLabel()],
            [CteEmissionBatchResource::getNavigationSort(), CteEmissionBatchResource::getNavigationLabel()],
            [CteEmissionResource::getNavigationSort(), CteEmissionResource::getNavigationLabel()],
            [MdfeDocumentResource::getNavigationSort(), MdfeDocumentResource::getNavigationLabel()],
            [CiotResource::getNavigationSort(), CiotResource::getNavigationLabel()],
        ])
            ->sortBy(fn (array $item): ?int => $item[0])
            ->pluck(1)
            ->values()
            ->all();

        $this->assertSame(['Agente CT-e', 'Lotes de CT-e', 'CT-e', 'Manifestos', 'CIOTs'], $items);
    }

    public function test_fleet_and_register_resources_use_the_cadastro_group(): void
    {
        $this->assertSame('Cadastro', CiotVehicleResource::getNavigationGroup());
        $this->assertSame('Cadastro', CompanyResource::getNavigationGroup());
        $this->assertSame('Cadastro', MdfeDriverResource::getNavigationGroup());
    }

    public function test_outlook_is_grouped_under_system(): void
    {
        $this->assertSame('System', MicrosoftGraphSettings::getNavigationGroup());
    }

    public function test_sidebar_groups_follow_the_requested_order(): void
    {
        $this->assertSame(
            ['Emissões', 'Financeiro', 'Cadastro', 'System'],
            Filament::getPanel('admin')->getNavigationGroups(),
        );
    }

    public function test_system_items_are_only_accessible_to_the_configured_admin_user(): void
    {
        config(['authuser.user' => 'admin@example.com']);

        $this->actingAs(User::factory()->make(['email' => 'other@example.com']));

        $this->assertFalse(MicrosoftGraphSettings::canAccess());
        $this->assertFalse(ActivityLogResource::canViewAny());

        $this->actingAs(User::factory()->make(['email' => 'admin@example.com']));

        $this->assertTrue(MicrosoftGraphSettings::canAccess());
        $this->assertTrue(ActivityLogResource::canViewAny());
    }
}
