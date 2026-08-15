<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use App\Services\Cte\CreateCteEmissionBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CreateCteEmissionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_draft_batch_with_one_snapshot_per_register(): void
    {
        $user = User::factory()->create();
        $registers = Register::factory()->count(2)->create();

        $batch = app(CreateCteEmissionBatch::class)->handle($registers, $user, 'dry_run');

        $this->assertInstanceOf(CteEmissionBatch::class, $batch);
        $this->assertSame('draft', $batch->status->value);
        $this->assertSame('dry_run', $batch->execution_mode);
        $this->assertCount(2, $batch->documents);
        $this->assertTrue($batch->documents->every(
            fn ($document): bool => $document->status === CteDocumentStatusEnum::DRAFT
        ));
        $this->assertSame('T691299', $batch->documents->first()->snapshot['payment_code']);
    }

    public function test_it_rejects_a_register_that_already_has_an_authorized_cte(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create();
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
            'execution_mode' => 'live',
        ]);
        $register->cteDocuments()->create([
            'public_id' => fake()->uuid(),
            'cte_emission_batch_id' => $batch->id,
            'status' => 'authorized',
            'snapshot' => [],
            'idempotency_key' => fake()->uuid(),
            'execution_mode' => 'live',
        ]);

        $this->expectException(ValidationException::class);

        app(CreateCteEmissionBatch::class)->handle(collect([$register]), $user, 'dry_run');
    }
}
