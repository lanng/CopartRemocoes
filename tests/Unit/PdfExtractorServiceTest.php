<?php

namespace Tests\Unit;

use App\Services\PdfExtractorService;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase as ApplicationTestCase;

class PdfExtractorServiceTest extends ApplicationTestCase
{
    public function test_it_extracts_all_fields_from_a_layout_text_fixture(): void
    {
        $text = $this->fixtureText();

        $result = (new PdfExtractorService)->extractDataFromText($text);

        $this->assertSame([
            'deadline_withdraw' => '26/08/2026',
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'origin_address' => 'Avenida Brasil',
            'origin_number' => '123',
            'origin_name' => 'Oficina Copart',
            'origin_zip_code' => '01234567',
            'origin_district' => 'Centro',
            'deadline_delivery' => '03/09/2026',
            'destination_city' => 'Pirapora',
            'vehicle_id' => '1156340',
            'insurance' => 'ALLIANZ SEGUROS S/A',
            'origin_phones' => ['11 99999 1111', '11 98888 2222'],
        ], $result);
    }

    public function test_it_returns_null_and_an_empty_list_when_optional_fields_are_missing(): void
    {
        $result = (new PdfExtractorService)->extractDataFromText('MODELO FIAT ARGO 1.3 VALOR FIPE');

        $this->assertSame([
            'deadline_withdraw' => null,
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_plate' => null,
            'origin_city' => null,
            'origin_address' => null,
            'origin_number' => null,
            'origin_name' => null,
            'origin_zip_code' => null,
            'origin_district' => null,
            'deadline_delivery' => null,
            'destination_city' => null,
            'vehicle_id' => null,
            'insurance' => null,
            'origin_phones' => [],
        ], $result);
    }

    public function test_it_uses_a_safe_pdftotext_process_with_a_thirty_second_timeout(): void
    {
        $path = $this->fixturePath();
        Process::fake(fn () => Process::result($this->fixtureText()));

        $result = (new PdfExtractorService)->extractData($path);

        $this->assertSame('ABC1D23', $result['vehicle_plate']);
        Process::assertRan(function ($process): bool {
            return $process->command === 'pdftotext -layout '.escapeshellarg($this->fixturePath()).' -'
                && $process->timeout === 30;
        });
    }

    public function test_it_throws_a_runtime_exception_for_a_failed_process_without_ending_the_suite(): void
    {
        $path = $this->fixturePath();
        Process::fake(fn () => Process::result('', 'pdftotext failed', 1));

        try {
            (new PdfExtractorService)->extractData($path);
            $this->fail('The failed process should throw a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Não foi possível extrair texto do PDF.', $exception->getMessage());
        }

        $this->assertTrue(true);
    }

    public function test_it_throws_a_runtime_exception_for_empty_process_output_without_ending_the_suite(): void
    {
        Process::fake(fn () => Process::result(''));

        try {
            (new PdfExtractorService)->extractData($this->fixturePath());
            $this->fail('Empty process output should throw a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Não foi possível extrair texto do PDF.', $exception->getMessage());
        }

        $this->assertTrue(true);
    }

    public function test_it_throws_a_runtime_exception_for_a_missing_file_without_ending_the_suite(): void
    {
        try {
            (new PdfExtractorService)->extractData('/tmp/missing-pdf-extractor-service.pdf');
            $this->fail('A missing file should throw a RuntimeException.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Não foi possível ler o arquivo PDF.', $exception->getMessage());
        }

        $this->assertTrue(true);
    }

    public function test_it_can_parse_two_texts_consecutively_without_redeclaring_a_function(): void
    {
        $service = new PdfExtractorService;

        $firstResult = $service->extractDataFromText($this->fixtureText());
        $secondResult = $service->extractDataFromText("MODELO FORD KA VALOR FIPE\n");

        $this->assertSame('ABC1D23', $firstResult['vehicle_plate']);
        $this->assertSame('FORD KA', $secondResult['vehicle_model']);
    }

    public function test_it_escapes_paths_with_spaces_and_quotes_in_the_process_command(): void
    {
        $path = sys_get_temp_dir().'/pdf extraction \'quoted\' file.pdf';
        file_put_contents($path, 'fake pdf');
        Process::fake(fn () => Process::result($this->fixtureText()));

        try {
            (new PdfExtractorService)->extractData($path);

            Process::assertRan(function ($process) use ($path): bool {
                return $process->command === 'pdftotext -layout '.escapeshellarg($path).' -';
            });
            $this->assertTrue(true);
        } finally {
            unlink($path);
        }
    }

    private function fixturePath(): string
    {
        return __DIR__.'/../Fixtures/Pdf/carta-de-remocao.txt';
    }

    private function fixtureText(): string
    {
        $text = file_get_contents($this->fixturePath());
        $this->assertIsString($text);

        return $text;
    }
}
