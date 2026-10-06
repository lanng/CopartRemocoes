<?php

namespace App\Services\Dfe;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use DomainException;
use Dompdf\Dompdf;
use Throwable;

class GenerateDamdfePdf
{
    public function __construct(
        protected DfeDocumentStore $store,
        protected MdfeCode128cSvg $barcode,
    ) {}

    public function generateForProc(string $accessKey, string $mdfeProcXml): string
    {
        $pdf = $this->renderForProc($accessKey, $mdfeProcXml, false);

        return $this->store->storeDamdfe($accessKey, $pdf);
    }

    public function renderVisualPreviewForProc(string $accessKey, string $mdfeProcXml): string
    {
        return $this->renderForProc($accessKey, $mdfeProcXml, true);
    }

    private function renderForProc(string $accessKey, string $mdfeProcXml, bool $visualPreview): string
    {
        $data = DamdfeData::fromProc($mdfeProcXml);

        if (! hash_equals($data['access_key'], $accessKey)) {
            throw new DomainException('A chave solicitada diverge do MDF-e autorizado fornecido.');
        }

        $dompdf = new Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
        ]);

        $dompdf->loadHtml((string) view('dfe.damdfe', [
            'damdfe' => $data,
            'qrImage' => $this->qrDataUri($data['qr_url']),
            'barcodeSvg' => 'data:image/svg+xml;base64,'.base64_encode($this->barcode->render($data['access_key'])),
            'visualPreview' => $visualPreview,
        ])->render(), 'UTF-8');
        $dompdf->setPaper('a4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    protected function qrDataUri(?string $url): ?string
    {
        if (blank($url)) {
            return null;
        }

        try {
            $qr = new QRCode(new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'eccLevel' => EccLevel::L,
                'scale' => 5,
                'imageBase64' => true,
            ]));

            $png = (string) $qr->render($url);

            return str_starts_with($png, 'data:image/png') ? $png : null;
        } catch (Throwable) {
            return null;
        }
    }
}
