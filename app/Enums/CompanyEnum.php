<?php

namespace App\Enums;

enum CompanyEnum: string
{
    case COPART = 'copart';
    case MILLAN = 'millan';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::COPART => 'Copart',
            self::MILLAN => 'Millan',
        };
    }
}
