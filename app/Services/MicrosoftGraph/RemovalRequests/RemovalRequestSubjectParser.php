<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

class RemovalRequestSubjectParser
{
    /**
     * @return array{vehicle_plate: string, vehicle_id: string, insurance: string}|null
     */
    public function parse(string $subject): ?array
    {
        if (! preg_match(
            '~^\s*Pedido\s+de\s+Remoção\s*-\s*([A-Z]{3}(?:-?[0-9]{4}|-?[0-9][A-Z][0-9]{2}))\s*-\s*(\d+)\s*-\s*(\S(?:.*?\S)?)\s*$~isu',
            $subject,
            $matches
        )) {
            return null;
        }

        $normalizer = new RemovalRequestNormalizer;

        if (! preg_match('/[\p{L}\p{N}]/u', $matches[3])) {
            return null;
        }

        return [
            'vehicle_plate' => (string) $normalizer->plate($matches[1]),
            'vehicle_id' => trim($matches[2]),
            'insurance' => trim($matches[3]),
        ];
    }
}
