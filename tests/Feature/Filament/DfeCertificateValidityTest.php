<?php

namespace Tests\Feature\Filament;

use App\Filament\Widgets\DfeCertificateValidity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class DfeCertificateValidityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    public function test_shows_the_days_until_expiry_from_the_real_certificate(): void
    {
        $certificate = DfeTestCertificate::create();

        config(['dfe.cert_path' => $certificate['path'], 'dfe.cert_password' => $certificate['password']]);

        Livewire::test(DfeCertificateValidity::class)
            ->assertSee('Certificado A1 (DFe)')
            ->assertSee('COPART-DFE-TEST');
    }

    public function test_warns_when_the_certificate_is_not_configured(): void
    {
        config(['dfe.cert_path' => '/caminho/inexistente.p12', 'dfe.cert_password' => 'x']);

        Livewire::test(DfeCertificateValidity::class)
            ->assertSee('Não configurado');
    }
}
