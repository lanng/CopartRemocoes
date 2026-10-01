<?php

namespace App\Services\Dfe;

use Illuminate\Support\Facades\Storage;

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
        Storage::disk(self::DISK)->put($path, $contents);

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

    public function read(string $path): string
    {
        return (string) Storage::disk(self::DISK)->get($path);
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
