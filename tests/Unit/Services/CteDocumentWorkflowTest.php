<?php

namespace Tests\Unit\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Services\Cte\CteDocumentWorkflow;
use Tests\TestCase;

class CteDocumentWorkflowTest extends TestCase
{
    public function test_a_queued_document_can_move_to_claimed_and_filling(): void
    {
        $document = new CteDocument(['status' => CteDocumentStatusEnum::QUEUED]);
        $workflow = new CteDocumentWorkflow;

        $workflow->transition($document, CteDocumentStatusEnum::CLAIMED);
        $workflow->transition($document, CteDocumentStatusEnum::FILLING);

        $this->assertSame(CteDocumentStatusEnum::FILLING, $document->status);
    }

    public function test_authorizing_document_cannot_return_to_the_queue(): void
    {
        $document = new CteDocument(['status' => CteDocumentStatusEnum::AUTHORIZING]);
        $workflow = new CteDocumentWorkflow;

        $this->expectException(\DomainException::class);

        $workflow->transition($document, CteDocumentStatusEnum::QUEUED);
    }
}
