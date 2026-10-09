<?php

namespace Tests\Feature\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\User;
use App\Services\Cte\PersistCteDocumentNfes;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersistCteDocumentNfesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dfe');
    }

    public function test_archives_and_links_each_original_before_returning_the_consolidation(): void
    {
        $document = $this->draftDocument();
        $files = [
            $this->file('nfe-delivery-001.xml', 'first-upload.xml'),
            $this->file('nfe-delivery-002.xml', 'second-upload.xml'),
        ];

        $result = app(PersistCteDocumentNfes::class)->handle($document, $files);

        $this->assertSame('3000.00', $result['consolidated']['cargo_value']);
        $this->assertCount(2, $result['nfe_documents']);
        $this->assertDatabaseCount('cte_document_nfes', 2);

        foreach ($result['nfe_documents'] as $position => $nfeDocument) {
            $xml = $files[$position]['xml'];
            $accessKey = $result['documents'][$position]['access_key'];

            $this->assertSame($document->id, $nfeDocument->cte_document_id);
            $this->assertSame($accessKey, $nfeDocument->access_key);
            $this->assertSame($files[$position]['filename'], $nfeDocument->source_filename);
            $this->assertSame(hash('sha256', $xml), $nfeDocument->sha256);
            $this->assertSame($result['documents'][$position], $nfeDocument->extracted_data);
            $this->assertSame('nfe/'.$accessKey.'/'.$nfeDocument->sha256.'.xml', $nfeDocument->xml_path);
            Storage::disk('dfe')->assertExists($nfeDocument->xml_path);
            $this->assertSame($xml, Storage::disk('dfe')->get($nfeDocument->xml_path));
        }
    }

    public function test_replacing_draft_nfe_selection_removes_only_stale_links(): void
    {
        $document = $this->draftDocument();
        $service = app(PersistCteDocumentNfes::class);
        $first = $this->file('nfe-delivery-001.xml', 'first.xml');
        $second = $this->file('nfe-delivery-002.xml', 'second.xml');

        $initial = $service->handle($document, [$first, $second]);
        $stalePath = $initial['nfe_documents'][1]->xml_path;
        $updated = $service->handle($document, [$first]);

        $this->assertCount(1, $updated['nfe_documents']);
        $this->assertDatabaseCount('cte_document_nfes', 1);
        $this->assertSame($initial['documents'][0]['access_key'], $updated['nfe_documents'][0]->access_key);
        Storage::disk('dfe')->assertExists($stalePath);
    }

    public function test_reimporting_a_note_without_complements_preserves_existing_operator_complements(): void
    {
        $document = $this->draftDocument();
        $operator = User::factory()->create();
        $file = $this->file('nfe-delivery-001.xml', 'delivery.xml');
        $file['operator_complements'] = ['delivery' => ['street' => 'Rua preenchida pelo operador']];
        $service = app(PersistCteDocumentNfes::class);

        $service->handle($document, [$file], $operator);
        $service->handle($document, [$this->file('nfe-delivery-001.xml', 'delivery.xml')]);

        $nfeDocument = $document->nfeDocuments()->sole();
        $this->assertSame(['delivery' => ['street' => 'Rua preenchida pelo operador']], $nfeDocument->operator_complements);
        $this->assertSame($operator->id, $nfeDocument->operator_complements_user_id);
        $this->assertTrue($nfeDocument->operatorComplementsUser->is($operator));
    }

    public function test_requires_a_user_to_record_operator_complements(): void
    {
        $document = $this->draftDocument();
        $file = $this->file('nfe-delivery-001.xml', 'delivery.xml');
        $file['operator_complements'] = ['delivery' => ['street' => 'Rua preenchida pelo operador']];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('usuário responsável');

        app(PersistCteDocumentNfes::class)->handle($document, [$file]);
    }

    public function test_refuses_to_persist_files_for_a_document_that_is_not_a_draft(): void
    {
        $document = CteDocument::factory()->create(['status' => CteDocumentStatusEnum::QUEUED]);

        try {
            app(PersistCteDocumentNfes::class)->handle($document, [$this->file('nfe-delivery-001.xml', 'queued.xml')]);
            $this->fail('Only a persisted CT-e draft may receive NF-e originals.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('rascunho', mb_strtolower($exception->getMessage()));
        }

        $this->assertDatabaseCount('cte_document_nfes', 0);
        $this->assertSame([], Storage::disk('dfe')->allFiles());
    }

    public function test_rejects_too_many_files_before_parsing_or_archiving_them(): void
    {
        Config::set('dfe.nfe_xml_max_files_per_import', 1);
        $document = $this->draftDocument();
        $files = [
            $this->file('nfe-delivery-001.xml', 'first.xml'),
            $this->file('nfe-delivery-002.xml', 'second.xml'),
        ];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('limite de 1 NF-es por importação');

        try {
            app(PersistCteDocumentNfes::class)->handle($document, $files);
        } finally {
            $this->assertDatabaseCount('cte_document_nfes', 0);
            $this->assertSame([], Storage::disk('dfe')->allFiles());
        }
    }

    public function test_rejects_invalid_and_duplicate_nfes_before_archiving_any_original(): void
    {
        $document = $this->draftDocument();
        $invalidFile = ['filename' => 'malformed.xml', 'xml' => '<nfeProc>'];

        try {
            app(PersistCteDocumentNfes::class)->handle($document, [$invalidFile]);
            $this->fail('Malformed NF-e XML must not be archived or linked.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Não foi possível ler o XML', $exception->getMessage());
        }

        $duplicate = $this->file('nfe-delivery-001.xml', 'duplicate.xml');

        try {
            app(PersistCteDocumentNfes::class)->handle($document, [$duplicate, $duplicate]);
            $this->fail('A duplicate access key must be rejected before originals are archived.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('repetida na seleção', $exception->getMessage());
        }

        $this->assertDatabaseCount('cte_document_nfes', 0);
        $this->assertSame([], Storage::disk('dfe')->allFiles());
    }

    private function draftDocument(): CteDocument
    {
        return CteDocument::factory()->create(['status' => CteDocumentStatusEnum::DRAFT]);
    }

    /** @return array{filename: string, xml: string} */
    private function file(string $fixture, string $filename): array
    {
        return [
            'filename' => $filename,
            'xml' => (string) file_get_contents(base_path("tests/Fixtures/Dfe/Nfe/{$fixture}")),
        ];
    }
}
