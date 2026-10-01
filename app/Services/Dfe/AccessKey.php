<?php

namespace App\Services\Dfe;

/**
 * Chave de acesso dos DF-e (spec §2.2): cUF + AAMM + CNPJ + modelo + série +
 * número + tpEmis + cCT (43 dígitos) e o cDV por mod11 com ciclo de pesos 2–9
 * da direita para a esquerda — NÃO é o ciclo 4,3,2,9 do CNPJ.
 */
class AccessKey
{
    public const MODELO_CTE = '57';

    public const MODELO_MDFE = '58';

    /**
     * @param  int|string  $serie  Série (3 dígitos, zero-padded).
     * @param  int|string  $number  nCT/nMDF (9 dígitos, zero-padded).
     * @param  int|string  $cct  Código aleatório de 8 dígitos, zero-padded.
     */
    public function build(
        string $cnpj,
        string $aamm,
        int|string $serie,
        int|string $number,
        string $modelo = self::MODELO_CTE,
        string $tpEmis = '1',
        int|string $cct = '',
    ): string {
        $key43 = str_pad((string) config('dfe.cuf', '35'), 2, '0', STR_PAD_LEFT)
            .$aamm
            .$this->onlyDigits($cnpj)
            .$modelo
            .str_pad((string) $serie, 3, '0', STR_PAD_LEFT)
            .str_pad((string) $number, 9, '0', STR_PAD_LEFT)
            .$tpEmis
            .str_pad((string) $cct, 8, '0', STR_PAD_LEFT);

        if (strlen($key43) !== 43) {
            throw new SefazException('Chave de acesso incompleta (43 dígitos esperados, '.strlen($key43).' gerados).');
        }

        return $key43.self::mod11($key43);
    }

    /**
     * cDV: mod11 com ciclo de pesos 2–9 da direita para a esquerda;
     * resto 0 → 11, dv >= 10 → 0. Autoteste obrigatório contra chaves reais
     * autorizadas (spec §2.2).
     */
    public static function mod11(string $key43): string
    {
        $weight = 2;
        $sum = 0;

        for ($i = strlen($key43) - 1; $i >= 0; $i--) {
            $sum += (int) $key43[$i] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }

        $dv = 11 - ($sum % 11);

        return (string) ($dv >= 10 ? 0 : $dv);
    }

    protected function onlyDigits(string $value): string
    {
        return (string) preg_replace('/\D/', '', $value);
    }
}
