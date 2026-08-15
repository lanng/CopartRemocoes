<?php

namespace App\Enums;

enum CteDocumentStatusEnum: string
{
    case DRAFT = 'draft';
    case QUEUED = 'queued';
    case CLAIMED = 'claimed';
    case FILLING = 'filling';
    case VALIDATING = 'validating';
    case READY_TO_AUTHORIZE = 'ready_to_authorize';
    case AUTHORIZING = 'authorizing';
    case WAITING_FOR_XML = 'waiting_for_xml';
    case DRY_RUN_COMPLETED = 'dry_run_completed';
    case AUTHORIZED = 'authorized';
    case REJECTED = 'rejected';
    case FAILED_BEFORE_AUTHORIZATION = 'failed_before_authorization';
    case RECONCILIATION_REQUIRED = 'reconciliation_required';
    case CANCELLED = 'cancelled';
    case SUPERSEDED = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Rascunho',
            self::QUEUED => 'Na fila',
            self::CLAIMED => 'Capturado',
            self::FILLING => 'Preenchendo',
            self::VALIDATING => 'Validando',
            self::READY_TO_AUTHORIZE => 'Pronto para autorizar',
            self::AUTHORIZING => 'Autorizando',
            self::WAITING_FOR_XML => 'Aguardando XML',
            self::DRY_RUN_COMPLETED => 'Simulação concluída',
            self::AUTHORIZED => 'Autorizado',
            self::REJECTED => 'Rejeitado',
            self::FAILED_BEFORE_AUTHORIZATION => 'Falha antes da autorização',
            self::RECONCILIATION_REQUIRED => 'Reconciliação necessária',
            self::CANCELLED => 'Cancelado',
            self::SUPERSEDED => 'Substituído',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::DRAFT, self::SUPERSEDED => 'gray',
            self::QUEUED, self::CLAIMED => 'info',
            self::FILLING, self::VALIDATING, self::WAITING_FOR_XML, self::RECONCILIATION_REQUIRED => 'warning',
            self::READY_TO_AUTHORIZE, self::AUTHORIZING => 'primary',
            self::DRY_RUN_COMPLETED, self::AUTHORIZED => 'success',
            self::REJECTED, self::FAILED_BEFORE_AUTHORIZATION, self::CANCELLED => 'danger',
        };
    }
}
