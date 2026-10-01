<?php

namespace Tests\Unit\Services\Dfe;

use App\Services\Dfe\AccessKey;
use Tests\TestCase;

class AccessKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['dfe.cuf' => '35']);
    }

    public static function authorizedKeyProvider(): array
    {
        // Prefixos de 43 dígitos das chaves reais autorizadas (spec §2.2).
        return [
            'CT-e 2962 (veículos)' => ['3526091256311200013057001000002962132113581', '3'],
            'CT-e 2963 (tanque)' => ['3526091256311200013057001000002963100413795', '3'],
            'CT-e homologação (spike)' => ['3526091256311200013057001900000001129423618', '0'],
        ];
    }

    /**
     * Autoteste obrigatório da spec §2.2 contra chaves reais autorizadas.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('authorizedKeyProvider')]
    public function test_mod11_matches_the_authorized_gabarito_keys(string $prefix43, string $expectedDv): void
    {
        $this->assertSame($expectedDv, AccessKey::mod11($prefix43));
    }

    public function test_build_reproduces_the_real_authorized_key_2962(): void
    {
        $key = app(AccessKey::class)->build(
            cnpj: '12563112000130',
            aamm: '2609',
            serie: 1,
            number: 2962,
            cct: 32113581,
        );

        $this->assertSame(44, strlen($key));
        $this->assertSame('35260912563112000130570010000029621321135813', $key);
    }

    public function test_build_pads_components_and_computes_the_check_digit(): void
    {
        $key = app(AccessKey::class)->build(
            cnpj: '12.563.112/0001-30',
            aamm: '2610',
            serie: 7,
            number: 42,
            cct: 5,
        );

        // 35 2610 12563112000130 57 007 000000042 1 00000005 + DV
        $prefix = '3526101256311200013057007000000042100000005';

        $this->assertSame($prefix, substr($key, 0, 43));
        $this->assertSame(AccessKey::mod11($prefix), substr($key, -1));
    }
}
