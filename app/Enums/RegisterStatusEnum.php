<?php

namespace App\Enums;

enum RegisterStatusEnum: string
{
    case COLLECTED = 'collected';
    case PAID = 'paid';
    case DELIVERED = 'delivered';
    case PENDING = 'pending';
    case CANCELLED = 'cancelled';
    case AVAILABLE = 'available';
    case PENDING_DAILY_RATES = 'pending daily rates';
    case INVOICED = 'invoiced';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COLLECTED => 'Collected',
            self::PAID => 'Paid',
            self::DELIVERED => 'Delivered',
            self::CANCELLED => 'Cancelled',
            self::AVAILABLE => 'Available',
            self::PENDING_DAILY_RATES => 'Pending daily rates',
            self::INVOICED => 'Invoiced'
        };
    }

    public function labelPt(): string
    {
        return match ($this) {
            self::PENDING => 'Pendente',
            self::COLLECTED => 'Coletado',
            self::PAID => 'Pago',
            self::DELIVERED => 'Entregue',
            self::CANCELLED => 'Cancelado',
            self::AVAILABLE => 'Liberado',
            self::PENDING_DAILY_RATES => 'Pen. Diária',
            self::INVOICED => 'Em nota fiscal',
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
                $case->value => $case->localizedLabel(),
            ])
            ->toArray();
    }

    public function color(): string
    {
        return match ($this) {
            self::COLLECTED => 'collected',
            self::PAID => 'success',
            self::AVAILABLE => 'available',
            self::DELIVERED => 'success',
            self::INVOICED => 'invoiced',
            self::PENDING => 'waiting',
            self::PENDING_DAILY_RATES => 'warning',
            self::CANCELLED => 'danger',
        };
    }
}
