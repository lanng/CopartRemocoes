<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\DacteData;
use App\Services\Dfe\GenerateDactePdf;
use DOMElement;
use Dompdf\Dompdf;
use Dompdf\Frame;
use DOMText;
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
            'homologação' => ['cte-homolog-autorizado.xml'],
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

    public function test_prints_the_neutral_software_credit_in_the_footer(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');
        $data = DacteData::fromProc($proc);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();

        $this->assertStringContainsString('Emitido por Sistema Interno', $html);
        $this->assertStringNotContainsString('COPART-DFE-1.0', $html);
    }

    public function test_pdf_renders_the_taker_selected_by_toma3_zero(): void
    {
        $proc = str_replace(
            '<toma3><toma>3</toma></toma3>',
            '<toma3><toma>0</toma></toma3>',
            (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml'),
        );
        $data = DacteData::fromProc($proc);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();

        $this->assertStringContainsString('TOKIO MARINE SEGURADORA S.A', $html);
        $this->assertStringNotContainsString('style="left: 66pt; top: 2pt; width: 210pt; font-size: 6.5pt">Copart do Brasil', $html);
        $this->assertPdfRenders($proc);
    }

    public function test_pdf_uses_toma4_expediter_cfop_and_route_from_xml(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');
        $taker = '<toma4><toma>4</toma><CNPJ>99999999000199</CNPJ><IE>998877665544</IE>'
            .'<xNome>Tomador Outro Sintetico</xNome><enderToma><xLgr>Rua do Tomador</xLgr><nro>99</nro>'
            .'<xBairro>Centro</xBairro><cMun>3534708</cMun><xMun>Ourinhos</xMun><CEP>19900000</CEP><UF>MG</UF></enderToma></toma4>';
        $expediter = '<exped><CNPJ>88888888000188</CNPJ><IE>887766554433</IE><xNome>Expedidor Sintetico</xNome>'
            .'<enderExped><xLgr>Rua do Expedidor</xLgr><nro>88</nro><xBairro>Industrial</xBairro>'
            .'<cMun>3534708</cMun><xMun>Ourinhos</xMun><CEP>19900001</CEP><UF>MG</UF></enderExped></exped>';
        $proc = str_replace('<toma3><toma>3</toma></toma3>', $taker, $proc);
        $proc = str_replace('</rem><dest>', '</rem>'.$expediter.'<dest>', $proc);
        $proc = str_replace('<CFOP>5353</CFOP>', '<CFOP>6932</CFOP>', $proc);
        $proc = str_replace('<UFIni>SP</UFIni>', '<UFIni>MG</UFIni>', $proc);
        $data = DacteData::fromProc($proc);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();

        $this->assertStringContainsString('6932 - PREST SERV TRANSPORTE', $html);
        $this->assertStringContainsString('Assis - MG - 3504008', $html);
        $this->assertStringContainsString('Itaquaquecetuba - SP - 3523107', $html);
        $this->assertStringContainsString('Expedidor Sintetico', $html);
        $this->assertStringContainsString('Tomador Outro Sintetico', $html);
        $this->assertStringContainsString('Rua do Tomador, 99 - Centro', $html);
        $this->assertPdfRenders($proc);
    }

    public function test_pdf_prints_all_measurements_and_selects_peso_bruto_by_description(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');
        $measurements = '<infQ><cUnid>01</cUnid><tpMed>PESO LÍQUIDO</tpMed><qCarga>1800.0000</qCarga></infQ>'
            .'<infQ><cUnid>01</cUnid><tpMed>PESO BRUTO</tpMed><qCarga>2100.0000</qCarga></infQ>';

        for ($position = 1; $position <= 8; $position++) {
            $measurements .= '<infQ><cUnid>03</cUnid><tpMed>MEDIDA TESTE '.$position.'</tpMed><qCarga>'
                .$position.'.0000</qCarga></infQ>';
        }

        $proc = str_replace(
            '<infQ><cUnid>01</cUnid><tpMed>KG</tpMed><qCarga>2000.0000</qCarga></infQ>',
            $measurements,
            $proc,
        );
        $data = DacteData::fromProc($proc);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();
        $pdf = new Dompdf;
        $frames = [];
        $pdf->setCallbacks([[
            'event' => 'end_frame',
            'f' => function (Frame $frame) use (&$frames): void {
                $node = $frame->get_node();
                if ($node instanceof DOMText && trim($node->textContent) !== '') {
                    $frames[] = [trim($node->textContent), $frame->get_border_box()];
                }
            },
        ]]);
        $pdf->loadHtml($html);
        $pdf->setPaper('a4');
        $pdf->render();

        $measurementFrames = array_values(array_filter(
            $frames,
            fn (array $frame): bool => str_starts_with($frame[0], 'MEDIDA TESTE '),
        ));
        $grossWeightFrames = array_values(array_filter(
            $frames,
            fn (array $frame): bool => $frame[0] === '2.100,0000' && $frame[1][0] < 100,
        ));
        $serviceFrame = array_values(array_filter(
            $frames,
            fn (array $frame): bool => str_contains($frame[0], 'COMPONENTES DO VALOR DA PRESTAÇÃO DE SERVIÇO'),
        ));

        $this->assertCount(8, $measurementFrames);
        $this->assertNotEmpty($grossWeightFrames, 'O campo de peso bruto deve usar a medida PESO BRUTO, não a primeira medida em kg.');
        $this->assertNotEmpty($serviceFrame);
        $this->assertSame(1, $pdf->getCanvas()->get_page_count());
        $this->assertGreaterThan(413, $serviceFrame[0][1][1]);

        $this->assertPdfRenders($proc);
    }

    public function test_estimated_weight_is_printed_as_estimated_and_not_in_the_gross_weight_box(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');
        $proc = str_replace(
            '<infQ><cUnid>01</cUnid><tpMed>KG</tpMed><qCarga>2000.0000</qCarga></infQ>',
            '<infQ><cUnid>01</cUnid><tpMed>PESO ESTIMADO</tpMed><qCarga>1500.0000</qCarga></infQ>',
            $proc,
        );
        $data = DacteData::fromProc($proc);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();

        $this->assertStringContainsString('PESO ESTIMADO: 1.500,0000 KG', $html);
        $this->assertSame(1, substr_count($html, '1.500,0000'));
        $this->assertPdfRenders($proc);

        $unitsOnlyProc = str_replace(
            '<infQ><cUnid>01</cUnid><tpMed>KG</tpMed><qCarga>2000.0000</qCarga></infQ>',
            '<infQ><cUnid>03</cUnid><tpMed>VEICULOS</tpMed><qCarga>3.0000</qCarga></infQ>',
            (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml'),
        );
        $unitsOnly = DacteData::fromProc($unitsOnlyProc);
        $unitsHtml = view('dfe.dacte', ['dacte' => $unitsOnly, 'qrImage' => null])->render();

        $this->assertStringContainsString('VEICULOS: 3,0000 UN', $unitsHtml);
    }

    public function test_measurements_beyond_the_first_page_continue_without_being_dropped(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');
        $measurements = '';

        for ($position = 1; $position <= 40; $position++) {
            $measurements .= '<infQ><cUnid>03</cUnid><tpMed>MEDIDA TESTE '.$position.'</tpMed><qCarga>'
                .$position.'.0000</qCarga></infQ>';
        }

        $proc = str_replace('</infCarga>', $measurements.'</infCarga>', $proc);
        $data = DacteData::fromProc($proc);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();
        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('a4');
        $pdf->render();

        $this->assertSame(2, $pdf->getCanvas()->get_page_count());
        $this->assertStringContainsString('01/02', $html);
        $this->assertStringContainsString('Folha 02/02', $html);
        $this->assertStringContainsString('MEDIDA TESTE 40: 40,0000 UN', $html);
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

    private function assertPdfRenders(string $proc): void
    {
        $accessKey = str_repeat('8', 44);
        $path = app(GenerateDactePdf::class)->generateForProc($accessKey, $proc);

        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get($path));
    }

    public function test_prints_all_nfe_references_and_the_physical_receiver_without_changing_page_geometry(): void
    {
        $proc = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2963.xml');
        $firstReference = '<infNFe><chave>35260945483450002164550010004300881365784943</chave><dPrev>2026-09-30</dPrev></infNFe>';
        $accessKeys = [];
        $references = '';

        for ($position = 1; $position <= 10; $position++) {
            $accessKey = str_pad((string) $position, 44, '1', STR_PAD_LEFT);
            $accessKeys[] = $accessKey;
            $references .= '<infNFe><chave>'.$accessKey.'</chave><dPrev>2026-10-01</dPrev></infNFe>';
        }

        $receiver = '<receb><CPF>12345678901</CPF><xNome>Recebedor físico da carga</xNome>'
            .'<enderReceb><xLgr>Rua da Entrega</xLgr><nro>300</nro><xCpl>Portão 2</xCpl><xBairro>Industrial</xBairro>'
            .'<cMun>3506003</cMun><xMun>Campinas</xMun><CEP>13000000</CEP><UF>SP</UF></enderReceb></receb>';
        $proc = str_replace($firstReference, $references, $proc);
        $proc = str_replace('</rem><dest>', '</rem>'.$receiver.'<dest>', $proc);
        $data = DacteData::fromProc($proc);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();

        foreach ($accessKeys as $accessKey) {
            $this->assertStringContainsString(implode(' ', str_split($accessKey, 4)), $html);
        }

        $this->assertStringContainsString('RECEBEDOR', $html);
        $this->assertStringContainsString('Recebedor físico da carga', $html);
        $this->assertStringContainsString('123.456.789-01', $html);
        $this->assertStringContainsString('01/10/2026', $html);

        $pdf = new Dompdf;
        $pdf->loadHtml($html);
        $pdf->setPaper('a4');
        $pdf->render();

        $this->assertSame(1, $pdf->getCanvas()->get_page_count());

        $path = app(GenerateDactePdf::class)->generateForProc(str_repeat('4', 44), $proc);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get($path));
    }

    #[DataProvider('gabaritoProvider')]
    public function test_printed_frames_match_the_reference_without_moving_for_homologation(string $fixture): void
    {
        $data = DacteData::fromProc(file_get_contents(__DIR__.'/../../../Fixtures/Dfe/'.$fixture));
        $pdf = new Dompdf;
        $frames = [];
        $textFrames = [];
        $pdf->setCallbacks([[
            'event' => 'end_frame',
            'f' => function (Frame $frame) use (&$frames, &$textFrames): void {
                $node = $frame->get_node();
                if ($node instanceof DOMElement && $node->hasAttribute('id')) {
                    $frames[$node->getAttribute('id')] = $frame->get_border_box();
                }
                if ($node instanceof DOMText && trim($node->textContent) !== '') {
                    $textFrames[] = [$node->textContent, $frame->get_border_box()];
                }
            },
        ]]);
        $html = view('dfe.dacte', ['dacte' => $data, 'qrImage' => null])->render();
        $pdf->loadHtml($html);
        $pdf->setPaper('a4');
        $pdf->render();

        $this->assertSame(1, $pdf->getCanvas()->get_page_count());
        $expected = [
            'receipt' => [17.5, 22.5, 563, 52.5],
            'header' => [17.5, 85.5, 563, 138],
            'parties' => [17.5, 261.75, 563, 87],
            'cargo' => [17.5, 375, 563, 39],
            'service' => [17.5, 414, 563, 58.5],
            'documents' => [17.5, 501.75, 563, 63],
            'observations' => [17.5, 564.75, 563, 33],
            'closing' => [17.5, 630.75, 563, 51],
        ];
        foreach ($expected as $id => $box) {
            $this->assertArrayHasKey($id, $frames);
            foreach ($box as $axis => $value) {
                $this->assertEqualsWithDelta($value, $frames[$id][$axis], 0.75, "{$id}: axis {$axis}");
            }
        }
        $this->assertSame($data->isHomolog(), str_contains($html, 'AMBIENTE DE HOMOLOGAÇÃO'));
        $this->assertStringContainsString(implode(' ', $data->groupedAccessKey()), $html);
        foreach ($textFrames as [$text, $box]) {
            $this->assertGreaterThanOrEqual(17.5, $box[0], $text);
            $this->assertLessThanOrEqual(580.5, $box[0] + $box[2], $text);
            $this->assertLessThanOrEqual(695, $box[1] + $box[3], $text);
        }
    }
}
