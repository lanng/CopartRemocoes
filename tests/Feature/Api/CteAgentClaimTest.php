<?php

namespace Tests\Feature\Api;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\Register;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CteAgentClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dry_run_agent_claims_only_a_dry_run_document_and_receives_a_snapshot(): void
    {
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $document = $this->createDocument('dry_run');
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $response = $this->withToken($token)->postJson('/api/v1/cte-agent/claim');

        $response
            ->assertOk()
            ->assertJsonPath('document_id', $document->public_id)
            ->assertJsonPath('execution_mode', 'dry_run')
            ->assertJsonPath('snapshot.vehicle_id', '1146609')
            ->assertJsonStructure(['claim_token', 'claim_expires_at', 'idempotency_key']);

        $document->refresh();
        $this->assertSame(CteDocumentStatusEnum::CLAIMED, $document->status);
        $this->assertSame($agent->id, $document->claimed_by);
        $this->assertNotNull($document->claim_token_hash);
        $this->assertNotSame($response->json('claim_token'), $document->claim_token_hash);
    }

    public function test_claim_returns_no_content_when_no_document_matches_the_agent_mode(): void
    {
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $this->createDocument('live');
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/cte-agent/claim')
            ->assertNoContent();
    }

    public function test_an_expired_pre_authorization_claim_is_returned_to_the_queue(): void
    {
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $document = $this->createDocument('dry_run');
        $document->update([
            'status' => CteDocumentStatusEnum::FILLING,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', str_repeat('b', 64)),
            'claim_expires_at' => now()->subMinute(),
        ]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/cte-agent/claim')
            ->assertOk()
            ->assertJsonPath('document_id', $document->public_id);

        $this->assertSame(CteDocumentStatusEnum::CLAIMED, $document->refresh()->status);
    }

    private function createDocument(string $executionMode): CteDocument
    {
        $register = Register::query()->create([
            'company' => 'copart',
            'vehicle_model' => 'CIVIC',
            'vehicle_plate' => 'ESN4A20',
            'origin_city' => 'Ourinhos',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-01',
            'deadline_delivery' => '2026-08-05',
            'vehicle_id' => '1146609',
            'value' => '750.00',
            'status' => 'pending',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
        ]);
        $batch = CteEmissionBatch::query()->create([
            'status' => CteEmissionBatchStatusEnum::APPROVED,
            'execution_mode' => $executionMode,
            'created_by' => User::factory()->create()->id,
            'approved_by' => User::factory()->create()->id,
            'approved_at' => now(),
        ]);

        return CteDocument::query()->create([
            'public_id' => fake()->uuid(),
            'cte_emission_batch_id' => $batch->id,
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'snapshot' => [
                'register_id' => $register->id,
                'vehicle_id' => $register->vehicle_id,
                'vehicle_plate' => $register->vehicle_plate,
                'value' => '750.00',
            ],
            'idempotency_key' => fake()->uuid(),
            'execution_mode' => $executionMode,
        ]);
    }
}
