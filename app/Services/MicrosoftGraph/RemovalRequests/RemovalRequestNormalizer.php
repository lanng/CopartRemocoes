<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

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

        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        $value = mb_strtoupper($ascii === false ? $value : $ascii, 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', '', $value);

        return $this->text($value);
    }

    public function decimal(string|int|float|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return number_format($value, 2, '.', '');
        }

        if (is_float($value)) {
            return is_finite($value) ? number_format($value, 2, '.', '') : null;
        }

        $value = $this->text($value);

        if ($value === null) {
            return null;
        }

        $value = preg_replace('/^r\$\s*/iu', '', $value);
        $value = str_replace(' ', '', (string) $value);

        if (! preg_match('/^[+-]?\d[\d,.]*$/', $value)) {
            return null;
        }

        $unsignedValue = ltrim($value, '+-');

        if (preg_match('/^[,.]|[,.]$|[,.]{2,}/', $unsignedValue)) {
            return null;
        }

        $commaPosition = strrpos($value, ',');
        $dotPosition = strrpos($value, '.');

        if ($commaPosition !== false && $dotPosition !== false) {
            if ($commaPosition > $dotPosition) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($commaPosition !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (substr_count($value, '.') === 1) {
            $decimalDigits = strlen($value) - $dotPosition - 1;

            if ($decimalDigits > 2) {
                $value = str_replace('.', '', $value);
            }
        } else {
            $value = str_replace('.', '', $value);
        }

        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
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
            in_array($field, ['id', 'identifier', 'vehicle_id'], true) => fn (mixed $value): ?string => $this->identifier($this->stringValue($value)),
            $field === 'insurance' => fn (mixed $value): ?string => $this->insurance($this->stringValue($value)),
            in_array($field, ['decimal', 'fipe', 'fipe_value', 'value'], true) => fn (mixed $value): ?string => $this->decimal($this->decimalValue($value)),
            in_array($field, ['date', 'deadline_delivery', 'deadline_withdraw'], true) => fn (mixed $value): ?string => $this->date($this->stringValue($value)),
            default => fn (mixed $value): ?string => $this->text($this->stringValue($value)),
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
