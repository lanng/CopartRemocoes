<?php

namespace App\Services\Dfe;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Throwable;

/**
 * Gera o DACTE em PDF (A4 retrato) a partir do cteProc autorizado — Blade +
 * Dompdf no leiaute de mercado (padrão de facto ACBr sobre o MOC Anexo II).
 * Dados vindos do cteProc (DacteData), nunca do banco. O QR Code do quadro
 * de consulta vem do qrCodCTe do próprio XML (ambos os ambientes).
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

        $dompdf->loadHtml((string) view('dfe.dacte', [
            'dacte' => $data,
            'qrImage' => $this->qrDataUri($data->qrUrl),
        ])->render(), 'UTF-8');

        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return $this->store->storeDacte($accessKey, (string) $dompdf->output());
    }

    /**
     * PNG (data URI) do QR de consulta a partir da URL do qrCodCTe; null
     * quando o XML não traz QR (o quadro cai para a URL textual).
     */
    protected function qrDataUri(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        try {
            $qr = new QRCode(new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'eccLevel' => EccLevel::L,
                'scale' => 3,
                'imageBase64' => true,
            ]));

            $png = (string) $qr->render($url);

            return str_starts_with($png, 'data:image/png') ? $png : null;
        } catch (Throwable) {
            return null;
        }
    }
}
