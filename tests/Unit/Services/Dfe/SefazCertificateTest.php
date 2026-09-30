<?php

namespace Tests\Unit\Services\Dfe;

use App\Services\Dfe\SefazCertificate;
use App\Services\Dfe\SefazException;
use Tests\Support\DfeTestCertificate;
use Tests\TestCase;

class SefazCertificateTest extends TestCase
{
    public function test_parses_a_p12_into_pem_der_and_validity(): void
    {
        $certificateFile = DfeTestCertificate::create();
        $certificate = new SefazCertificate($certificateFile['path'], $certificateFile['password']);

        $this->assertStringContainsString('-----BEGIN CERTIFICATE-----', $certificate->certificatePem());
        $this->assertMatchesRegularExpression('/PRIVATE KEY/', $certificate->privateKeyPem());

        $der = $certificate->certificateDerBase64();

        $this->assertStringNotContainsString('-----', $der);
        $this->assertStringNotContainsString("\n", $der);
        $this->assertNotFalse(base64_decode($der, true));

        $this->assertFalse($certificate->isExpired());
        $this->assertGreaterThan(0, $certificate->daysUntilExpiry());
        $this->assertSame('COPART-DFE-TEST', $certificate->subjectCommonName());
    }

    public function test_throws_when_the_certificate_file_is_missing(): void
    {
        $certificate = new SefazCertificate('/caminho/inexistente.p12', 'secret');

        $this->expectException(SefazException::class);
        $this->expectExceptionMessage('DFE_CERT_PATH');

        $certificate->parse();
    }

    public function test_throws_when_the_password_is_wrong(): void
    {
        $certificateFile = DfeTestCertificate::create();
        $certificate = new SefazCertificate($certificateFile['path'], 'senha-errada');

        $this->expectException(SefazException::class);
        $this->expectExceptionMessage('certificado A1');

        $certificate->parse();
    }
}
