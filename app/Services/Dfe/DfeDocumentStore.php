<?php

namespace App\Services\Dfe;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Arquivo fiscal de guarda dos DF-e (spec dfe §5.1): prefixo dfe/ no S3,
 * SEM expiração — a regra de ouro dos 5 anos. No bucket atual não existe
 * lifecycle AWS (configuração confirmada com o dono do projeto); se um dia
 * existir, o prefixo dfe/ tem de ficar fora dela.
 */
class DfeDocumentStore
{
    protected const DISK = 'dfe';

    /**
     * Grava o arquivo e devolve [path, sha256].
     *
     * @return array{path: string, sha256: string}
     */
    public function store(string $path, string $contents): array
    {
        if (! Storage::disk(self::DISK)->put($path, $contents)) {
            throw new RuntimeException("Falha ao armazenar o arquivo fiscal {$path}.");
        }

        return [
            'path' => $path,
            'sha256' => hash('sha256', $contents),
        ];
    }

    /**
     * cteProc completo (CTe + protocolo) por chave de acesso — o arquivo
     * fiscal de guarda (5 anos) e insumo do DACTE.
     */
    public function storeCteProc(string $accessKey, string $cteProcXml): array
    {
        return $this->store("cte/{$accessKey}/cte-proc.xml", $cteProcXml);
    }

    /**
     * Guarda a NF-e original por chave e hash para preservar cópias diferentes sem sobrescrever arquivos anteriores.
     *
     * @return array{path: string, sha256: string}
     */
    public function storeNfeOriginal(string $accessKey, string $nfeXml): array
    {
        $sha256 = hash('sha256', $nfeXml);

        return $this->store("nfe/{$accessKey}/{$sha256}.xml", $nfeXml);
    }

    /**
     * mdfeProc completo (MDFe assinado + protMDFe) por chave de acesso.
     *
     * @return array{path: string, sha256: string}
     */
    public function storeMdfeProc(string $accessKey, string $mdfeProcXml): array
    {
        return $this->store("mdfe/{$accessKey}/mdfe-proc.xml", $mdfeProcXml);
    }

    /**
     * procEventoMDFe processado, guardado individualmente pelo Id do evento.
     *
     * @return array{path: string, sha256: string}
     */
    public function storeMdfeEventProc(string $accessKey, string $eventId, string $procEventoXml): array
    {
        return $this->store("mdfe/{$accessKey}/eventos/{$eventId}-proc.xml", $procEventoXml);
    }

    /**
     * procEventoCTe processado, guardado individualmente pelo Id do evento.
     *
     * @return array{path: string, sha256: string}
     */
    public function storeCteEventProc(string $accessKey, string $eventId, string $procEventoXml): array
    {
        return $this->store("cte/{$accessKey}/eventos/{$eventId}-proc.xml", $procEventoXml);
    }

    /**
     * DACTE com versionamento (spec dfe §5.1): regeneração NUNCA sobrescreve —
     * dacte.pdf vira dacte-2.pdf, dacte-3.pdf…
     */
    public function storeDacte(string $accessKey, string $pdf): string
    {
        $base = "cte/{$accessKey}";
        $path = "{$base}/dacte.pdf";
        $version = 2;

        while ($this->exists($path)) {
            $path = "{$base}/dacte-{$version}.pdf";
            $version++;
        }

        $this->store($path, $pdf);

        return $path;
    }

    /**
     * DAMDFE com versionamento: regeneração não sobrescreve a representação já emitida.
     */
    public function storeDamdfe(string $accessKey, string $pdf): string
    {
        $base = "mdfe/{$accessKey}";
        $path = "{$base}/damdfe.pdf";
        $version = 2;

        while ($this->exists($path)) {
            $path = "{$base}/damdfe-{$version}.pdf";
            $version++;
        }

        $this->store($path, $pdf);

        return $path;
    }

    public function read(string $path): string
    {
        return (string) Storage::disk(self::DISK)->get($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
