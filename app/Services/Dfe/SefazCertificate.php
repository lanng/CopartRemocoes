<?php

namespace App\Services\Dfe;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Carrega o A1 (PFX/P12) — o mesmo certificado do CIOT (spec §7) — e expõe
 * PEM/DER para a assinatura XML-DSig e a validade para o widget operacional.
 */
class SefazCertificate
{
    /**
     * @var array{cert: string, pkey: string, extracerts?: list<string>}|null
     */
    protected ?array $parsed = null;

    public function __construct(
        protected ?string $certPath = null,
        protected ?string $certPassword = null,
    ) {
        $this->certPath ??= (string) config('dfe.cert_path');
        $this->certPassword ??= (string) config('dfe.cert_password');
    }

    /**
     * @return array{cert: string, pkey: string, extracerts?: list<string>}
     */
    public function parse(): array
    {
        if ($this->parsed !== null) {
            return $this->parsed;
        }

        if ($this->certPath === '' || $this->certPath === null || ! is_readable($this->certPath)) {
            throw new SefazException('Certificado A1 não encontrado ou ilegível: '.($this->certPath ?: '(vazio)').'. Verifique DFE_CERT_PATH.');
        }

        $contents = (string) file_get_contents($this->certPath);

        if (! openssl_pkcs12_read($contents, $parsed, (string) $this->certPassword)) {
            throw new SefazException('Falha ao abrir o certificado A1 (senha ou arquivo inválido): '.(string) openssl_error_string());
        }

        /** @var array{cert: string, pkey: string, extracerts?: list<string>} $parsed */
        return $this->parsed = $parsed;
    }

    public function certificatePem(): string
    {
        return (string) $this->parse()['cert'];
    }

    public function privateKeyPem(): string
    {
        return (string) $this->parse()['pkey'];
    }

    public function certificatePath(): string
    {
        return (string) $this->certPath;
    }

    public function password(): string
    {
        return (string) $this->certPassword;
    }

    /**
     * Base64 do DER, sem headers nem whitespace — conteúdo do X509Certificate
     * no KeyInfo da assinatura (spec §2.1).
     */
    public function certificateDerBase64(): string
    {
        $withoutMarkers = str_replace(
            ['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----'],
            '',
            $this->certificatePem(),
        );

        return (string) preg_replace('/\s+/', '', $withoutMarkers);
    }

    public function expiresAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromTimestampUTC($this->x509()['validTo_time_t']);
    }

    public function isExpired(): bool
    {
        return $this->expiresAt()->isPast();
    }

    public function daysUntilExpiry(): int
    {
        return (int) floor(now()->diffInDays($this->expiresAt(), false));
    }

    public function subjectCommonName(): string
    {
        return (string) ($this->x509()['subject']['CN'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function x509(): array
    {
        $parsed = openssl_x509_parse($this->certificatePem());

        if ($parsed === false) {
            throw new RuntimeException('Certificado A1 inválido: falha ao interpretar o X.509.');
        }

        return $parsed;
    }
}
