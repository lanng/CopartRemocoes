<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use Illuminate\Support\Str;

class RemovalRequestNormalizer
{
    public function plate(?string $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        return mb_strtoupper((string) preg_replace('/[\s-]+/u', '', $value), 'UTF-8');
    }

    public function identifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(str_replace("\xC2\xA0", ' ', $value));

        return $value === '' ? null : $value;
    }

    public function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\xC2\xA0", ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function insurance(?string $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        $transliterated = Str::ascii($value);
        $containsNonLatin = preg_match('/[^\p{Latin}\p{N}\p{P}\p{S}\p{Z}]/u', $value) === 1;

        if (! $containsNonLatin && preg_match('/[\p{L}\p{N}]/u', $transliterated) === 1) {
            $value = $transliterated;
        }

        $value = mb_strtoupper($value, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);

        return $this->text($value);
    }

    public function decimal(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value < 0 ? null : number_format($value, 2, '.', '');
        }

        if (is_float($value)) {
            if (! is_finite($value) || $value < 0) {
                return null;
            }

            $normalized = rtrim(rtrim(sprintf('%.15f', $value), '0'), '.');

            $decimalSeparatorPosition = strpos($normalized, '.');

            if ($decimalSeparatorPosition !== false && strlen($normalized) - $decimalSeparatorPosition - 1 > 2) {
                return null;
            }

            return number_format($value, 2, '.', '');
        }

        $value = trim(str_replace("\xC2\xA0", ' ', $value));

        $value = preg_replace('/^r\$\s*/iu', '', $value);
        $value = trim((string) $value);

        if ($value === '' || str_starts_with($value, '-') || preg_match('/\s/u', $value)) {
            return null;
        }

        if (preg_match('/^([+-]?)(\d+)$/', $value, $matches)) {
            return $this->formatDecimalParts($matches[1], $matches[2], '');
        }

        if (preg_match('/^([+-]?)(\d+)\.(\d{1,2})$/', $value, $matches)) {
            return $this->formatDecimalParts($matches[1], $matches[2], $matches[3]);
        }

        if (preg_match('/^([+-]?)(\d+|\d{1,3}(?:\.\d{3})+),(\d{1,2})$/', $value, $matches)) {
            return $this->formatDecimalParts($matches[1], str_replace('.', '', $matches[2]), $matches[3]);
        }

        return null;
    }

    private function formatDecimalParts(string $sign, string $integer, string $fraction): string
    {
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($fraction, 2, '0');

        return ($sign === '-' ? '-' : '').$integer.'.'.substr($fraction, 0, 2);
    }

    public function date(?string $value): ?string
    {
        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        foreach (['d/m/Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                continue;
            }

            if ($date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    public function equivalent(string $field, mixed $left, mixed $right): bool
    {
        $field = mb_strtolower($field, 'UTF-8');

        $normalize = match (true) {
            in_array($field, ['plate', 'vehicle_plate'], true) => fn (mixed $value): ?string => $this->plate($this->stringValue($value)),
            in_array($field, ['id', 'identifier', 'vehicle_id', 'payment_code'], true) => fn (mixed $value): ?string => $this->identifier($this->stringValue($value)),
            $field === 'insurance' => fn (mixed $value): ?string => $this->insurance($this->stringValue($value)),
            in_array($field, ['decimal', 'fipe', 'fipe_value', 'value'], true) => fn (mixed $value): ?string => $this->decimal($this->decimalValue($value)),
            in_array($field, ['date', 'deadline_delivery', 'deadline_withdraw'], true) => fn (mixed $value): ?string => $this->date($this->stringValue($value)),
            in_array($field, ['text', 'destination_city'], true) => fn (mixed $value): ?string => $this->text($this->stringValue($value)),
            default => throw new \InvalidArgumentException("Unknown normalization field [{$field}]."),
        };

        return $normalize($left) === $normalize($right);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function decimalValue(mixed $value): string|int|float|null
    {
        return is_string($value) || is_int($value) || is_float($value) || $value === null ? $value : null;
    }
}
