<?php

namespace App\Enums;

enum CiotOperationTypeEnum: string
{
    case Lotation = 'lotation';
    case Fractioned = 'fractioned';

    /**
     * Código do DCS: 1 = lotação, 2 = fracionada.
     */
    public function code(): int
    {
        return match ($this) {
            self::Lotation => 1,
            self::Fractioned => 2,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Lotation => 'Lotação',
            self::Fractioned => 'Fracionada',
        };
    }
}
