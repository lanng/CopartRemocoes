<?php

namespace App\Services\Dfe;

use DomainException;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipStream\ZipStream;

class DownloadFiscalDocumentArchive
{
    /**
     * @param  iterable<array{path: string, name: string}>  $files
     */
    public function handle(iterable $files, string $filename): ?StreamedResponse
    {
        $temporaryFile = tmpfile();

        if ($temporaryFile === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário da exportação.');
        }

        try {
            $zip = new ZipStream(outputStream: $temporaryFile, sendHttpHeaders: false);
            $hasFiles = false;

            foreach ($files as $file) {
                try {
                    $contents = Storage::disk('dfe')->get($file['path']);
                } catch (UnableToReadFile) {
                    $contents = null;
                }

                if ($contents === null || $contents === '') {
                    throw new DomainException("O arquivo {$file['name']} está indisponível. Nenhum ZIP foi baixado para evitar uma exportação incompleta.");
                }

                $zip->addFile(fileName: $file['name'], data: $contents);
                $hasFiles = true;
            }

            if (! $hasFiles) {
                fclose($temporaryFile);

                return null;
            }

            $zip->finish();
            rewind($temporaryFile);
        } catch (Throwable $exception) {
            fclose($temporaryFile);

            throw $exception;
        }

        return response()->streamDownload(static function () use ($temporaryFile): void {
            try {
                fpassthru($temporaryFile);
            } finally {
                fclose($temporaryFile);
            }
        }, $filename, ['Content-Type' => 'application/zip']);
    }
}
