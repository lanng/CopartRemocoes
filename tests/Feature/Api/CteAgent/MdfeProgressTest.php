<?php

namespace Tests\Feature\Api\CteAgent;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteAgent;
use App\Models\MdfeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdfeProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_progress_renews_an_expired_lease_mid_flow(): void
    {
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::AUTHORIZING,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', $claimToken = str_repeat('a', 64)),
            'claim_expires_at' => now()->subMinutes(2),
        ]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        // O agent passou do lease durante a digitação no Lab: o próximo
        // progresso (waiting_for_xml) provava vida e não pode ser rejeitado.
        $this->withToken($token)
            ->postJson("/api/v1/cte-agent/mdfe/documents/{$document->public_id}/progress", [
                'claim_token' => $claimToken,
                'stage' => 'waiting_for_xml',
                'occurred_at' => now()->toIso8601String(),
                'details' => [],
            ])
            ->assertOk();

        $document->refresh();

        $this->assertSame(CteDocumentStatusEnum::WAITING_FOR_XML, $document->status);
        $this->assertTrue($document->claim_expires_at->isFuture());
    }

    public function test_progress_with_the_wrong_claim_token_is_rejected(): void
    {
        $agent = CteAgent::factory()->create();
        $document = MdfeDocument::factory()->create([
            'status' => CteDocumentStatusEnum::CLAIMED,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', str_repeat('b', 64)),
            'claim_expires_at' => now()->addMinutes(10),
        ]);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/cte-agent/mdfe/documents/{$document->public_id}/progress", [
                'claim_token' => str_repeat('c', 64),
                'stage' => 'authorizing',
                'occurred_at' => now()->toIso8601String(),
                'details' => [],
            ])
            ->assertConflict();
    }
}
