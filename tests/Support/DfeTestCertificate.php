<?php

namespace Tests\Support;

/**
 * Gera um A1 de teste (P12 autoassinado, 2048 bits) para exercitar o
 * SefazCertificate/XmlSigner sem depender do certificado real do CIOT.
 */
class DfeTestCertificate
{
    /**
     * @return array{path: string, password: string}
     */
    public static function create(?string $path = null, string $password = 'test-password'): array
    {
        $path ??= sys_get_temp_dir().'/dfe-test-'.uniqid().'.p12';

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $csr = openssl_csr_new(['commonName' => 'COPART-DFE-TEST'], $privateKey, ['digest_alg' => 'sha256']);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 365, ['digest_alg' => 'sha256'], (string) time());

        if ($privateKey === false || $csr === false || $certificate === false
            || ! openssl_pkcs12_export($certificate, $contents, $privateKey, $password)) {
            self::fail();
        }

        if (file_put_contents($path, $contents) === false) {
            self::fail();
        }

        return ['path' => $path, 'password' => $password];
    }

    protected static function fail(): never
    {
        \PHPUnit\Framework\Assert::fail('Não foi possível gerar o certificado de teste: '.openssl_error_string());
    }
}
