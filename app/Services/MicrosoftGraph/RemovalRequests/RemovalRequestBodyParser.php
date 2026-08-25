<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

class RemovalRequestBodyParser
{
    private const FIELD_BOUNDARY = '(?:;|\n|\z|remetente\s*:|destinat[áa]rio\s*:|p[aá]tio\s+(?:de\s+)?destino\b|valor\s+(?:total\s+da\s+mercadoria|total\s+do\s+serviço|da\s+fipe|(?:de\s+)?frete)\b|data\s+(?:para\s+retirar|limite\s+de\s+entrega)\b|c[oó]digo\s+(?:do\s+)?ve[ií]culo\b)';

    /**
     * @return array{valid: bool, data: array<string, string>, missing_fields: list<string>}
     */
    public function parse(string $body): array
    {
        $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = str_replace(["\xC2\xA0", "\r\n", "\r"], [' ', "\n", "\n"], $body);
        $body = preg_replace('/[^\S\n]+/u', ' ', $body);
        $body = preg_replace('/\n+/u', "\n", (string) $body);
        $body = trim((string) $body);
        $normalizer = new RemovalRequestNormalizer;
        $data = [];

        if (preg_match('~c[oó]digo\s+do\s+transporte\s*:?\s*(T[A-Z0-9]+)~iu', $body, $matches)) {
            $data['payment_code'] = mb_strtoupper((string) $normalizer->identifier($matches[1]), 'UTF-8');
        }

        if (preg_match('~remetente\s*:\s*(?:dados\s+do\s+)?comitente\s+([^\n;]+?)(?=[ \t]*'.self::FIELD_BOUNDARY.')~iu', $body, $matches)) {
            $value = $normalizer->insurance($matches[1]);

            if ($value !== null) {
                $data['insurance'] = $value;
            }
        }

        if (preg_match('~p[aá]tio\s+(?:de\s+)?destino\s*:?\s*([^;]+?)\s*-\s*[A-Z]{2}\b~iu', $body, $matches)) {
            $value = $normalizer->text($matches[1]);

            if ($value !== null) {
                $data['destination_city'] = $value;
            }
        }

        $value = $this->extractMoney($body, 'fipe', $normalizer);

        if ($value !== null) {
            $data['fipe_value'] = $value;
        }

        $value = $this->extractMoney($body, '(?:valor\s+de\s+frete|valor\s+frete|frete)', $normalizer);

        if ($value !== null) {
            $data['value'] = $value;
        }

        $value = $this->extractDate($body, 'data\s+para\s+retirar\s+o\s+ve[ií]culo\s+da\s+oficina', $normalizer);

        if ($value !== null) {
            $data['deadline_withdraw'] = $value;
        }

        $value = $this->extractDate($body, 'data\s+limite\s+de\s+entrega\s+no\s+p[aá]tio', $normalizer);

        if ($value !== null) {
            $data['deadline_delivery'] = $value;
        }

        if (preg_match('~c[oó]digo\s+(?:do\s+)?ve[ií]culo\s*:?\s*(\d+)(?=[ \t]*'.self::FIELD_BOUNDARY.')~iu', $body, $matches)) {
            $data['vehicle_id'] = $normalizer->identifier($matches[1]);
        }

        $requiredFields = [
            'payment_code',
            'insurance',
            'destination_city',
            'fipe_value',
            'value',
            'deadline_withdraw',
            'deadline_delivery',
            'vehicle_id',
        ];
        $missingFields = array_values(array_filter(
            $requiredFields,
            fn (string $field): bool => ! isset($data[$field]) || $data[$field] === ''
        ));

        return [
            'valid' => $missingFields === [],
            'data' => $data,
            'missing_fields' => $missingFields,
        ];
    }

    private function extractMoney(string $body, string $labelPattern, RemovalRequestNormalizer $normalizer): ?string
    {
        if (! preg_match(
            '~'.$labelPattern.'[ \t]*:?[ \t]*(?:r\$[ \t]*)?([^;\r\n]*?)(?:;|\r?\n|\z)~iu',
            $body,
            $matches
        )) {
            return null;
        }

        return $normalizer->decimal($matches[1]);
    }

    private function extractDate(string $body, string $labelPattern, RemovalRequestNormalizer $normalizer): ?string
    {
        if (! preg_match(
            '~'.$labelPattern.'\s*:?\s*(\d{2}[/-]\d{2}[/-]\d{4}|\d{4}-\d{2}-\d{2})(?=[ \t]*'.self::FIELD_BOUNDARY.')~iu',
            $body,
            $matches
        )) {
            return null;
        }

        return $normalizer->date($matches[1]);
    }
}
