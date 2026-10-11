<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\BuildMdfeInsuranceSnapshot;
use DomainException;
use Tests\TestCase;

class BuildMdfeInsuranceSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dfe.tp_amb' => '2',
            'dfe.mdfe.insurer_name' => 'Seguradora de Teste',
            'dfe.mdfe.insurer_cnpj' => '11222333000181',
            'dfe.mdfe.policy_number' => 'APOLICE-REAL-001',
        ]);
    }

    public function test_builds_the_insurance_snapshot_with_operation_endorsements(): void
    {
        $snapshot = app(BuildMdfeInsuranceSnapshot::class)->handle(['AVERBACAO-OP-001']);

        $this->assertSame('Seguradora de Teste', $snapshot['insurer_name']);
        $this->assertSame('11222333000181', $snapshot['insurer_cnpj']);
        $this->assertSame('APOLICE-REAL-001', $snapshot['policy_number']);
        $this->assertSame(['AVERBACAO-OP-001'], $snapshot['endorsements']);
        $this->assertSame('configured', $snapshot['source']);
    }

    public function test_keeps_the_confirmed_operation_endorsement_list_instead_of_replacing_it_with_the_default(): void
    {
        config(['dfe.mdfe.default_endorsement' => 'AVERBACAO-PADRAO']);

        $snapshot = app(BuildMdfeInsuranceSnapshot::class)->handle([
            'AVERBACAO-OVERRIDE-001',
            'AVERBACAO-OVERRIDE-002',
        ]);

        $this->assertSame([
            'AVERBACAO-OVERRIDE-001',
            'AVERBACAO-OVERRIDE-002',
        ], $snapshot['endorsements']);

        config(['dfe.mdfe.default_endorsement' => 'AVERBACAO-NOVA']);

        $this->assertSame([
            'AVERBACAO-OVERRIDE-001',
            'AVERBACAO-OVERRIDE-002',
        ], $snapshot['endorsements']);
    }

    public function test_requires_at_least_one_operation_endorsement(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('nAver');

        app(BuildMdfeInsuranceSnapshot::class)->handle([]);
    }

    public function test_does_not_use_the_configured_default_when_no_operation_value_was_confirmed(): void
    {
        config(['dfe.mdfe.default_endorsement' => 'AVERBACAO-PADRAO']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('nAver');

        app(BuildMdfeInsuranceSnapshot::class)->handle([]);
    }

    public function test_accepts_an_endorsement_at_the_40_character_limit(): void
    {
        $endorsement = str_repeat('A', 40);

        $snapshot = app(BuildMdfeInsuranceSnapshot::class)->handle([$endorsement]);

        $this->assertSame([$endorsement], $snapshot['endorsements']);
    }

    public function test_rejects_an_endorsement_over_the_40_character_limit(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('excede 40 caracteres');

        app(BuildMdfeInsuranceSnapshot::class)->handle([str_repeat('A', 41)]);
    }

    public function test_requires_real_insurance_configuration_for_the_operational_snapshot(): void
    {
        config(['dfe.mdfe.insurer_cnpj' => null]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('DFE_MDFE_INSURER_CNPJ');

        app(BuildMdfeInsuranceSnapshot::class)->handle(['AVERBACAO-OP-001']);
    }

    public function test_accepts_explicit_synthetic_fixture_only_in_homologation(): void
    {
        $fixture = [
            'insurer_name' => 'Seguradora Fixture',
            'insurer_cnpj' => '11111111111111',
            'policy_number' => 'FIXTURE',
        ];

        $snapshot = app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE'],
            $fixture,
        );

        $this->assertSame($fixture['insurer_name'], $snapshot['insurer_name']);
        $this->assertSame($fixture['insurer_cnpj'], $snapshot['insurer_cnpj']);
        $this->assertSame(['AVERBACAO-FIXTURE'], $snapshot['endorsements']);
        $this->assertSame('homologation_fixture', $snapshot['source']);
    }

    public function test_rejects_synthetic_insurance_fixture_in_production(): void
    {
        config(['dfe.tp_amb' => '1']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('somente em homologação');

        app(BuildMdfeInsuranceSnapshot::class)->handle(
            ['AVERBACAO-FIXTURE'],
            [
                'insurer_name' => 'Seguradora Fixture',
                'insurer_cnpj' => '11111111111111',
                'policy_number' => 'FIXTURE',
            ],
        );
    }
}
