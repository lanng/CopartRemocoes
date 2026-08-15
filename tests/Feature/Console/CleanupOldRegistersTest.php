<?php

namespace Tests\Feature\Console;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CleanupOldRegistersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_old_registers_and_their_cte_documents_and_empty_batches(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
        ]);
        $batch = $this->createBatch();

        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);

        $this->artisan('app:cleanup-old-registers')
            ->assertSuccessful();

        $this->assertModelMissing($register);
        $this->assertDatabaseMissing('cte_documents', ['register_id' => $register->id]);
        $this->assertModelMissing($batch);
    }

    public function test_it_keeps_a_cte_batch_when_it_still_has_documents(): void
    {
        $oldRegister = Register::factory()->create([
            'status' => RegisterStatusEnum::PAID,
            'updated_at' => now()->subDays(16),
        ]);
        $currentRegister = Register::factory()->create();
        $batch = $this->createBatch();

        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $oldRegister->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $currentRegister->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);

        $this->artisan('app:cleanup-old-registers')
            ->assertSuccessful();

        $this->assertModelMissing($oldRegister);
        $this->assertModelExists($currentRegister);
        $this->assertModelExists($batch);
        $this->assertDatabaseCount('cte_documents', 1);
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
}
