<?php

namespace Tests\Feature\Models;

use App\Enums\CteDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteDocumentNfe;
use App\Models\CteEmissionBatch;
use App\Models\CteEvent;
use App\Models\MdfeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FiscalDocumentRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_mdfe_keeps_explicit_cte_and_ciot_links_without_a_batch(): void
    {
        $this->assertTrue(Schema::hasTable('cte_documents'));

        $cte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
        ]);
        $ciot = Ciot::factory()->create([
            'cte_emission_batch_id' => null,
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);

        $mdfe->cteDocuments()->attach($cte);
        $mdfe->cteDocuments()->updateExistingPivot($cte->id, ['position' => 1]);

        $historicalMdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::CANCELLED,
        ]);
        $historicalMdfe->cteDocuments()->attach($cte, ['position' => 0]);

        $nfeDocuments = collect([1, 2])->map(fn (int $number): CteDocumentNfe => CteDocumentNfe::query()->create([
            'cte_document_id' => $cte->id,
            'access_key' => str_repeat((string) $number, 44),
            'number' => (string) $number,
            'series' => '1',
            'source_filename' => "nfe-{$number}.xml",
            'xml_path' => "nfe/{$number}.xml",
            'sha256' => str_repeat('a', 64),
            'extracted_data' => ['cargo_value' => '100.00'],
        ]));
        $event = CteEvent::query()->create([
            'cte_document_id' => $cte->id,
            'event_type' => '110111',
            'sequence' => 1,
            'status' => 'recorded',
            'event_id' => 'ID110111'.str_repeat('1', 44).'01',
        ]);

        $this->assertTrue($mdfe->cteDocuments->contains($cte));
        $this->assertTrue($cte->mdfeDocuments->contains($mdfe));
        $this->assertTrue($mdfe->ciot->is($ciot));
        $this->assertTrue($cte->mdfeDocuments->contains($historicalMdfe));
        $this->assertCount(2, $cte->mdfeDocuments);
        $this->assertCount(2, $cte->nfeDocuments);
        $this->assertCount(1, $cte->events);
        $this->assertSame($nfeDocuments[0]->id, $cte->nfeDocuments->first()->id);
        $this->assertTrue($event->document->is($cte));
    }

    public function test_cte_and_mdfe_expose_fiscal_status_as_a_shared_enum(): void
    {
        $cte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => null,
            'register_id' => null,
            'fiscal_status' => 'authorized',
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => null,
            'fiscal_status' => 'closed',
        ]);

        $this->assertInstanceOf(\App\Enums\FiscalDocumentStatusEnum::class, $cte->refresh()->fiscal_status);
        $this->assertInstanceOf(\App\Enums\FiscalDocumentStatusEnum::class, $mdfe->refresh()->fiscal_status);
    }

    public function test_deleting_a_batch_detaches_but_preserves_its_fiscal_documents(): void
    {
        $batch = CteEmissionBatch::factory()->create();
        $cte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => null,
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
        ]);

        $batch->delete();

        $this->assertDatabaseHas('cte_documents', ['id' => $cte->id]);
        $this->assertDatabaseHas('mdfe_documents', ['id' => $mdfe->id]);
        $this->assertNull($cte->refresh()->cte_emission_batch_id);
        $this->assertNull($mdfe->refresh()->cte_emission_batch_id);
    }
}
