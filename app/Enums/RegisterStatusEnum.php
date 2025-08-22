<?php
namespace App\Enums;

enum RegisterStatusEnum: string {
    case COLLECTED           = 'collected';
    case PAID                = 'paid';
    case INVOICED            = 'invoiced';
    case PENDING             = 'pending';
    case CANCELLED           = 'cancelled';
    case AVAILABLE           = 'available';
    case PENDING_DAILY_RATES = 'pending daily rates';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COLLECTED => 'Collected',
            self::PAID => 'Paid',
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
            self::PAID => 'Pago',
            self::INVOICED => 'Em nota fiscal',
            self::CANCELLED => 'Cancelado',
            self::AVAILABLE => 'Liberado',
            self::PENDING_DAILY_RATES => 'Pen. Diária',
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
            ->mapWithKeys(fn($case) => [
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
            self::INVOICED => 'invoiced',
            self::PENDING => 'waiting',
            self::PENDING_DAILY_RATES => 'warning',
            self::CANCELLED => 'danger',
        };
    }
}
