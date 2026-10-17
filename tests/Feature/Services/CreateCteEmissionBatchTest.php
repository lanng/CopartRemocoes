<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
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

    public function test_it_creates_ctes_for_the_internal_emitter_as_controlled_workflow_documents(): void
    {
        $batch = app(CreateCteEmissionBatch::class)->handle(
            Register::factory()->count(1)->create(),
            User::factory()->create(),
            'live',
        );

        $this->assertSame('controlled_v1', $batch->documents->first()->getRawOriginal('workflow_origin'));
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
            'emitter' => 'lab',
            'workflow_origin' => null,
        ]);

        $this->expectException(ValidationException::class);

        app(CreateCteEmissionBatch::class)->handle(collect([$register]), $user, 'dry_run');
    }

    public function test_it_creates_a_replacement_document_when_the_reemission_is_confirmed(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create(['status' => 'analysis']);
        $oldBatch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
            'execution_mode' => 'live',
        ]);
        $oldDocument = CteDocument::factory()->authorized()->create([
            'register_id' => $register->id,
            'cte_emission_batch_id' => $oldBatch->id,
            'cte_number' => '900100',
        ]);

        $batch = app(CreateCteEmissionBatch::class)->handle(
            collect([$register]),
            $user,
            'live',
            reemissionConfirmed: true,
            reemissionReason: 'Recusado pelo contratante',
        );

        $document = $batch->documents->first();
        $this->assertSame($oldDocument->id, $document->replaced_document_id);
        $this->assertSame('Recusado pelo contratante', $document->replacement_reason);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $oldDocument->refresh()->status);
        $this->assertSame('analysis', $register->refresh()->status->value);
    }

    public function test_it_links_only_the_registers_that_have_an_authorized_cte(): void
    {
        $user = User::factory()->create();
        $emitted = Register::factory()->create();
        $fresh = Register::factory()->create();
        $oldDocument = CteDocument::factory()->authorized()->create(['register_id' => $emitted->id]);

        $batch = app(CreateCteEmissionBatch::class)->handle(
            collect([$emitted, $fresh]),
            $user,
            'dry_run',
            reemissionConfirmed: true,
        );

        $documents = $batch->documents->keyBy('register_id');
        $this->assertSame($oldDocument->id, $documents[$emitted->id]->replaced_document_id);
        $this->assertNull($documents[$fresh->id]->replaced_document_id);
        $this->assertNull($documents[$fresh->id]->replacement_reason);
    }
}
