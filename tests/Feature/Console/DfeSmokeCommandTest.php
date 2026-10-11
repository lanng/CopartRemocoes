<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\DfeSoapResponses;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class DfeSmokeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected string $baseUrl = 'https://sefaz-hml.test/CTeWS/WS';

    protected function setUp(): void
    {
        parent::setUp();

        $certificate = DfeTestCertificate::create();

        config([
            'dfe.tp_amb' => '2',
            'dfe.base_url' => $this->baseUrl,
            'dfe.cert_path' => $certificate['path'],
            'dfe.cert_password' => $certificate['password'],
            'dfe.ca_bundle' => '/caminho/inexistente/sefaz-ca.pem',
            'dfe.log_channel' => 'null',
            'dfe.xsd_path' => resource_path('xsds/cte'),
        ]);
    }

    public function test_runs_the_full_cycle_and_gets_the_cte_authorized(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $this->artisan('dfe:smoke')
            ->expectsOutputToContain('AUTORIZADO')
            ->assertExitCode(0);

        // A numeração atômica da homologação foi consumida pelo smoke.
        $this->assertDatabaseHas('cte_number_sequences', [
            'cnpj' => '12563112000130',
            'serie' => 1,
            'next_number' => 900000002,
        ]);
    }

    public function test_runs_the_tank_line_snapshot(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        $this->artisan('dfe:smoke', ['--line' => 'tank_alcohol'])
            ->expectsOutputToContain('AUTORIZADO')
            ->assertExitCode(0);
    }

    public function test_refuses_to_transmit_in_production_without_force(): void
    {
        config(['dfe.tp_amb' => '1']);

        $this->artisan('dfe:smoke')
            ->expectsOutputToContain('--force')
            ->assertExitCode(1);
    }

    public function test_fails_when_sefaz_is_offline(): void
    {
        Http::fake([
            $this->baseUrl.'/CTeStatusServicoV4.asmx' => Http::response(
                DfeSoapResponses::statusEnvelope('106', 'Servico nao em Operacao'),
                200,
            ),
        ]);

        $this->artisan('dfe:smoke')
            ->expectsOutputToContain('106')
            ->assertExitCode(1);
    }

    public function test_reports_a_sefaz_rejection_as_failure(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl, cStat: '225', xMotivo: 'Rejeicao: Duplicidade de CT-e');

        $this->artisan('dfe:smoke')
            ->expectsOutputToContain('225')
            ->assertExitCode(1);
    }

    public function test_persists_the_cte_proc_and_the_dacte_locally_on_demand(): void
    {
        DfeSoapResponses::fakeOnline($this->baseUrl);
        DfeSoapResponses::fakeTransmission($this->baseUrl);

        config([
            'filesystems.disks.dfe.driver' => 'local',
            'filesystems.disks.dfe.root' => storage_path('app/dfe-smoke'),
            'filesystems.disks.dfe.throw' => true,
        ]);

        Storage::fake('dfe');

        $this->artisan('dfe:smoke', ['--persist' => true])->assertExitCode(0);

        $files = Storage::disk('dfe')->allFiles();

        $this->assertCount(2, $files);

        $xml = collect($files)->first(fn ($file): bool => str_contains($file, 'cte-proc.xml'));
        $pdf = collect($files)->first(fn ($file): bool => str_contains($file, 'dacte'));

        $this->assertNotNull($xml);
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('<?xml', (string) Storage::disk('dfe')->get($xml));
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get($pdf));
    }
}
