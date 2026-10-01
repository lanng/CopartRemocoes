<?php

namespace Tests\Unit\Support;

use App\Support\PtbrNumeric;
use Tests\TestCase;

class PtbrNumericTest extends TestCase
{
    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function normalizeProvider(): array
    {
        return [
            'formato brasileiro com milhar' => ['4.132,98', '4132.98'],
            'formato brasileiro sem milhar' => ['4132,98', '4132.98'],
            'decimal canônico legado' => ['4132.98', '4132.98'],
            'inteiro solto' => ['4132', '4132'],
            'milhar sem decimais' => ['1.500', '1500'],
            'milhar dupla' => ['1.234.567', '1234567'],
            'milhar e decimais' => ['1.234.567,89', '1234567.89'],
            'vírgula decimal curta' => ['1,5', '1.5'],
            'espaços e milhar' => [' 4.132,98 ', '4132.98'],
            'prefixo R$ colado de planilha' => ['R$ 4.132,98', '4132.98'],
            'prefixo R$ minúsculo' => ['r$ 100,50', '100.50'],
            'float de pré-preenchimento' => [716.4, '716.4'],
            'inteiro de pré-preenchimento' => [20000, '20000'],
            'zero com vírgula' => ['0,00', '0.00'],
            'negativo parseável (mínimo rejeita)' => ['-50', '-50'],
            'decimal iniciado em zero' => ['0.123', '0.123'],
            'separadores ambíguos' => ['4.132.98', null],
            'vírgula antes do ponto (formato US)' => ['4,132.98', null],
            'vírgulas de milhar (formato US)' => ['1,234,567', null],
            'grupo quebrado' => ['1.234.56', null],
            'texto qualquer' => ['abc', null],
            'string vazia' => ['', null],
            'nulo' => [null, null],
        ];
    }

    /**
     * @dataProvider normalizeProvider
     */
    public function test_it_normalizes_user_input(mixed $input, ?string $expected): void
    {
        $this->assertSame($expected, PtbrNumeric::normalize($input));
    }

    public function test_it_formats_for_display_in_brazilian_style(): void
    {
        $this->assertSame('4.132,98', PtbrNumeric::format('4132.98'));
        $this->assertSame('20.000,00', PtbrNumeric::format(20000));
        $this->assertNull(PtbrNumeric::format(null));
        $this->assertSame('716,40', PtbrNumeric::format('716.4'));
    }

    public function test_it_formats_with_a_custom_number_of_decimals(): void
    {
        $this->assertSame('4.132,9800', PtbrNumeric::format('4132.98', 4));
    }
}
