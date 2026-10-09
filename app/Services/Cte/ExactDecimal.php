<?php

namespace App\Services\Cte;

use DomainException;

final class ExactDecimal
{
    public static function parse(string $value, int $scale, string $filename, string $field): int
    {
        $value = trim($value);

        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            throw new DomainException("A NF-e {$filename} possui valor numérico inválido no campo {$field}.");
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        if (trim(substr($fraction, $scale), '0') !== '') {
            throw new DomainException("A NF-e {$filename} possui precisão numérica incompatível no campo {$field}.");
        }

        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $wholeValue = (int) $whole;
        $fractionValue = (int) $fraction;
        $factor = 10 ** $scale;

        if ($wholeValue > intdiv(PHP_INT_MAX - $fractionValue, $factor)) {
            throw new DomainException("A NF-e {$filename} excede o limite para cálculo exato no campo {$field}.");
        }

        return ($wholeValue * $factor) + $fractionValue;
    }

    public static function add(int $total, int $value, string $filename, string $field): int
    {
        if ($value > PHP_INT_MAX - $total) {
            throw new DomainException("A NF-e {$filename} excede o limite para cálculo exato no campo {$field}.");
        }

        return $total + $value;
    }

    public static function format(int $value, int $scale): string
    {
        $divisor = 10 ** $scale;

        return intdiv($value, $divisor).'.'.str_pad((string) ($value % $divisor), $scale, '0', STR_PAD_LEFT);
    }
}
