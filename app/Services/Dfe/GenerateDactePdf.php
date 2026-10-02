<?php

namespace App\Services\Dfe;

use Dompdf\Dompdf;
use Throwable;

/**
 * Gera o DACTE em PDF (A4 retrato) a partir do cteProc autorizado — Blade +
 * Dompdf desenhando o leiaute essencial do MOC Anexo II (decisão da spec §5:
 * controle total, sem dependência externa frágil). Dados vindos do cteProc
 * (DacteData), nunca do banco.
 */
class GenerateDactePdf
{
    public function __construct(
        protected DfeDocumentStore $store,
    ) {}

    /**
     * Gera o PDF, grava com versionamento no disk dfe e devolve o caminho.
     *
     * @throws Throwable falha de geração/escrita — o chamador decide
     *                   (na autorização o PDF nunca derruba o registro)
     */
    public function generateForProc(string $accessKey, string $cteProcXml): string
    {
        $data = DacteData::fromProc($cteProcXml);

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        $dompdf->loadHtml((string) view('dfe.dacte', ['dacte' => $data])->render(), 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        $pdf = (string) $dompdf->output();

        return $this->store->storeDacte($accessKey, $pdf);
    }
}
