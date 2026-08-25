<?php

namespace Tests\Unit\MicrosoftGraph;

use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemovalRequestNormalizerTest extends TestCase
{
    public function test_it_normalizes_plates_identifiers_and_text(): void
    {
        $normalizer = new RemovalRequestNormalizer;

        $this->assertSame('ABC1234', $normalizer->plate(' abc-1234 '));
        $this->assertNull($normalizer->plate('  '));
        $this->assertNull($normalizer->identifier("\t\u{00A0}"));
        $this->assertSame('Pirapora do Bom Jesus', $normalizer->text(" Pirapora\t\u{00A0}do\nBom Jesus "));
        $this->assertNull($normalizer->text(null));
    }

    public function test_it_normalizes_insurance_for_canonical_comparison(): void
    {
        $normalizer = new RemovalRequestNormalizer;

        $this->assertSame('ALLIANZ SEGUROS SA', $normalizer->insurance(' Allianz Seguros S/A '));
        $this->assertSame('SEGURADORA AGIL', $normalizer->insurance('Seguradora Ágil'));
        $this->assertNull($normalizer->insurance(' / '));
    }

    #[DataProvider('decimalProvider')]
    public function test_it_normalizes_brazilian_and_already_decimal_values(mixed $value, ?string $expected): void
    {
        $this->assertSame($expected, (new RemovalRequestNormalizer)->decimal($value));
    }

    /**
     * @return array<string, array{mixed, ?string}>
     */
    public static function decimalProvider(): array
    {
        return [
            'brazilian fipe' => ['56.739,00', '56739.00'],
            'brazilian freight' => ['R$ 866,48', '866.48'],
            'already decimal' => ['866.48', '866.48'],
            'one decimal place' => ['1234.5', '1234.50'],
            'integer' => [42, '42.00'],
            'blank' => ['  ', null],
            'null' => [null, null],
        ];
    }

    public function test_it_normalizes_supported_dates_and_rejects_invalid_dates(): void
    {
        $normalizer = new RemovalRequestNormalizer;

        $this->assertSame('2026-08-26', $normalizer->date('26/08/2026'));
        $this->assertSame('2026-09-03', $normalizer->date('2026-09-03'));
        $this->assertNull($normalizer->date('31/02/2026'));
        $this->assertNull($normalizer->date('not a date'));
    }

    #[DataProvider('equivalentProvider')]
    public function test_it_compares_values_using_the_field_normalization(string $field, mixed $left, mixed $right, bool $expected): void
    {
        $this->assertSame($expected, (new RemovalRequestNormalizer)->equivalent($field, $left, $right));
    }

    /**
     * @return array<string, array{string, mixed, mixed, bool}>
     */
    public static function equivalentProvider(): array
    {
        return [
            'plate formatting' => ['plate', 'ABC-1234', 'abc1234', true],
            'identifier whitespace' => ['vehicle_id', ' 1156340 ', '1156340', true],
            'insurance punctuation and case' => ['insurance', 'ALLIANZ SEGUROS S/A', 'Allianz Seguros SA', true],
            'different insurance' => ['insurance', 'ALLIANZ SEGUROS SA', 'TOKIO MARINE', false],
            'freight decimal formats' => ['value', '866,48', '866.48', true],
            'fipe decimal formats' => ['fipe_value', '56.739,00', '56739.00', true],
            'date formats' => ['deadline_withdraw', '26/08/2026', '2026-08-26', true],
            'text whitespace' => ['destination_city', 'Pirapora  ', "Pirapora\t", true],
            'text case is meaningful' => ['destination_city', 'Pirapora', 'PIRAPORA', false],
            'blank and null' => ['vehicle_id', '', null, true],
        ];
    }
}
