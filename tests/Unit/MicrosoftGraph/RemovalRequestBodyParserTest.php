<?php

namespace Tests\Unit\MicrosoftGraph;

use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestBodyParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RemovalRequestBodyParserTest extends TestCase
{
    public function test_it_extracts_and_normalizes_all_fields_from_the_fixture(): void
    {
        $body = file_get_contents(__DIR__.'/../../Fixtures/MicrosoftGraph/removal-request-body.txt');
        $this->assertIsString($body);

        $result = (new RemovalRequestBodyParser)->parse($body);

        $this->assertTrue($result['valid']);
        $this->assertSame([
            'payment_code' => 'T708269',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'destination_city' => 'Pirapora',
            'fipe_value' => '56739.00',
            'value' => '866.48',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'vehicle_id' => '1156340',
        ], $result['data']);
        $this->assertSame([], $result['missing_fields']);
    }

    public function test_it_tolerates_case_tabs_nbsp_and_line_breaks(): void
    {
        $body = "OUTRAS\u{00A0}CARACTERÍSTICAS DA CARGA:\tpreencher este código do transporte t708269\n"
            ."REMETENTE: dados\tdo COMITENTE allianz seguros s/a;\r\n"
            ."DESTINATÁRIO: dados do PÁTIO DE DESTINO Pirapora - sp;\n"
            ."VALOR DA FIPE:\tR$\u{00A0}56.739,00\n"
            ."VALOR FRETE R$\u{00A0}866,48\n"
            ."DATA PARA RETIRAR O VEÍCULO DA OFICINA\t26/08/2026\n"
            ."DATA LIMITE DE ENTREGA NO PÁTIO 03/09/2026\n"
            ."CÓDIGO VEÍCULO\t1156340";

        $result = (new RemovalRequestBodyParser)->parse($body);

        $this->assertTrue($result['valid']);
        $this->assertSame('T708269', $result['data']['payment_code']);
        $this->assertSame('ALLIANZ SEGUROS SA', $result['data']['insurance']);
        $this->assertSame('Pirapora', $result['data']['destination_city']);
        $this->assertSame('56739.00', $result['data']['fipe_value']);
        $this->assertSame('866.48', $result['data']['value']);
        $this->assertSame('2026-08-26', $result['data']['deadline_withdraw']);
        $this->assertSame('2026-09-03', $result['data']['deadline_delivery']);
        $this->assertSame('1156340', $result['data']['vehicle_id']);
    }

    #[DataProvider('requiredFieldProvider')]
    public function test_it_reports_each_missing_required_field_deterministically(string $field, string $line): void
    {
        $body = str_replace($line, '', $this->bodyWithAllFields());

        $result = (new RemovalRequestBodyParser)->parse($body);

        $this->assertFalse($result['valid']);
        $this->assertArrayNotHasKey($field, $result['data']);
        $this->assertSame([$field], $result['missing_fields']);
    }

    #[DataProvider('invalidDateProvider')]
    public function test_it_treats_invalid_dates_as_missing_without_throwing(string $field, string $line, string $replacement): void
    {
        $result = (new RemovalRequestBodyParser)->parse(
            str_replace($line, $replacement, $this->bodyWithAllFields())
        );

        $this->assertFalse($result['valid']);
        $this->assertSame([$field], $result['missing_fields']);
    }

    #[DataProvider('malformedMonetaryProvider')]
    public function test_it_rejects_malformed_monetary_values(string $field, string $line): void
    {
        $result = (new RemovalRequestBodyParser)->parse(
            str_replace($this->monetaryLineFor($field), $line, $this->bodyWithAllFields())
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayNotHasKey($field, $result['data']);
        $this->assertSame([$field], $result['missing_fields']);
    }

    #[DataProvider('invalidSuffixProvider')]
    public function test_it_rejects_fields_with_invalid_suffixes(string $field, string $line): void
    {
        $result = (new RemovalRequestBodyParser)->parse(
            str_replace($this->fieldLineFor($field), $line, $this->bodyWithAllFields())
        );

        $this->assertFalse($result['valid']);
        $this->assertArrayNotHasKey($field, $result['data']);
        $this->assertSame([$field], $result['missing_fields']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requiredFieldProvider(): array
    {
        return [
            'payment code' => ['payment_code', 'Outras características da carga: preencher apenas este código do transporte T708269;'],
            'insurance' => ['insurance', 'Remetente: dados do comitente ALLIANZ SEGUROS S/A;'],
            'destination city' => ['destination_city', 'Pátio destino Pirapora - SP;'],
            'fipe value' => ['fipe_value', 'Valor da FIPE: R$ 56.739,00;'],
            'value' => ['value', 'Valor frete R$ 866,48;'],
            'withdraw deadline' => ['deadline_withdraw', 'Data para retirar o veículo da oficina 26/08/2026'],
            'delivery deadline' => ['deadline_delivery', 'Data limite de entrega no pátio 03/09/2026'],
            'vehicle id' => ['vehicle_id', 'Código veículo 1156340'],
        ];
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function invalidDateProvider(): array
    {
        return [
            'withdraw invalid calendar date' => ['deadline_withdraw', 'Data para retirar o veículo da oficina 26/08/2026', 'Data para retirar o veículo da oficina 31/02/2026'],
            'delivery invalid calendar date' => ['deadline_delivery', 'Data limite de entrega no pátio 03/09/2026', 'Data limite de entrega no pátio 31/02/2026'],
            'withdraw single-digit date' => ['deadline_withdraw', 'Data para retirar o veículo da oficina 26/08/2026', 'Data para retirar o veículo da oficina 1/2/2026'],
            'delivery single-digit date' => ['deadline_delivery', 'Data limite de entrega no pátio 03/09/2026', 'Data limite de entrega no pátio 1/2/2026'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedMonetaryProvider(): array
    {
        return [
            'trailing comma' => ['value', 'Valor frete R$ 866,;'],
            'missing integer part' => ['fipe_value', 'Valor da FIPE: R$ ,48;'],
            'trailing dot' => ['value', 'Valor frete R$ 866.;'],
            'repeated separator' => ['fipe_value', 'Valor da FIPE: R$ 56..739,00;'],
            'junk after value' => ['value', 'Valor frete R$ 866.48abc;'],
            'text after value' => ['value', 'Valor frete R$ 866,48 inválido;'],
            'keyword text after value' => ['value', 'Valor frete R$ 866,48 data inválido;'],
            'date label text after value' => ['value', 'Valor frete R$ 866,48 data para retirar inválido;'],
            'vehicle label text after value' => ['value', 'Valor frete R$ 866,48 código veículo inválido;'],
            'negative freight' => ['value', 'Valor frete R$ -866,48;'],
            'negative fipe' => ['fipe_value', 'Valor da FIPE: R$ -56.739,00;'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidSuffixProvider(): array
    {
        return [
            'withdraw date suffix' => ['deadline_withdraw', 'Data para retirar o veículo da oficina 26/08/2026xyz'],
            'delivery date suffix' => ['deadline_delivery', 'Data limite de entrega no pátio 03/09/2026xyz'],
            'vehicle id suffix' => ['vehicle_id', 'Código veículo 1156340abc'],
        ];
    }

    private function monetaryLineFor(string $field): string
    {
        return $field === 'fipe_value'
            ? 'Valor da FIPE: R$ 56.739,00;'
            : 'Valor frete R$ 866,48;';
    }

    private function fieldLineFor(string $field): string
    {
        return match ($field) {
            'deadline_withdraw' => 'Data para retirar o veículo da oficina 26/08/2026',
            'deadline_delivery' => 'Data limite de entrega no pátio 03/09/2026',
            'vehicle_id' => 'Código veículo 1156340',
        };
    }

    private function bodyWithAllFields(): string
    {
        return implode("\n", [
            'Outras características da carga: preencher apenas este código do transporte T708269;',
            'Remetente: dados do comitente ALLIANZ SEGUROS S/A;',
            'Pátio destino Pirapora - SP;',
            'Valor da FIPE: R$ 56.739,00;',
            'Valor frete R$ 866,48;',
            'Data para retirar o veículo da oficina 26/08/2026',
            'Data limite de entrega no pátio 03/09/2026',
            'Código veículo 1156340',
        ]);
    }
}
