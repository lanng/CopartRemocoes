<?php

namespace App\Enums;

enum CteEmissionBatchStatusEnum: string
{
    case DRAFT = 'draft';
    case APPROVED = 'approved';
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case COMPLETED_WITH_ERRORS = 'completed_with_errors';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::APPROVED => 'Aprovado',
            self::PROCESSING => 'Em processamento',
            self::COMPLETED => 'Concluído',
            self::COMPLETED_WITH_ERRORS => 'Concluído com erros',
            self::CANCELLED => 'Cancelado',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::APPROVED => 'info',
            self::PROCESSING => 'warning',
            self::COMPLETED => 'success',
            self::COMPLETED_WITH_ERRORS => 'danger',
            self::CANCELLED => 'danger',
        };
    }
}
