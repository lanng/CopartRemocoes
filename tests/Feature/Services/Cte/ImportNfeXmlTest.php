<?php

namespace Tests\Feature\Services\Cte;

use App\Services\Cte\ImportNfeXml;
use DomainException;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ImportNfeXmlTest extends TestCase
{
    public function test_imports_structured_fields_and_delivery_without_reading_free_text(): void
    {
        $path = $this->fixture('nfe-delivery-001.xml');
        $nfe = app(ImportNfeXml::class)->handle((string) file_get_contents($path), basename($path));

        $this->assertSame('35261011111111111111550010000000011000000011', $nfe['access_key']);
        $this->assertSame('1000.00', $nfe['cargo_value']);
        $this->assertSame('100.0000', $nfe['cargo_liters']);
        $this->assertSame('80.000', $nfe['net_weight_kg']);
        $this->assertSame('22222222222222', $nfe['recipient']['cnpj']);
        $this->assertSame('33333333333333', $nfe['delivery']['cnpj']);
        $this->assertSame('3506003', $nfe['delivery']['ibge']);
        $this->assertSame([], $nfe['delivery_missing_fields']);
        $this->assertNotEmpty($nfe['rem']['cnpj']);
        $this->assertNotEmpty($nfe['rem']['name']);
        $this->assertCount(1, $nfe['products']);
        $this->assertSame('ETANOL HIDRATADO COMBUSTIVEL - TESTE', $nfe['products'][0]['name']);
        $this->assertSame('100.0000', $nfe['products'][0]['liters']);
        $this->assertSame('1000.00', $nfe['products'][0]['value']);
        $this->assertSame('135260000000001', $nfe['protocol']);
    }

    public function test_retains_incomplete_delivery_data_for_operator_completion(): void
    {
        $path = $this->fixture('nfe-delivery-001.xml');
        $xml = (string) file_get_contents($path);
        $modified = preg_replace('/(<entrega.*?<xLgr>)[^<]*(<\/xLgr>)/s', '$1$2', $xml, 1);
        $nfe = app(ImportNfeXml::class)->handle((string) $modified, 'renamed-document.xml');

        $this->assertSame(['street'], $nfe['delivery_missing_fields']);
        $this->assertSame('renamed-document.xml', $nfe['source_filename']);
        $this->assertSame('3506003', $nfe['delivery']['ibge']);
        $this->assertSame(['street'], app(\App\Services\Cte\ConsolidateAlcoholNfes::class)
            ->handle([$nfe])['delivery_missing_fields']);
    }

    public function test_validates_and_preserves_a_cpf_party_identity(): void
    {
        $xml = str_replace(
            '<CNPJ>22222222222222</CNPJ>',
            '<CPF>12345678909</CPF>',
            (string) file_get_contents($this->fixture('nfe-delivery-001.xml')),
        );

        $nfe = app(ImportNfeXml::class)->handle($xml, 'cpf-recipient.xml');

        $this->assertSame('', $nfe['recipient']['cnpj']);
        $this->assertSame('12345678909', $nfe['recipient']['cpf']);
    }

    public function test_imports_all_reference_fixtures_using_protocol_and_xml_contents(): void
    {
        $fixtures = [
            ['nfe-recipient-001.xml', '35261011111111111111550010000000031000000032', '3000.00', '300.0000', '240.000', '135260000000003'],
            ['nfe-recipient-002.xml', '35261011111111111111550010000000041000000048', '4000.00', '400.0000', '320.000', '135260000000004'],
            ['nfe-delivery-001.xml', '35261011111111111111550010000000011000000011', '1000.00', '100.0000', '80.000', '135260000000001'],
            ['nfe-delivery-002.xml', '35261011111111111111550010000000021000000027', '2000.00', '200.0000', '160.000', '135260000000002'],
        ];

        foreach ($fixtures as [$filename, $accessKey, $value, $liters, $weight, $protocol]) {
            $path = $this->fixture($filename);
            $nfe = app(ImportNfeXml::class)->handle((string) file_get_contents($path), 'operator-renamed.xml');

            $this->assertSame($accessKey, $nfe['access_key']);
            $this->assertSame($value, $nfe['cargo_value']);
            $this->assertSame($liters, $nfe['cargo_liters']);
            $this->assertSame($weight, $nfe['net_weight_kg']);
            $this->assertSame($protocol, $nfe['protocol']);
            $this->assertSame($liters, $nfe['products'][0]['liters']);
            $this->assertSame($value, $nfe['products'][0]['value']);
            $this->assertSame('operator-renamed.xml', $nfe['source_filename']);
        }
    }

    public function test_rejects_an_nfe_that_is_not_authorized(): void
    {
        $xml = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $xml = str_replace('<cStat>100</cStat>', '<cStat>204</cStat>', $xml);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('protocolo de autorização válido');

        app(ImportNfeXml::class)->handle($xml, 'unauthorized.xml');
    }

    public function test_ignores_stale_libxml_errors_when_importing_a_valid_nfe(): void
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        $invalidDocument = new \DOMDocument;
        $invalidDocument->loadXML('<invalid');

        try {
            $nfe = app(ImportNfeXml::class)->handle(
                (string) file_get_contents($this->fixture('nfe-delivery-001.xml')),
                'valid-after-invalid.xml',
            );

            $this->assertSame('35261011111111111111550010000000011000000011', $nfe['access_key']);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    public function test_accepts_authorization_protocols_with_normal_and_late_authorization_status(): void
    {
        $xml = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $xml = str_replace('<cStat>100</cStat>', '<cStat>150</cStat>', $xml);

        $nfe = app(ImportNfeXml::class)->handle($xml, 'late-authorized.xml');

        $this->assertSame('35261011111111111111550010000000011000000011', $nfe['access_key']);
        $this->assertSame('135260000000001', $nfe['protocol']);
    }

    public function test_rejects_quantity_unit_or_total_values_that_need_manual_review(): void
    {
        $path = $this->fixture('nfe-delivery-001.xml');
        $xml = (string) file_get_contents($path);
        $xml = str_replace('<qTrib>100.0000</qTrib>', '<qTrib>99.0000</qTrib>', $xml);

        try {
            app(ImportNfeXml::class)->handle($xml, 'different-quantity.xml');
            $this->fail('Expected a difference between commercial and taxable quantities to require review.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('quantidade', $exception->getMessage());
        }

        $xml = (string) file_get_contents($path);
        $xml = str_replace('<vNF>1000.00</vNF>', '<vNF>1000.01</vNF>', $xml);

        try {
            app(ImportNfeXml::class)->handle($xml, 'different-total.xml');
            $this->fail('Expected a difference between vNF and the product total to require review.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('vNF', $exception->getMessage());
        }

        $xml = (string) file_get_contents($path);
        $xml = str_replace('<qCom>100.0000</qCom>', '<qCom>100.00001</qCom>', $xml);

        $this->expectException(DomainException::class);
        app(ImportNfeXml::class)->handle($xml, 'excess-precision.xml');
    }

    public function test_normalizes_known_liter_unit_aliases_and_rejects_ambiguous_units(): void
    {
        $path = $this->fixture('nfe-delivery-001.xml');
        $xml = str_replace('<uTrib>L</uTrib>', '<uTrib>LT</uTrib>', (string) file_get_contents($path));
        $nfe = app(ImportNfeXml::class)->handle($xml, 'liter-unit-alias.xml');

        $this->assertSame('100.0000', $nfe['cargo_liters']);

        $xml = str_replace(['<uCom>L</uCom>', '<uTrib>L</uTrib>'], ['<uCom>KG</uCom>', '<uTrib>KG</uTrib>'], (string) file_get_contents($path));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('unidades comerciais e tributárias incompatíveis');

        app(ImportNfeXml::class)->handle($xml, 'ambiguous-unit.xml');
    }

    public function test_converts_cubic_meter_quantities_to_liters(): void
    {
        $xml = str_replace(
            ['<uCom>L</uCom>', '<qCom>100.0000</qCom>', '<uTrib>L</uTrib>', '<qTrib>100.0000</qTrib>'],
            ['<uCom>m3</uCom>', '<qCom>19.89</qCom>', '<uTrib>m3</uTrib>', '<qTrib>19.89</qTrib>'],
            (string) file_get_contents($this->fixture('nfe-delivery-001.xml')),
        );

        $nfe = app(ImportNfeXml::class)->handle($xml, 'cubic-meter-quantity.xml');

        $this->assertSame('19890.0000', $nfe['cargo_liters']);
        $this->assertSame('19890.0000', $nfe['products'][0]['liters']);
    }

    public function test_rejects_ambiguous_decimal_separators_and_numbers_outside_exact_integer_range(): void
    {
        $path = $this->fixture('nfe-delivery-001.xml');
        $xml = str_replace('<qCom>100.0000</qCom>', '<qCom>100,0000</qCom>', (string) file_get_contents($path));

        try {
            app(ImportNfeXml::class)->handle($xml, 'ambiguous-decimal.xml');
            $this->fail('Expected comma decimal separators to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('ambiguous-decimal.xml', $exception->getMessage());
            $this->assertStringContainsString('qCom', $exception->getMessage());
        }

        $xml = str_replace(
            ['<qCom>100.0000</qCom>', '<qTrib>100.0000</qTrib>'],
            ['<qCom>999999999999999999999999.0000</qCom>', '<qTrib>999999999999999999999999.0000</qTrib>'],
            (string) file_get_contents($path),
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('limite para cálculo exato');

        app(ImportNfeXml::class)->handle($xml, 'quantity-overflow.xml');
    }

    public function test_rejects_access_key_with_an_invalid_check_digit_even_when_protocol_matches(): void
    {
        $validKey = '35261011111111111111550010000000011000000011';
        $invalidKey = substr($validKey, 0, 43).(((int) substr($validKey, -1) + 1) % 10);
        $xml = str_replace($validKey, $invalidKey, (string) file_get_contents($this->fixture('nfe-delivery-001.xml')));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('chave de acesso válida');

        app(ImportNfeXml::class)->handle($xml, 'invalid-check-digit.xml');
    }

    public function test_rejects_an_xml_with_an_unexpected_namespace_or_model(): void
    {
        $fixture = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $wrongNamespace = str_replace('http://www.portalfiscal.inf.br/nfe', 'https://example.invalid/nfe', $fixture);

        try {
            app(ImportNfeXml::class)->handle($wrongNamespace, 'wrong-namespace.xml');
            $this->fail('Expected the NF-e namespace to be validated.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('infNFe estruturado', $exception->getMessage());
        }

        $wrongModel = str_replace('<mod>55</mod>', '<mod>65</mod>', $fixture);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('não é uma NF-e modelo 55');

        app(ImportNfeXml::class)->handle($wrongModel, 'wrong-model.xml');
    }

    public function test_requires_a_complete_authorization_protocol_number(): void
    {
        $xml = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $xml = preg_replace('/<nProt>[^<]+<\/nProt>/', '<nProt></nProt>', $xml, 1);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('protocolo de autorização');

        app(ImportNfeXml::class)->handle((string) $xml, 'missing-protocol-number.xml');
    }

    public function test_rejects_malformed_xml(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Não foi possível ler o XML');

        app(ImportNfeXml::class)->handle('<nfeProc>', 'malformed.xml');
    }

    public function test_rejects_external_entity_and_network_declarations(): void
    {
        $payloads = [
            '<!DOCTYPE nfeProc [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><nfeProc>&xxe;</nfeProc>',
            '<!DOCTYPE nfeProc SYSTEM "https://example.invalid/nfe.dtd"><nfeProc/>',
        ];

        foreach ($payloads as $xml) {
            try {
                app(ImportNfeXml::class)->handle($xml, 'external-resource.xml');
                $this->fail('Expected external XML resources to be rejected.');
            } catch (DomainException $exception) {
                $this->assertStringContainsString('declarações XML não permitidas', $exception->getMessage());
            }
        }
    }

    public function test_rejects_xml_that_exceeds_the_configured_per_file_limit(): void
    {
        $xml = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $maxFileSizeBytes = strlen($xml) - 1;
        Config::set('dfe.nfe_xml_max_file_size_bytes', $maxFileSizeBytes);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("limite de {$maxFileSizeBytes} bytes por XML");

        app(ImportNfeXml::class)->handle($xml, 'oversized.xml');
    }

    public function test_sums_every_structured_net_weight_value(): void
    {
        $xml = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $xml = str_replace('</transp>', '<vol><pesoL>1.250</pesoL></vol></transp>', $xml);

        $nfe = app(ImportNfeXml::class)->handle($xml, 'multiple-volumes.xml');

        $this->assertSame('81.250', $nfe['net_weight_kg']);
    }

    public function test_requires_a_net_weight_for_every_volume_group(): void
    {
        $xml = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $xml = str_replace('</transp>', '<vol><qVol>1</qVol></vol></transp>', $xml);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('missing-volume-weight.xml');
        $this->expectExceptionMessage('pesoL');

        app(ImportNfeXml::class)->handle($xml, 'missing-volume-weight.xml');
    }

    public function test_ignores_trip_totals_and_other_destinations_in_free_text(): void
    {
        $xml = (string) file_get_contents($this->fixture('nfe-delivery-001.xml'));
        $xml = preg_replace(
            '/(<infCpl>).*?(<\/infCpl>)/s',
            '$1TOTAL DA VIAGEM: 999999.99; OUTRO DESTINO: RUA TESTE, 999$2',
            $xml,
            1,
        );

        $nfe = app(ImportNfeXml::class)->handle((string) $xml, 'free-text-ignored.xml');

        $this->assertSame('1000.00', $nfe['cargo_value']);
        $this->assertSame('33333333333333', $nfe['delivery']['cnpj']);
        $this->assertSame('Rua da Entrega', $nfe['delivery']['street']);
    }

    private function fixture(string $filename): string
    {
        return base_path("tests/Fixtures/Dfe/Nfe/{$filename}");
    }
}
