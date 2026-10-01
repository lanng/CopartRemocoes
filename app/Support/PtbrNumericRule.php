<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida números digitados no padrão brasileiro ("4.132,98"), aceitando
 * também o canônico com ponto ("4132.98"). O valor permanece o estado bruto
 * do formulário — a conversão final é feita por PtbrNumeric::normalize()
 * no dehydrate do campo, depois da validação.
 */
class PtbrNumericRule implements ValidationRule
{
    public function __construct(
        protected float $min,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (blank($value)) {
            return;
        }

        $normalized = PtbrNumeric::normalize($value);

        if ($normalized === null) {
            $fail('O campo :attribute não é um número válido. Use o formato brasileiro, por exemplo 1.234,56.');

            return;
        }

        if ((float) $normalized < $this->min) {
            $fail($this->min > 0
                ? sprintf('O campo :attribute deve ser maior que %s.', PtbrNumeric::format($this->min))
                : 'O campo :attribute não pode ser negativo.');
        }
    }
}
