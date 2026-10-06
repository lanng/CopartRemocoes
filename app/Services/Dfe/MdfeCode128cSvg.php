<?php

namespace App\Services\Dfe;

use DomainException;

class MdfeCode128cSvg
{
    private const PATTERNS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '233111',
    ];

    public function render(string $accessKey): string
    {
        if (! preg_match('/^\d{44}$/', $accessKey)) {
            throw new DomainException('O CODE-128C do DAMDFE exige somente a chave MDF-e de 44 dígitos.');
        }

        $codewords = [105];

        for ($index = 0; $index < strlen($accessKey); $index += 2) {
            $codewords[] = (int) substr($accessKey, $index, 2);
        }

        $checksum = 105;
        foreach (array_slice($codewords, 1) as $index => $codeword) {
            $checksum += $codeword * ($index + 1);
        }
        $codewords[] = $checksum % 103;
        $codewords[] = 106;

        $x = 10;
        $bars = '';

        foreach ($codewords as $codeword) {
            $pattern = self::PATTERNS[$codeword];

            if ($codeword === 106) {
                $pattern .= '2';
            }

            for ($position = 0; $position < strlen($pattern); $position++) {
                $width = (int) $pattern[$position];

                if ($position % 2 === 0) {
                    $bars .= '<rect x="'.$x.'" y="0" width="'.$width.'" height="80"/>';
                }

                $x += $width;
            }
        }

        $viewWidth = $x + 10;

        return '<svg xmlns="http://www.w3.org/2000/svg" width="90mm" height="30mm" viewBox="0 0 '.$viewWidth.' 100" role="img" aria-label="CODE-128C '.$accessKey.'">'
            .'<rect width="100%" height="100%" fill="#fff"/><g fill="#000">'.$bars.'</g></svg>';
    }
}
