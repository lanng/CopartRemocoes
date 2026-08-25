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
        $this->assertSame('東京海上', $normalizer->insurance('東京海上'));
        $this->assertSame('ΑΣΦΑΛΙΣΤΙΚΉ', $normalizer->insurance('Ασφαλιστική'));
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
            'brazilian grouped amount' => ['1.234.567,8', '1234567.80'],
            'large string amount' => ['12345678901234567890.12', '12345678901234567890.12'],
            'integer' => [42, '42.00'],
            'float' => [1234.5, '1234.50'],
            'blank' => ['  ', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('invalidDecimalProvider')]
    public function test_it_rejects_ambiguous_or_malformed_string_decimals(string $value): void
    {
        $this->assertNull((new RemovalRequestNormalizer)->decimal($value));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDecimalProvider(): array
    {
        return [
            'three canonical decimal places' => ['0.001'],
            'three decimal places' => ['1234.567'],
            'ambiguous dotted grouping' => ['1.234'],
            'malformed brazilian grouping' => ['1.23.456,78'],
            'mixed international separators' => ['1,234.56'],
            'short brazilian grouping' => ['12.34,56'],
            'space grouping' => ['1 234,56'],
            'trailing comma' => ['866,'],
            'missing integer part' => ['R$ ,48'],
        ];
    }

    public function test_it_normalizes_supported_dates_and_rejects_invalid_dates(): void
    {
        $normalizer = new RemovalRequestNormalizer;

        $this->assertSame('2026-08-26', $normalizer->date('26/08/2026'));
        $this->assertSame('2026-09-03', $normalizer->date('2026-09-03'));
        $this->assertNull($normalizer->date('31/02/2026'));
        $this->assertNull($normalizer->date('1/2/2026'));
        $this->assertNull($normalizer->date('not a date'));
    }

    #[DataProvider('equivalentProvider')]
    public function test_it_compares_values_using_the_field_normalization(string $field, mixed $left, mixed $right, bool $expected): void
    {
        $this->assertSame($expected, (new RemovalRequestNormalizer)->equivalent($field, $left, $right));
    }

    public function test_it_rejects_unknown_equivalence_fields(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RemovalRequestNormalizer)->equivalent('unknown_field', 'a', 'a');
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
