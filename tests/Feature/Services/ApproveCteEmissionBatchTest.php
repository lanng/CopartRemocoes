<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
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
}
