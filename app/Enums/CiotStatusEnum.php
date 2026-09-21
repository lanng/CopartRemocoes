<?php

namespace App\Enums;

enum CiotStatusEnum: string
{
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case ISSUED = 'issued';
    case CANCELED = 'canceled';
    case CLOSED = 'closed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::PENDING => 'Emitindo',
            self::ISSUED => 'Emitido',
            self::CANCELED => 'Cancelado',
            self::CLOSED => 'Encerrado',
            self::FAILED => 'Falhou',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::PENDING => 'info',
            self::ISSUED => 'warning',
            self::CLOSED => 'success',
            self::CANCELED, self::FAILED => 'danger',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::ISSUED;
    }
}
