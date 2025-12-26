<?php
namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class PdfExtractorService
{
    /**
     * @throws Exception
     */
    public function extractData(string $pdfPath): array
    {
        if (! defined('PDFTOTEXT_PATH')) {
            define('PDFTOTEXT_PATH', 'pdftotext');
        }

        $pdfFilePath = $pdfPath;

        if (! file_exists($pdfFilePath)) {
            Log::error("PdfExtractorService: Arquivo não encontrado em '$pdfFilePath'");
            throw new Exception("Erro: Arquivo PDF não encontrado.");
        }

        if (! is_readable($pdfFilePath)) {
            Log::error("PdfExtractorService: Sem permissão de leitura em '$pdfFilePath'");
            throw new Exception("Erro: Sem permissão para ler o arquivo PDF.");
        }

        $escapedPdfPath = escapeshellarg($pdfFilePath);
        $command        = PDFTOTEXT_PATH . " -layout " . $escapedPdfPath . " -";

        $pdfTextContent = shell_exec($command);

        if ($pdfTextContent === null || trim($pdfTextContent) === '') {
            Log::error("Error during the read of the file in PdfExtractorService.");
            exit(1);
        }

        function extractData($pattern, $text, $group = 1): ?string
        {
            if (preg_match($pattern, $text, $matches, PREG_UNMATCHED_AS_NULL)) {
                if (isset($matches[$group])) {
                    return trim($matches[$group]);
                }
            }
            return null;
        }

        $outputData = [];

        //addresses added for future usage.
        $outputData['deadline_withdraw'] = extractData('~DATA LIMITE PARA RETIRAR DA OFICINA\s+(\d{2}/\d{2}/\d{4})\s*$~m', $pdfTextContent);
        $outputData['vehicle_model']     = extractData('~^MODELO\s+([^\s].*?)\s+VALOR FIPE~m', $pdfTextContent);
        $outputData['vehicle_plate']     = extractData('~^\s+PLACA\s+([^\s]+)\s*$~m', $pdfTextContent);
        $outputData['origin_city']       = extractData('~^CIDADE\s+([^\s].*?)\s*$~m', $pdfTextContent);
        $outputData['origin_address']    = extractData('~^ENDEREÇO\s+([^\s].*?)\s+Nº~m', $pdfTextContent);
        $outputData['origin_number']     = extractData('~Nº\s+(\d+)\s*$~m', $pdfTextContent);
        $outputData['origin_name']       = extractData('~^LOCAL\s+(.*?)\s+CEP\s+\d+~m', $pdfTextContent);
        $outputData['origin_zip_code']   = extractData('~CEP\s+(\d+)\s*$~m', $pdfTextContent);
        $outputData['origin_district']   = extractData('~^BAIRRO\s+(.*?)\s+ESTADO~m', $pdfTextContent);
        $outputData['deadline_delivery'] = extractData('~DATA LIMITE ENTREGA\s+(\d{2}/\d{2}/\d{4})\s*$~m', $pdfTextContent);
        $outputData['destination_city']  = extractData('~PATIO DESTINO\s+([^-]+?)\s+-\s+\w{2}\s+DATA LIMITE ENTREGA~', $pdfTextContent);
        $outputData['vehicle_id']        = extractData('~CÓDIGO VEÍCULO\s+(\d+)\s*$~m', $pdfTextContent);
        $outputData['insurance']         = extractData('~DADOS DO COMITENTE\s+COMITENTE\s+(.*?)\s+SINISTRO~s', $pdfTextContent);

        $phone1                      = extractData('~TELEFONE 1\s+([\d\s]+)$~m', $pdfTextContent);
        $phone2                      = extractData('~TELEFONE 2\s+([\d\s]+)$~m', $pdfTextContent);
        $outputData['origin_phones'] = [];
        if ($phone1 !== null) {
            $outputData['origin_phones'][] = preg_replace('/\s+/', ' ', trim($phone1));
        }
        if ($phone2 !== null) {
            $outputData['origin_phones'][] = preg_replace('/\s+/', ' ', trim($phone2));
        }

        header('Content-Type: application/json; charset=utf-8');

        return $outputData;
    }
}
