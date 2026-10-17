<?php

namespace App\Services\MicrosoftGraph\RemovalRequests;

use App\Models\IntegrationInboxItem;
use App\Services\MicrosoftGraph\ProcessChecklistEmail;
use App\Services\MicrosoftGraph\ProcessCteErrorEmail;

class RemovalRequestMessageRouter
{
    public function __construct(
        private readonly RemovalRequestSubjectParser $subjectParser,
        private readonly QueueRemovalRequestEmail $queueRemovalRequest,
        private readonly ProcessChecklistEmail $checklistProcessor,
        private readonly ProcessCteErrorEmail $cteErrorProcessor,
    ) {}

    /** @param array<string, mixed> $message */
    public function handle(array $message): ?IntegrationInboxItem
    {
        $sender = strtolower(trim((string) ($message['sender'] ?? '')));
        $subject = trim((string) ($message['subject'] ?? ''));

        if ($sender === 'remocao@copart.com.br' && $this->subjectParser->parse($subject) !== null) {
            return $this->queueRemovalRequest->handle($message);
        }

        return $this->cteErrorProcessor->handle($message)
            ?? $this->checklistProcessor->handle($message);
    }
}
