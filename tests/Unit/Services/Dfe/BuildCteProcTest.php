<?php

namespace Tests\Unit\Services\Dfe;

use App\Services\Dfe\BuildCteProc;
use DomainException;
use Tests\TestCase;

class BuildCteProcTest extends TestCase
{
    protected BuildCteProc $builder;

    protected string $gabarito;

    protected function setUp(): void
    {
        parent::setUp();

        config(['dfe.xsd_path' => resource_path('xsds/cte')]);

        $this->builder = app(BuildCteProc::class);
        $this->gabarito = (string) file_get_contents(__DIR__.'/../../../Fixtures/Dfe/cte-2962.xml');
    }

    public function test_rebuilds_the_real_authorized_proc_from_its_pieces(): void
    {
        $cte = $this->between($this->gabarito, '<CTe ', '</CTe>');
        $prot = $this->between($this->gabarito, '<protCTe ', '</protCTe>');

        $response = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body>'
            .'<cteRetRecepcaoResult xmlns="http://www.portalfiscal.inf.br/cte/wsdl/CTeRecepcaoSincV4">'.$prot.'</cteRetRecepcaoResult>'
            .'</soap:Body></soap:Envelope>';

        $proc = $this->builder->handle('<?xml version="1.0" encoding="UTF-8"?>'.$cte, $response);

        // O protCTe re-serializado ganha o xmlns herdado de forma explícita —
        // semanticamente idêntico ao proc original.
        $canonical = fn (string $xml): string => str_replace(' xmlns="http://www.portalfiscal.inf.br/cte"', '', $xml);

        $this->assertSame(
            $canonical($this->between($this->gabarito, '<cteProc ', '</cteProc>')),
            $canonical($this->between($proc, '<cteProc ', '</cteProc>')),
        );
    }

    public function test_rejects_a_response_without_the_protocol(): void
    {
        $cte = $this->between($this->gabarito, '<CTe ', '</CTe>');

        $response = '<?xml version="1.0" encoding="utf-8"?>'
            .'<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><empty/></soap:Body></soap:Envelope>';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('protCTe');

        $this->builder->handle('<?xml version="1.0" encoding="UTF-8"?>'.$cte, $response);
    }

    protected function between(string $xml, string $start, string $end): string
    {
        $startPos = strpos($xml, $start);
        $endPos = strpos($xml, $end, $startPos) + strlen($end);

        return substr($xml, $startPos, $endPos - $startPos);
    }
}
