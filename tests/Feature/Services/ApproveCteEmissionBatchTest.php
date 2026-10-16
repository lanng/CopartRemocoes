<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Jobs\EmitCteDirectJob;
use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use App\Services\Cte\ApproveCteEmissionBatch;
use App\Services\Cte\CreateCteEmissionBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ApproveCteEmissionBatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        City::factory()->create(['ibge_code' => '3534708', 'name' => 'Ourinhos', 'state' => 'SP']);
        City::factory()->create(['ibge_code' => '3539103', 'name' => 'Pirapora do Bom Jesus', 'state' => 'SP']);
        Company::factory()->create(['name' => 'ALLIANZ SEGUROS SA', 'ibge_code' => '3550308']);
        Company::factory()->create(['name' => 'Pátio Pirapora', 'ibge_code' => '3539103']);
    }

    public function test_approval_records_the_user_and_queues_documents(): void
    {
        $user = User::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(
            Register::factory()->count(2)->create(['status' => 'analysis']),
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
        $this->assertTrue($approved->documents->every(
            fn (CteDocument $document): bool => $document->register->status->value === 'analysis'
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

    public function test_approval_rejects_a_legacy_batch(): void
    {
        $user = User::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(
            Register::factory()->count(1)->create(),
            $user,
            'live',
        );
        $document = $batch->documents->firstOrFail();
        $document->forceFill(['emitter' => 'lab', 'workflow_origin' => null])->save();

        try {
            app(ApproveCteEmissionBatch::class)->handle($batch, $user);
            $this->fail('Legacy batches must remain read-only.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('batch', $exception->errors());
        }

        $this->assertSame(CteEmissionBatchStatusEnum::DRAFT, $batch->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::DRAFT, $document->refresh()->status);
    }

    public function test_approval_preserves_the_fiscal_status_of_the_previous_cte_of_a_reemission(): void
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
            'fiscal_status' => 'authorized',
        ]);

        $batch = app(CreateCteEmissionBatch::class)->handle(
            collect([$register]),
            $user,
            'live',
            reemissionConfirmed: true,
        );
        $newDocument = $batch->documents->first();

        app(ApproveCteEmissionBatch::class)->handle($batch, $user);

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $oldDocument->refresh()->status);
        $this->assertSame('authorized', $oldDocument->refresh()->fiscal_status->value);
        $this->assertSame('900100', $oldDocument->cte_number);
        $this->assertSame(CteDocumentStatusEnum::QUEUED, $newDocument->refresh()->status);
        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $oldBatch->refresh()->status);
    }

    public function test_approval_keeps_reemission_history_when_previous_cte_was_canceled_after_draft_creation(): void
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

        $approved = app(ApproveCteEmissionBatch::class)->handle($batch, $user);

        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $approved->status);
        $this->assertSame(CteDocumentStatusEnum::CANCELLED, $oldDocument->refresh()->status);
        $this->assertSame(CteDocumentStatusEnum::DRY_RUN_COMPLETED, $batch->documents->first()->refresh()->status);
    }

    public function test_approval_dispatches_one_internal_emission_job_per_live_document(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(
            Register::factory()->count(2)->create(),
            $user,
            'live',
        );

        app(ApproveCteEmissionBatch::class)->handle($batch, $user);

        Queue::assertPushed(EmitCteDirectJob::class, 2);
        Queue::assertPushed(EmitCteDirectJob::class, fn (EmitCteDirectJob $job): bool => $job->afterCommit === true);
        $this->assertTrue($batch->refresh()->documents->every(
            fn ($document): bool => $document->status === CteDocumentStatusEnum::QUEUED
                && $document->emitter === 'direct'
        ));
    }

    public function test_approval_completes_dry_run_documents_without_transmitting(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $batch = app(CreateCteEmissionBatch::class)->handle(
            Register::factory()->count(1)->create(),
            $user,
            'dry_run',
        );

        app(ApproveCteEmissionBatch::class)->handle($batch, $user);

        Queue::assertNotPushed(EmitCteDirectJob::class);
        $this->assertSame(CteDocumentStatusEnum::DRY_RUN_COMPLETED, $batch->documents->first()->refresh()->status);
    }
}
