<?php

namespace App\Services\MicrosoftGraph;

use Illuminate\Support\Str;

class CteErrorEmailParser
{
    /**
     * @param  array{sender: string, subject: string, body: string}  $message
     * @return array{valid: bool, reason?: string, cte_number?: string, error_message?: string, notes?: string}
     */
    public function parse(array $message): array
    {
        if (strtolower(trim($message['sender'])) !== 'remocao@copart.com.br') {
            return ['valid' => false, 'reason' => 'untrusted_sender'];
        }

        if (! preg_match('/^\s*copart\s*-\s*erro\s+no\s+documento\s+numero\s+(\d+)\s*$/i', Str::ascii($message['subject']), $subjectMatches)) {
            return ['valid' => false, 'reason' => 'invalid_subject'];
        }

        $body = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $message['body']);
        $body = preg_replace('/<[^>]*>/', ' ', $body);
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $body));

        if (! preg_match('/documento\s+de\s+transporte\s+n[uú]mero\s*:?\s*(\d+)\b/iu', $body, $bodyMatches)) {
            return ['valid' => false, 'reason' => 'invalid_body'];
        }

        if ($subjectMatches[1] !== $bodyMatches[1]) {
            return ['valid' => false, 'reason' => 'cte_number_mismatch', 'cte_number' => $subjectMatches[1]];
        }

        $footerPattern = 'solicitamos\s+o\s+cancelamento\b'
            .'|esta\s+mensagem\s+de\s+e-mail\s+foi\s+enviada\s+a\s+partir\s+de\s+um\s+endere[cç]o\s+de\s+notifica[cç][aã]o\b'
            .'|connect\s+with\s+copart\b';

        if (! preg_match('/mensagem\s+de\s+erro\s*:\s*(.*?)(?:\s*(?:'.$footerPattern.')|\z)/iu', $body, $errorMatches)
            || trim($errorMatches[1]) === '') {
            return ['valid' => false, 'reason' => 'invalid_body', 'cte_number' => $subjectMatches[1]];
        }

        $error = trim($errorMatches[1]);
        $number = $subjectMatches[1];

        return [
            'valid' => true,
            'cte_number' => $number,
            'error_message' => $error,
            'notes' => "Identificamos um problema na validação dos dados do documento de transporte número:\n\n{$number}\n- Mensagem de Erro:\n\n{$error}",
        ];
    }
}
