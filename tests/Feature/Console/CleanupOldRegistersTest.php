<?php

namespace Tests\Feature\Console;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\CteDocument;
use App\Models\CteDocumentNfe;
use App\Models\CteEmissionBatch;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchItem;
use App\Models\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupOldRegistersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_preserves_authorized_documents_after_old_register_cleanup(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
        ]);
        $batch = $this->createBatch();

        $document = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);

        $this->artisan('app:cleanup-old-registers')
            ->assertSuccessful();

        $this->assertModelMissing($register);
        $this->assertDatabaseMissing('cte_documents', ['register_id' => $register->id]);
        $this->assertModelExists($document->refresh());
        $this->assertNull($document->register_id);
        $this->assertModelExists($batch->refresh());
    }

    public function test_it_deletes_both_register_pdfs_when_an_old_register_is_cleaned_up(): void
    {
        Storage::fake('s3');
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
            'pdf_path' => 'registros/copart/123/current/CartaDeRemoção ABC1D23.pdf',
            'consignor_letter_path' => 'registros/copart/123/current/CartaDoComitente ABC1D23.pdf',
        ]);
        Storage::disk('s3')->put($register->pdf_path, '%PDF-removal');
        Storage::disk('s3')->put($register->consignor_letter_path, '%PDF-consignor');

        $this->artisan('app:cleanup-old-registers')
            ->assertSuccessful();

        Storage::disk('s3')->assertMissing([
            $register->pdf_path,
            $register->consignor_letter_path,
        ]);
    }

    public function test_it_keeps_a_cte_batch_when_it_still_has_documents(): void
    {
        $oldRegister = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
        ]);
        $currentRegister = Register::factory()->create();
        $batch = $this->createBatch();

        $oldDocument = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $oldRegister->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);
        $currentDocument = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $currentRegister->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $this->artisan('app:cleanup-old-registers')
            ->assertSuccessful();

        $this->assertModelMissing($oldRegister);
        $this->assertModelExists($currentRegister);
        $this->assertModelExists($batch);
        $this->assertDatabaseCount('cte_documents', 2);
        $this->assertNull($oldDocument->refresh()->register_id);
        $this->assertSame($currentRegister->id, $currentDocument->refresh()->register_id);
    }

    public function test_it_does_not_delete_recent_or_non_cleanup_registers(): void
    {
        $recentPaid = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(14),
        ]);
        $delivered = Register::factory()->create([
            'status' => RegisterStatusEnum::DELIVERED,
            'updated_at' => now()->subDays(20),
        ]);

        $this->artisan('app:cleanup-old-registers')
            ->assertSuccessful();

        $this->assertModelExists($recentPaid);
        $this->assertModelExists($delivered);
    }

    public function test_it_removes_payment_items_but_preserves_confirmed_batch_history(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'value' => '123.45',
            'updated_at' => now()->subDays(16),
        ]);
        $batch = PaymentBatch::factory()->create([
            'status' => 'confirmed',
            'total_amount' => '123.45',
            'confirmed_at' => now()->subDays(10),
        ]);
        PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
            'amount' => '123.45',
        ]);

        $this->artisan('app:cleanup-old-registers')->assertSuccessful();

        $this->assertModelMissing($register);
        $this->assertDatabaseMissing('payment_batch_items', ['payment_batch_id' => $batch->id]);
        $this->assertDatabaseHas('payment_batches', [
            'id' => $batch->id,
            'total_amount' => '123.45',
        ]);
    }

    public function test_it_recalculates_a_pending_batch_when_deleting_an_old_register(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
        ]);
        $batch = PaymentBatch::factory()->create([
            'status' => 'pending',
            'total_amount' => '300.00',
        ]);
        PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'register_id' => $register->id,
            'amount' => '100.00',
        ]);
        PaymentBatchItem::factory()->create([
            'payment_batch_id' => $batch->id,
            'amount' => '200.00',
        ]);

        $this->artisan('app:cleanup-old-registers')->assertSuccessful();

        $this->assertModelExists($batch);
        $this->assertSame('200.00', $batch->refresh()->total_amount);
    }

    private function createBatch(): CteEmissionBatch
    {
        $user = User::factory()->create();

        return CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'created_by' => $user->id,
            'approved_by' => $user->id,
            'approved_at' => Carbon::now(),
        ]);
    }

    public function test_all_non_draft_documents_survive_cleanup_detached_from_the_register(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
        ]);
        $batch = $this->createBatch();

        $direct = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'emitter' => 'direct',
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);

        $lab = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'emitter' => 'lab',
        ]);

        $this->artisan('app:cleanup-old-registers')->assertSuccessful();

        $this->assertModelMissing($register);
        $this->assertModelExists($lab->refresh());
        $this->assertNull($lab->register_id);

        $direct->refresh();

        $this->assertModelExists($direct);
        $this->assertNull($direct->register_id);
        $this->assertNotNull($direct->access_key);
        $this->assertModelExists($batch->refresh());
    }

    public function test_cleanup_deletes_drafts_but_keeps_and_detaches_authorized_documents(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
        ]);
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::DRAFT,
        ]);
        $authorized = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
        ]);
        $draft = CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::DRAFT,
        ]);
        $nfe = CteDocumentNfe::query()->create([
            'cte_document_id' => $draft->id,
            'access_key' => str_repeat('1', 44),
            'xml_path' => 'nfe/'.str_repeat('1', 44).'/original.xml',
            'sha256' => str_repeat('a', 64),
            'extracted_data' => [],
        ]);

        $this->artisan('app:cleanup-old-registers')->assertSuccessful();

        $this->assertModelMissing($register);
        $this->assertModelExists($authorized->refresh());
        $this->assertNull($authorized->register_id);
        $this->assertModelMissing($draft);
        $this->assertModelMissing($nfe);
        $this->assertModelExists($batch->refresh());
    }
}
