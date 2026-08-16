<?php

namespace App\Enums;

enum PaymentBatchStatusEnum: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::CONFIRMED => 'Confirmado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::CONFIRMED => 'success',
        };
    }
}
