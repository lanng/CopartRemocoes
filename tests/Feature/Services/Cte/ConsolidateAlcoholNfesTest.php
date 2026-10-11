<?php

namespace Tests\Feature\Services\Cte;

use App\Services\Cte\ConsolidateAlcoholNfes;
use App\Services\Cte\ImportNfeXml;
use DomainException;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ConsolidateAlcoholNfesTest extends TestCase
{
    public function test_sums_compatible_nfes_and_rejects_duplicate_keys(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $first = app(ImportNfeXml::class)->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'first.xml',
        );
        $second = app(ImportNfeXml::class)->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'second.xml',
        );
        $totals = app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);

        $this->assertSame('3000.00', $totals['cargo_value']);
        $this->assertSame('300.0000', $totals['cargo_liters']);
        $this->assertSame('240.000', $totals['net_weight_kg']);
        $this->assertSame('3506003', $totals['delivery']['ibge']);

        $this->expectException(DomainException::class);
        app(ConsolidateAlcoholNfes::class)->handle([$first, $first]);
    }

    public function test_rejects_import_batches_above_the_configured_file_count(): void
    {
        Config::set('dfe.nfe_xml_max_files_per_import', 1);
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $first = $importer->handle((string) file_get_contents($base.'nfe-delivery-001.xml'), 'first.xml');
        $second = $importer->handle((string) file_get_contents($base.'nfe-delivery-002.xml'), 'second.xml');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('limite de 1 NF-es por importação');

        app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);
    }

    public function test_rejects_different_product_identities_even_with_matching_ncm_and_anp(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $first = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'first.xml',
        );
        $second = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'second.xml',
        );
        $second['products'][0]['name'] = 'Produto diferente';

        try {
            app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);
            $this->fail('Expected different product identities to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('second.xml', $exception->getMessage());
            $this->assertStringContainsString('produto', mb_strtolower($exception->getMessage()));
        }
    }

    public function test_normalizes_product_name_punctuation_before_comparing_identity(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $first = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'first.xml',
        );
        $second = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'second.xml',
        );
        $second['products'][0]['name'] = 'etanol hidratado combustivel teste';

        $totals = app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);

        $this->assertCount(1, $totals['products']);
        $this->assertSame('300.0000', $totals['products'][0]['liters']);
    }

    public function test_rejects_different_product_codes_even_with_matching_classification_and_name(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $first = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'first.xml',
        );
        $second = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'second.xml',
        );
        $second['products'][0]['code'] = 'OUTRO-ETANOL';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('cProd');

        app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);
    }

    public function test_rejects_nfes_with_different_effective_delivery_locations(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $deliveryNfe = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'delivery.xml',
        );
        $differentCityNfe = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'different-city.xml',
        );
        $differentCityNfe['delivery']['city'] = 'MARINGA';

        try {
            app(ConsolidateAlcoholNfes::class)->handle([$deliveryNfe, $differentCityNfe]);
            $this->fail('Expected a conflicting delivery city to identify its source file and field.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('different-city.xml', $exception->getMessage());
            $this->assertStringContainsString('city', $exception->getMessage());
        }
    }

    public function test_consolidates_nfes_without_delivery_to_the_fiscal_recipient(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $first = app(ImportNfeXml::class)->handle(
            (string) file_get_contents($base.'nfe-recipient-001.xml'),
            '499606.xml',
        );
        $second = app(ImportNfeXml::class)->handle(
            (string) file_get_contents($base.'nfe-recipient-002.xml'),
            '499608.xml',
        );
        $second['delivery']['city'] = 'campinas';
        $second['delivery']['street'] = ' Rua   Fiscal ';
        $totals = app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);

        $this->assertSame('7000.00', $totals['cargo_value']);
        $this->assertSame('700.0000', $totals['cargo_liters']);
        $this->assertSame('560.000', $totals['net_weight_kg']);
        $this->assertSame($totals['recipient'], $totals['delivery']);
        $this->assertSame([], $totals['delivery_missing_fields']);
    }

    public function test_rejects_a_product_not_classified_as_alcohol(): void
    {
        $path = base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $nfe = app(ImportNfeXml::class)->handle((string) file_get_contents($path), 'wrong-product.xml');
        $nfe['products'][0]['ncm'] = '22071010';

        $this->expectException(DomainException::class);
        app(ConsolidateAlcoholNfes::class)->handle([$nfe]);
    }

    public function test_rejects_totals_with_precision_beyond_the_supported_units(): void
    {
        $path = base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $nfe = app(ImportNfeXml::class)->handle((string) file_get_contents($path), 'excess-precision.xml');
        $nfe['cargo_liters'] = '100.00001';

        $this->expectException(DomainException::class);
        app(ConsolidateAlcoholNfes::class)->handle([$nfe]);
    }

    public function test_rejects_aggregate_totals_outside_the_exact_integer_range(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $first = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'first.xml',
        );
        $second = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'second.xml',
        );
        $first['cargo_value'] = '50000000000000000.00';
        $second['cargo_value'] = '50000000000000000.00';

        try {
            app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);
            $this->fail('Expected an aggregate outside the exact integer range to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('second.xml', $exception->getMessage());
            $this->assertStringContainsString('cargo_value', $exception->getMessage());
        }
    }

    public function test_rejects_a_different_recipient_identity_with_file_and_field_context(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $first = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'first.xml',
        );
        $second = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'second.xml',
        );
        $second['recipient']['cnpj'] = '12345678000199';

        try {
            app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);
            $this->fail('Expected a different recipient identity to require review.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('second.xml', $exception->getMessage());
            $this->assertStringContainsString('identidade fiscal do destinatário fiscal', $exception->getMessage());
        }
    }

    public function test_rejects_a_recipient_with_a_different_tax_identity_type(): void
    {
        $path = base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $nfe = app(ImportNfeXml::class)->handle((string) file_get_contents($path), 'cpf-recipient.xml');
        $nfe['recipient']['cnpj'] = '';
        $nfe['recipient']['cpf'] = '12345678909';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('identidade fiscal do destinatário fiscal');

        app(ConsolidateAlcoholNfes::class)->handle([$nfe, app(ImportNfeXml::class)->handle(
            (string) file_get_contents(base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-002.xml')),
            'cnpj-recipient.xml',
        )]);
    }

    public function test_rejects_comma_decimal_values_during_consolidation(): void
    {
        $path = base_path('tests/Fixtures/Dfe/Nfe/nfe-delivery-001.xml');
        $nfe = app(ImportNfeXml::class)->handle((string) file_get_contents($path), 'comma-total.xml');
        $nfe['cargo_value'] = '1000,00';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('valor numérico inválido no campo cargo_value');

        app(ConsolidateAlcoholNfes::class)->handle([$nfe]);
    }

    public function test_preserves_each_incomplete_delivery_candidate_and_missing_field(): void
    {
        $base = base_path('tests/Fixtures/Dfe/Nfe/');
        $importer = app(ImportNfeXml::class);
        $first = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-001.xml'),
            'first.xml',
        );
        $second = $importer->handle(
            (string) file_get_contents($base.'nfe-delivery-002.xml'),
            'second.xml',
        );
        $first['delivery']['street'] = '';
        $first['delivery_missing_fields'] = ['street'];
        $second['delivery']['zipcode'] = '';
        $second['delivery_missing_fields'] = ['zipcode'];

        $totals = app(ConsolidateAlcoholNfes::class)->handle([$first, $second]);

        $this->assertSame(['street', 'zipcode'], $totals['delivery_missing_fields']);
        $this->assertSame([
            ['source_filename' => 'first.xml', 'missing_fields' => ['street']],
            ['source_filename' => 'second.xml', 'missing_fields' => ['zipcode']],
        ], array_map(fn (array $candidate): array => [
            'source_filename' => $candidate['source_filename'],
            'missing_fields' => $candidate['missing_fields'],
        ], $totals['delivery_candidates']));
        $this->assertSame([$first, $second], $totals['documents']);
        $this->assertSame('', $first['delivery']['street']);
        $this->assertSame('', $second['delivery']['zipcode']);
    }
}
