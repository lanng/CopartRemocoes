<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class WhatsappExtractorService
{
    public function extractData(string $text): array
    {
        $data = [];

        $extract = function ($pattern, $subject, $group = 1) {
            if (preg_match($pattern, $subject, $matches)) {
                return trim($matches[$group]);
            }

            return null;
        };

        $data['vehicle_id'] = $extract('~^(\d+_\d+)\s*-~m', $text);
        $data['insurance'] = $extract('~Banco/Seguradora:\s*(.+)~im', $text);
        $data['vehicle_model'] = $extract('~Veículo:\s*(.+)~im', $text);
        $data['vehicle_plate'] = $extract('~Placa:\s*([A-Z0-9]+)~im', $text);
        $data['origin_city'] = $extract('~Endereço:.*?[,\s-]\s*([\w\s]+?)\s*[-/]\s*SP~im', $text);
        $data['contact_phone'] = $extract('~Contato:\s*(.+)~im', $text);

        $data['destination_city'] = 'MILAN LEILOES';
        $data['vehicle_id'] = '1111';

        $deadlineMatch = $extract('~ENTREGAR NA MILAN ATE DIA\s+(\d{2}/\d{2})~im', $text);
        if ($deadlineMatch) {
            try {
                $data['deadline_delivery'] = Carbon::createFromFormat('d/m/Y', $deadlineMatch.'/'.now()->year)->format('Y-m-d');
            } catch (\Exception $e) {
                $data['deadline_delivery'] = null;
            }
        }

        $value = $extract('~R\$\s*([\d.,]+)~im', $text);
        if ($value) {
            $data['value'] = (float) str_replace(['.', ','], ['', '.'], $value);
        }

        return array_filter($data, fn ($value) => ! is_null($value));
    }
}
