<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Models\MdfeEvent;
use App\Services\Dfe\DeleteDraftMdfeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DeleteDraftMdfeDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_an_unsubmitted_standalone_manifest_and_releases_links_and_reservations(): void
    {
        $ciot = Ciot::factory()->issued()->create();
        $cte = CteDocument::factory()->authorized()->create();
        $manifest = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::DRAFT,
            'fiscal_status' => FiscalDocumentStatusEnum::NOT_AUTHORIZED,
        ]);
        $manifest->cteDocuments()->attach($cte->id, ['position' => 1]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
            'mdfe_document_id' => $manifest->id,
        ]);
        DfeDocumentReservation::query()->create([
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $manifest->id,
        ]);

        app(DeleteDraftMdfeDocument::class)->handle($manifest);

        $this->assertModelMissing($manifest);
        $this->assertModelExists($ciot);
        $this->assertModelExists($cte);
        $this->assertDatabaseMissing('cte_document_mdfe_document', [
            'mdfe_document_id' => $manifest->id,
        ]);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'mdfe_document_id' => $manifest->id,
        ]);
    }

    public function test_it_refuses_to_delete_a_manifest_with_fiscal_or_transmission_evidence(): void
    {
        $manifest = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::DRAFT,
            'access_key' => str_repeat('1', 44),
            'mdfe_number' => '900123',
            'payload_xml' => '<MDFe/>',
        ]);

        $this->expectException(ValidationException::class);

        app(DeleteDraftMdfeDocument::class)->handle($manifest);
    }

    public function test_it_refuses_to_delete_a_manifest_with_an_event_record(): void
    {
        $manifest = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        MdfeEvent::factory()->create([
            'mdfe_document_id' => $manifest->id,
            'event_type' => '110111',
            'attempt' => 1,
            'status' => 'rejected',
        ]);

        $this->expectException(ValidationException::class);

        app(DeleteDraftMdfeDocument::class)->handle($manifest);
    }
}
