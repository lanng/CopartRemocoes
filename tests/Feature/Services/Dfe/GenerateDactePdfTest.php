<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\GenerateDactePdf;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenerateDactePdfTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dfe');
    }

    public static function gabaritoProvider(): array
    {
        return [
            'veículos (2962)' => ['cte-2962.xml'],
            'tanque (2963)' => ['cte-2963.xml'],
        ];
    }

    #[DataProvider('gabaritoProvider')]
    public function test_generates_a_pdf_from_the_authorized_proc(string $fixture): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/'.$fixture);

        $accessKey = str_repeat('3', 44);

        $path = app(GenerateDactePdf::class)->generateForProc($accessKey, $proc);

        $pdf = Storage::disk('dfe')->get($path);

        $this->assertSame("cte/{$accessKey}/dacte.pdf", $path);
        $this->assertNotNull($pdf);
        $this->assertStringStartsWith('%PDF', (string) $pdf);
        $this->assertGreaterThan(3000, strlen((string) $pdf), 'DACTE com o leiaute completo não pode ser um PDF vazio.');
    }

    public function test_regeneration_never_overwrites_it_versions_the_file(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');
        $accessKey = str_repeat('3', 44);

        $generator = app(GenerateDactePdf::class);

        $first = $generator->generateForProc($accessKey, $proc);
        $second = $generator->generateForProc($accessKey, $proc);
        $third = $generator->generateForProc($accessKey, $proc);

        $this->assertSame("cte/{$accessKey}/dacte.pdf", $first);
        $this->assertSame("cte/{$accessKey}/dacte-2.pdf", $second);
        $this->assertSame("cte/{$accessKey}/dacte-3.pdf", $third);

        Storage::disk('dfe')->assertExists($first);
        Storage::disk('dfe')->assertExists($second);
        Storage::disk('dfe')->assertExists($third);
    }
}
