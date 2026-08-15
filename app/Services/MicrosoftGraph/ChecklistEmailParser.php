<?php

namespace App\Services\MicrosoftGraph;

use Illuminate\Support\Str;

class ChecklistEmailParser
{
    /**
     * @param  array{sender: string, subject: string, body: string}  $message
     * @return array{valid: bool, reason?: string, vehicle_id?: string, vehicle_plate?: string}
     */
    public function parse(array $message): array
    {
        // TEMPORARIO: remover antes do commit/push junto com a excecao do processador.
        if (! in_array(strtolower(trim($message['sender'])), ['remocao@copart.com.br', 'victorlanguer@hotmail.com'], true)) {
            return ['valid' => false, 'reason' => 'untrusted_sender'];
        }

        if (! preg_match('/^\s*Checklist\s+digital\s*-\s*(\d+)\s*$/iu', $message['subject'], $subjectMatches)) {
            return ['valid' => false, 'reason' => 'invalid_subject'];
        }

        $body = html_entity_decode(strip_tags($message['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = Str::of($body)->replaceMatches('/\s+/', ' ')->trim()->toString();

        if (! preg_match('/ve[ií]culo\s+(\d+)\s*-\s*([A-Z]{3}-?[0-9][A-Z0-9][0-9]{2}|[A-Z]{3}-?[0-9]{4})/iu', $body, $bodyMatches)) {
            return ['valid' => false, 'reason' => 'invalid_body'];
        }

        if ($subjectMatches[1] !== $bodyMatches[1]) {
            return ['valid' => false, 'reason' => 'vehicle_id_mismatch'];
        }

        return [
            'valid' => true,
            'vehicle_id' => $subjectMatches[1],
            'vehicle_plate' => strtoupper(str_replace('-', '', $bodyMatches[2])),
        ];
    }
}
