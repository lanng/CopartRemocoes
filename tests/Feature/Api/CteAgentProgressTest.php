<?php

namespace Tests\Feature\Api;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CteAgentProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_updates_the_document_and_authorizing_records_the_fiscal_barrier(): void
    {
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $document = CteDocument::factory()->create([
            'status' => CteDocumentStatusEnum::CLAIMED,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', $claimToken = str_repeat('a', 64)),
            'claim_expires_at' => now()->addMinutes(10),
        ]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/cte-agent/documents/{$document->public_id}/progress", [
                'claim_token' => $claimToken,
                'stage' => 'filling',
                'occurred_at' => now()->toIso8601String(),
                'details' => [],
            ])
            ->assertOk();

        $document->refresh();
        $this->assertSame(CteDocumentStatusEnum::FILLING, $document->status);

        $document->update(['status' => CteDocumentStatusEnum::READY_TO_AUTHORIZE]);

        $this->withToken($token)
            ->postJson("/api/v1/cte-agent/documents/{$document->public_id}/progress", [
                'claim_token' => $claimToken,
                'stage' => 'authorizing',
                'occurred_at' => now()->toIso8601String(),
                'details' => [],
            ])
            ->assertOk();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZING, $document->refresh()->status);
        $this->assertNotNull($document->authorization_started_at);
    }

    public function test_progress_with_the_wrong_claim_token_is_rejected(): void
    {
        $agent = CteAgent::factory()->create();
        $document = CteDocument::factory()->create([
            'status' => CteDocumentStatusEnum::CLAIMED,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', str_repeat('b', 64)),
            'claim_expires_at' => now()->addMinutes(10),
        ]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/cte-agent/documents/{$document->public_id}/progress", [
                'claim_token' => str_repeat('c', 64),
                'stage' => 'filling',
                'occurred_at' => now()->toIso8601String(),
                'details' => [],
            ])
            ->assertConflict();
    }
}
