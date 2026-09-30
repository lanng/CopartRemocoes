<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\CreateCteEmissionBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApproveCteEmissionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_approval_records_the_user_and_queues_documents(): void
    {
        $user = User::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(
            Register::factory()->count(2)->create(),
            $user,
            'live',
        );

        $approved = app(ApproveCteEmissionBatch::class)->handle($batch, $user);

        $this->assertSame(CteEmissionBatchStatusEnum::APPROVED, $approved->status);
        $this->assertSame($user->id, $approved->approved_by);
        $this->assertNotNull($approved->approved_at);
        $this->assertTrue($approved->documents->every(
            fn ($document): bool => $document->status === CteDocumentStatusEnum::QUEUED
        ));
    }

    public function test_approval_rejects_a_register_changed_after_the_snapshot_was_created(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(collect([$register]), $user, 'dry_run');
        $register->update(['value' => '999.00']);

        $this->expectException(ValidationException::class);

        app(ApproveCteEmissionBatch::class)->handle($batch, $user);
    }

    public function test_approval_supersedes_the_previous_authorized_cte_of_a_reemission(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create();
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
        );
        $newDocument = $batch->documents->first();

        app(ApproveCteEmissionBatch::class)->handle($batch, $user);

        $this->assertSame(CteDocumentStatusEnum::SUPERSEDED, $oldDocument->refresh()->status);
        $this->assertSame('900100', $oldDocument->cte_number);
        $this->assertSame(CteDocumentStatusEnum::QUEUED, $newDocument->refresh()->status);
        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $oldBatch->refresh()->status);
    }

    public function test_approval_fails_when_the_replaced_cte_is_no_longer_authorized(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create();
        $oldDocument = CteDocument::factory()->authorized()->create(['register_id' => $register->id]);

        $batch = app(CreateCteEmissionBatch::class)->handle(
            collect([$register]),
            $user,
            'dry_run',
            reemissionConfirmed: true,
        );

        $oldDocument->update(['status' => CteDocumentStatusEnum::CANCELLED]);

        try {
            app(ApproveCteEmissionBatch::class)->handle($batch, $user);
            $this->fail('A approval whose replaced CT-e is no longer authorized should fail.');
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->implode(' ');
            $this->assertStringContainsString('nao esta mais autorizado', $messages);
        }

        $this->assertSame(CteEmissionBatchStatusEnum::DRAFT, $batch->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $batch->documents->first()->refresh()->status);
    }
}
