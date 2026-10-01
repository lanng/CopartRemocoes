<?php

namespace App\Support;

class PtbrNumeric
{
    /**
     * Converte a entrada do usuário para o formato canônico (ponto decimal,
     * sem separador de milhar), aceitando o padrão brasileiro: '.' para
     * milhar e ',' para decimal (ex.: "4.132,98" → "4132.98").
     *
     * Sem vírgula, pontos são milhar apenas quando todos os grupos seguintes
     * têm exatamente 3 dígitos ("1.500" → "1500", "1.234.567" → "1234567");
     * caso contrário o valor já é decimal canônico ("4132.98" permanece).
     * Devolve null quando não é possível interpretar o valor.
     */
    public static function normalize(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = str_replace(["\u{00A0}", ' '], '', trim($value));

        if (stripos($value, 'R$') === 0) {
            $value = substr($value, 2);
        }

        if ($value === '') {
            return null;
        }

        $sign = '';

        if (str_starts_with($value, '-')) {
            $sign = '-';
            $value = substr($value, 1);
        } elseif (str_starts_with($value, '+')) {
            $value = substr($value, 1);
        }

        if (! preg_match('/^[\d.,]+$/', $value)) {
            return null;
        }

        if (str_contains($value, ',')) {
            // "4,132.98" não é formato brasileiro nem canônico — rejeita
            // em vez de interpretar errado.
            if (preg_match('/,\d*\./', $value)) {
                return null;
            }

            $value = str_replace(',', '.', str_replace('.', '', $value));

            return preg_match('/^\d+(\.\d+)?$/', $value) ? $sign.$value : null;
        }

        $groups = explode('.', $value);

        if (self::isThousandGrouped($groups)) {
            return $sign.implode('', $groups);
        }

        return preg_match('/^\d+(\.\d+)?$/', $value) ? $sign.$value : null;
    }

    /**
     * Formata um valor numérico para exibição no padrão brasileiro
     * (ex.: "4132.98" → "4.132,98"). Entrada nula ou ilegível devolve null.
     */
    public static function format(mixed $value, int $decimals = 2): ?string
    {
        $normalized = self::normalize($value);

        if ($normalized === null) {
            return null;
        }

        return number_format((float) $normalized, $decimals, ',', '.');
    }

    /**
     * @param  list<string>  $groups
     */
    protected static function isThousandGrouped(array $groups): bool
    {
        $first = array_shift($groups);

        if ($first === '' || $first === '0' || str_starts_with($first, '0')) {
            return false;
        }

        foreach ($groups as $group) {
            if (strlen($group) !== 3) {
                return false;
            }
        }

        return $groups !== [];
    }
}
