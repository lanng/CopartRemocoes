<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

class PdfExtractorService
{
    public function extractData(string $pdfPath): array
    {
        if (! file_exists($pdfPath) || ! is_readable($pdfPath)) {
            Log::error('PdfExtractorService: Arquivo PDF ausente ou sem permissão de leitura.', [
                'path' => $pdfPath,
            ]);

            throw new RuntimeException('Não foi possível ler o arquivo PDF.');
        }

        $command = 'pdftotext -layout '.escapeshellarg($pdfPath).' -';

        try {
            $result = Process::timeout(30)->run($command);
        } catch (Throwable $exception) {
            Log::error('PdfExtractorService: Falha controlada ao executar pdftotext.', [
                'exception' => $exception,
            ]);

            throw new RuntimeException('Não foi possível extrair texto do PDF.', 0, $exception);
        }

        if ($result->failed() || trim($result->output()) === '') {
            Log::error('PdfExtractorService: pdftotext retornou falha ou saída vazia.', [
                'exit_code' => $result->exitCode(),
            ]);

            throw new RuntimeException('Não foi possível extrair texto do PDF.');
        }

        return $this->extractDataFromText($result->output());
    }

    public function extractDataFromText(string $text): array
    {
        $outputData = [];

        $outputData['deadline_withdraw'] = $this->extractValue('~DATA LIMITE PARA RETIRAR DA OFICINA\s+(\d{2}/\d{2}/\d{4})\s*$~m', $text);
        $outputData['vehicle_model'] = $this->extractValue('~^MODELO\s+([^\s].*?)\s+VALOR FIPE~m', $text);
        $outputData['vehicle_plate'] = $this->extractValue('~^\s+PLACA\s+([^\s]+)\s*$~m', $text);
        $outputData['origin_city'] = $this->extractValue('~^CIDADE\s+([^\s].*?)\s*$~m', $text);
        $outputData['origin_address'] = $this->extractValue('~^ENDEREÇO\s+([^\s].*?)\s+Nº~m', $text);
        $outputData['origin_number'] = $this->extractValue('~Nº\s+(\d+)\s*$~m', $text);
        $outputData['origin_name'] = $this->extractValue('~^LOCAL\s+(.*?)\s+CEP\s+\d+~m', $text);
        $outputData['origin_zip_code'] = $this->extractValue('~CEP\s+(\d+)\s*$~m', $text);
        $outputData['origin_district'] = $this->extractValue('~^BAIRRO\s+(.*?)\s+ESTADO~m', $text);
        $outputData['deadline_delivery'] = $this->extractValue('~DATA LIMITE ENTREGA\s+(\d{2}/\d{2}/\d{4})\s*$~m', $text);
        $outputData['destination_city'] = $this->extractValue('~PATIO DESTINO\s+([^-]+?)\s+-\s+\w{2}\s+DATA LIMITE ENTREGA~', $text);
        $outputData['vehicle_id'] = $this->extractValue('~CÓDIGO VEÍCULO\s+(\d+)\s*$~m', $text);
        $outputData['insurance'] = $this->extractValue('~DADOS DO COMITENTE\s+COMITENTE\s+(.*?)\s+SINISTRO~s', $text);

        $phone1 = $this->extractValue('~TELEFONE 1\s+([\d\s]+)$~m', $text);
        $phone2 = $this->extractValue('~TELEFONE 2\s+([\d\s]+)$~m', $text);
        $outputData['origin_phones'] = [];
        if ($phone1 !== null) {
            $outputData['origin_phones'][] = preg_replace('/\s+/', ' ', trim($phone1));
        }
        if ($phone2 !== null) {
            $outputData['origin_phones'][] = preg_replace('/\s+/', ' ', trim($phone2));
        }

        return $outputData;
    }

    private function extractValue(string $pattern, string $text, int $group = 1): ?string
    {
        if (preg_match($pattern, $text, $matches, PREG_UNMATCHED_AS_NULL) === 1
            && array_key_exists($group, $matches)
            && $matches[$group] !== null) {
            return trim((string) $matches[$group]);
        }

        return null;
    }
}
