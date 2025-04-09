<?php

namespace App\Enums;

enum RegisterStatusEnum: string
{
    case COLLECTED = 'collected';
    case DELIVERED = 'delivered';
    case INVOICED = 'invoiced';
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';
    case AVAILABLE = 'available';
    case PENDING_DAILY_RATES = 'pending daily rates';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COLLECTED => 'Collected',
            self::DELIVERED => 'Delivered',
            self::INVOICED => 'Invoiced',
            self::CANCELLED => 'Cancelled',
            self::AVAILABLE => 'Available',
            self::PENDING_DAILY_RATES => 'Pending daily rates'
        };
    }

    public function labelPt(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::COLLECTED => 'Coletado',
            self::DELIVERED => 'Entregue',
            self::INVOICED => 'Em nota fiscal',
            self::CANCELLED => 'Cancelado',
            self::AVAILABLE => 'Liberado',
            self::PENDING_DAILY_RATES => 'Diárias Pendentes',
        };
    }

    public function localizedLabel(): string
    {
        return app()->getLocale() === 'pt_BR'
            ? $this->labelPt()
            : $this->label();
    }

    public static function optionsWithLabels(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn ($case) => [
                $case->value => $case->localizedLabel()
            ])
            ->toArray();
    }

    public function color(): string
    {
        return match ($this) {
            self::COLLECTED => 'ok',
            self::DELIVERED, self::AVAILABLE => 'success',
            self::INVOICED => 'info',
            self::PENDING, self::PENDING_DAILY_RATES => 'warning',
            self::CANCELLED => 'danger',
        };
    }
}
