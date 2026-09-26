<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Models\MdfeDocument;
use App\Services\Cte\MdfeDocumentWorkflow;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdfeDocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * O agente do MDF-e não percorre a coreografia completa do CT-e no Lab:
     * pode saltar estágios intermediários, sempre para frente.
     */
    public function test_progress_may_skip_pipeline_stages_forward(): void
    {
        $workflow = app(MdfeDocumentWorkflow::class);

        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::FILLING,
        ]);

        $workflow->transition($document, CteDocumentStatusEnum::READY_TO_AUTHORIZE);

        $this->assertSame(CteDocumentStatusEnum::READY_TO_AUTHORIZE, $document->refresh()->status);

        $workflow->transition($document, CteDocumentStatusEnum::WAITING_FOR_XML);

        $this->assertSame(CteDocumentStatusEnum::WAITING_FOR_XML, $document->refresh()->status);
    }

    public function test_progress_cannot_move_backwards(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::VALIDATING,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot transition a validating document to filling.');

        app(MdfeDocumentWorkflow::class)->transition($document, CteDocumentStatusEnum::FILLING);
    }

    public function test_authorized_requires_the_fiscal_barrier(): void
    {
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::FILLING,
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Cannot transition a filling document to authorized.');

        app(MdfeDocumentWorkflow::class)->transition($document, CteDocumentStatusEnum::AUTHORIZED);
    }
}
