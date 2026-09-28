<?php

namespace App\Filament\Support;

use App\Enums\CiotEmissionOutcome;
use App\Services\Ciot\CiotEmissionResult;
use Filament\Notifications\Notification;

/**
 * Configuração de strings das notificações de emissão de CIOT — defaults
 * verbatim das coreografias originais; cada página pode sobrescrever
 * (ex.: `queuedTitle`) sem perder o mapeamento outcome -> notificação.
 */
class CiotEmissionNotice
{
    public function __construct(
        public readonly string $issuedTitle = 'CIOT emitido: ',
        public readonly string $enqueuedTitle = 'Emissão enfileirada.',
        public readonly string $queuedTitle = 'CIOT criado — emissão em processamento',
        public readonly string $queuedBody = 'A ANTT não respondeu agora; a emissão ficou na fila com retry automático.',
        public readonly string $rejectedTitle = 'Emissão rejeitada pela ANTT',
        public readonly string $invalidTitle = 'Falha ao emitir o CIOT',
        public readonly string $erroredTitle = 'Erro inesperado ao emitir o CIOT',
        public readonly string $erroredBody = 'Tente novamente; se persistir, contate o suporte.',
    ) {}

    /**
     * Preset da reemissão pelo lote (strings verbatim do reemitCiot).
     */
    public static function reemission(): self
    {
        return new self(
            issuedTitle: 'CIOT reemitido: ',
            queuedTitle: 'Reemissão em processamento',
            queuedBody: 'A ANTT não respondeu agora; a reemissão ficou na fila com retry automático.',
            rejectedTitle: 'Reemissão rejeitada pela ANTT',
        );
    }

    public function send(CiotEmissionResult $result): void
    {
        $notification = match ($result->outcome) {
            CiotEmissionOutcome::Issued => Notification::make()
                ->title($this->issuedTitle.$result->fullNumber())
                ->success(),
            CiotEmissionOutcome::Enqueued => Notification::make()
                ->title($this->enqueuedTitle)
                ->success(),
            CiotEmissionOutcome::Queued => Notification::make()
                ->title($this->queuedTitle)
                ->body($this->queuedBody)
                ->warning(),
            CiotEmissionOutcome::Failed => Notification::make()
                ->title($this->rejectedTitle)
                ->body($result->message)
                ->danger(),
            CiotEmissionOutcome::Invalid => Notification::make()
                ->title($this->invalidTitle)
                ->body($result->message)
                ->danger(),
            CiotEmissionOutcome::Errored => Notification::make()
                ->title($this->erroredTitle)
                ->body($this->erroredBody)
                ->danger(),
        };

        $notification->send();
    }
}
