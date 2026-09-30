<?php

namespace Tests\Unit\Services\Dfe;

use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\XmlSigner;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class XmlSignerTest extends TestCase
{
    protected const XMLNS = 'http://www.portalfiscal.inf.br/cte';

    protected const CHAVE = '35260912563112000130570010000000019997761234';

    protected SefazCertificate $certificate;

    protected XmlSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $certificate = DfeTestCertificate::create();

        $this->certificate = new SefazCertificate($certificate['path'], $certificate['password']);
        $this->signer = new XmlSigner($this->certificate);
    }

    public function test_digest_reproduces_the_canonical_bytes_of_the_standalone_trick(): void
    {
        // Valor-alvo calculado independentemente (openssl CLI) sobre os bytes
        // canônicos previstos à mão: o xmlns entra no ápice e nada mais muda.
        $expected = 'GQ15fD9nesDsowEltR27rrM9srM=';

        $digest = $this->signer->digest($this->documentXml(), 'infCte', self::XMLNS);

        $this->assertSame($expected, $digest);
    }

    public function test_canonical_fragment_declares_the_namespace_at_the_apex_without_spurious_xmlns(): void
    {
        $canonical = $this->signer->canonicalFragment($this->documentXml(), 'infCte', self::XMLNS);

        $this->assertStringStartsWith('<infCte xmlns="'.self::XMLNS.'"', $canonical);
        $this->assertStringNotContainsString('xmlns=""', $canonical);
    }

    public function test_sign_inserts_the_signature_after_the_supl_group_without_touching_the_inf(): void
    {
        $xml = $this->fullDocumentXml();
        $infFragment = $this->fragmentBetween($xml, '<infCte ', '</infCte>');

        $signed = $this->signer->sign($xml, 'infCte', self::XMLNS, 'CTe'.self::CHAVE);

        $this->assertSame($infFragment, $this->fragmentBetween($signed, '<infCte ', '</infCte>'), 'Os bytes do infCte devem permanecer idênticos (sem re-serialização).');

        $signaturePos = strpos($signed, '<Signature ');
        $suplPos = strpos($signed, '<infCTeSupl');
        $rootClosePos = strrpos($signed, '</CTe>');

        $this->assertNotFalse($signaturePos);
        $this->assertGreaterThan($suplPos, $signaturePos, 'Ordem xs:sequence do MOC: infCte → infCTeSupl → Signature.');
        $this->assertLessThan($rootClosePos, $signaturePos);

        $this->assertTrue($this->signer->verify($signed, 'infCte', self::XMLNS, 'CTe'.self::CHAVE));
    }

    public function test_sign_produces_a_signature_verifiable_by_the_embedded_certificate(): void
    {
        $signed = $this->signer->sign($this->fullDocumentXml(), 'infCte', self::XMLNS, 'CTe'.self::CHAVE);

        $this->assertStringContainsString('<DigestValue>'.$this->signer->digest($this->fullDocumentXml(), 'infCte', self::XMLNS).'</DigestValue>', $signed);
        $this->assertStringContainsString('<X509Certificate>', $signed);
        $this->assertStringContainsString('Algorithm="'.XmlSigner::C14N_ALGO.'"', $signed);
        $this->assertStringContainsString('Algorithm="'.XmlSigner::RSA_SHA1_ALGO.'"', $signed);
    }

    public function test_verify_rejects_tampered_content(): void
    {
        $signed = $this->signer->sign($this->fullDocumentXml(), 'infCte', self::XMLNS, 'CTe'.self::CHAVE);

        $tampered = str_replace('<nCT>2962</nCT>', '<nCT>2963</nCT>', $signed);

        $this->assertFalse($this->signer->verify($tampered, 'infCte', self::XMLNS, 'CTe'.self::CHAVE));
    }

    public function test_verify_rejects_a_swapped_signature_from_another_document(): void
    {
        $signed = $this->signer->sign($this->fullDocumentXml(), 'infCte', self::XMLNS, 'CTe'.self::CHAVE);
        $other = str_replace('<nCT>2962</nCT>', '<nCT>2963</nCT>', $this->fullDocumentXml());
        $otherSigned = $this->signer->sign($other, 'infCte', self::XMLNS, 'CTe'.self::CHAVE);

        $signature = $this->fragmentBetween($otherSigned, '<Signature ', '</Signature>');
        $spliced = str_replace($this->fragmentBetween($signed, '<Signature ', '</Signature>'), $signature, $signed);

        $this->assertFalse($this->signer->verify($spliced, 'infCte', self::XMLNS, 'CTe'.self::CHAVE));
    }

    public static function authorizedGabaritoProvider(): array
    {
        return [
            'CT-e 2962 (veículos)' => ['cte-2962.xml', 'Ol5h4Q7LEbxFqervvg2/zUevfiI='],
            'CT-e 2963 (tanque)' => ['cte-2963.xml', 'wbqyA5+fqTWP743OhEapA3V89EQ='],
        ];
    }

    #[DataProvider('authorizedGabaritoProvider')]
    public function test_digest_matches_the_authorized_gabarito(string $fixture, string $expectedDigest): void
    {
        $path = __DIR__.'/../../../Fixtures/Dfe/'.$fixture;

        if (! is_readable($path)) {
            $this->markTestSkipped("Gabarito fiscal não provisionado nesta máquina ({$fixture}). Copie os XMLs autorizados 2962/2963 (workspace cte/_gabarito) para tests/Fixtures/Dfe/ para ativar o critério de aceite da F1.");
        }

        $xml = (string) file_get_contents($path);

        $this->assertSame($expectedDigest, $this->signer->digest($xml, 'infCte', self::XMLNS));
    }

    /**
     * CTe mínimo com o infCte herdeiro do namespace no ápice do documento.
     */
    protected function documentXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CTe xmlns="'.self::XMLNS.'">'
            .'<infCte Id="CTe'.self::CHAVE.'" versao="4.00">'
            .'<ide><cUF>35</cUF><nCT>2962</nCT></ide>'
            .'</infCte>'
            .'</CTe>';
    }

    protected function fullDocumentXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CTe xmlns="'.self::XMLNS.'">'
            .'<infCte Id="CTe'.self::CHAVE.'" versao="4.00">'
            .'<ide><cUF>35</cUF><nCT>2962</nCT></ide>'
            .'</infCte>'
            .'<infCTeSupl><qrCode>https://homologacao.nfe.fazenda.sp.gov.br/CTeConsulta/qrCode?chCTe='.self::CHAVE.'&amp;tpAmb=2</qrCode></infCTeSupl>'
            .'</CTe>';
    }

    protected function fragmentBetween(string $xml, string $start, string $end): string
    {
        $startPos = strpos($xml, $start);
        $endPos = strpos($xml, $end, $startPos) + strlen($end);

        return substr($xml, $startPos, $endPos - $startPos);
    }
}
