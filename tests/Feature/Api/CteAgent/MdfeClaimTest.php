<?php

namespace Tests\Feature\Api\CteAgent;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\MdfeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdfeClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function claimDocument(): MdfeDocument
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);

        return MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'execution_mode' => 'dry_run',
        ]);
    }

    public function test_claims_a_queued_mdfe_with_document_type(): void
    {
        $this->claimDocument();

        /** @var CteAgent $agent */
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/cte-agent/claim');

        $response->assertOk();
        $response->assertJsonPath('document_type', 'mdfe');
        $response->assertJsonPath('execution_mode', 'dry_run');
        $response->assertJsonStructure([
            'api_version', 'document_type', 'document_id', 'idempotency_key',
            'execution_mode', 'claim_token', 'claim_expires_at',
            'snapshot' => ['trip_id', 'ciot', 'cte_access_keys', 'payment_doc'],
        ]);
    }

    public function test_prioritizes_cte_documents_over_mdfe(): void
    {
        $this->claimDocument();

        $batch = \App\Models\CteEmissionBatch::factory()->create([
            'status' => \App\Enums\CteEmissionBatchStatusEnum::APPROVED,
        ]);
        CteDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'execution_mode' => 'dry_run',
        ]);

        /** @var CteAgent $agent */
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/cte-agent/claim');

        $response->assertOk();
        $response->assertJsonPath('document_type', 'cte');
    }

    public function test_returns_204_when_there_is_nothing_to_claim(): void
    {
        /** @var CteAgent $agent */
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/cte-agent/claim')
            ->assertNoContent();
    }

    public function test_ignores_mdfe_from_a_different_execution_mode(): void
    {
        $batch = CteEmissionBatch::factory()->create([
            'status' => CteEmissionBatchStatusEnum::COMPLETED,
        ]);
        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'execution_mode' => 'live',
        ]);

        /** @var CteAgent $agent */
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/cte-agent/claim')
            ->assertNoContent();
    }

    public function test_requeues_an_expired_mdfe_lease(): void
    {
        $mdfe = $this->claimDocument();
        $mdfe->forceFill([
            'status' => CteDocumentStatusEnum::CLAIMED,
            'claim_expires_at' => now()->subMinutes(20),
        ])->save();

        /** @var CteAgent $agent */
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/cte-agent/claim');

        $response->assertOk();
        $this->assertSame(CteDocumentStatusEnum::CLAIMED, $mdfe->refresh()->status);
    }
}
