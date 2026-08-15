<?php

namespace Tests\Feature\Api;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\CteEmissionBatchStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CteAgentResultTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_result_stores_the_cte_number_key_and_protocol(): void
    {
        [$agent, $document, $claimToken] = $this->documentInState(CteDocumentStatusEnum::WAITING_FOR_XML);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;
        $payload = $this->authorizedPayload($document, $claimToken);

        $this->withToken($token)
            ->postJson("/api/v1/cte-agent/documents/{$document->public_id}/result", $payload)
            ->assertOk()
            ->assertJsonPath('outcome', 'authorized');

        $document->refresh();
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $document->status);
        $this->assertSame('002670', $document->cte_number);
        $this->assertSame('35260812563112000130570010000026701338262343', $document->access_key);
        $this->assertSame('135263860830097', $document->protocol);
        $this->assertNotNull($document->authorized_at);
        $this->assertSame(CteEmissionBatchStatusEnum::COMPLETED, $document->batch->refresh()->status);
    }

    public function test_repeating_the_same_result_is_idempotent(): void
    {
        [$agent, $document, $claimToken] = $this->documentInState(CteDocumentStatusEnum::WAITING_FOR_XML);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;
        $payload = $this->authorizedPayload($document, $claimToken);
        $url = "/api/v1/cte-agent/documents/{$document->public_id}/result";

        $this->withToken($token)->postJson($url, $payload)->assertOk();
        $this->withToken($token)->postJson($url, $payload)->assertOk();

        $this->assertSame(1, CteDocument::query()->where('access_key', $payload['cte']['access_key'])->count());
    }

    public function test_a_different_result_for_the_same_idempotency_key_is_rejected(): void
    {
        [$agent, $document, $claimToken] = $this->documentInState(CteDocumentStatusEnum::WAITING_FOR_XML);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;
        $payload = $this->authorizedPayload($document, $claimToken);
        $url = "/api/v1/cte-agent/documents/{$document->public_id}/result";

        $this->withToken($token)->postJson($url, $payload)->assertOk();
        $payload['cte']['number'] = '9999';

        $this->withToken($token)
            ->postJson($url, $payload)
            ->assertConflict();
    }

    public function test_an_authorized_result_with_mismatched_xml_evidence_is_rejected(): void
    {
        [$agent, $document, $claimToken] = $this->documentInState(CteDocumentStatusEnum::WAITING_FOR_XML);
        $token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;
        $payload = $this->authorizedPayload($document, $claimToken);
        $payload['evidence']['vehicle_plate'] = 'AAA1B23';

        $this->withToken($token)
            ->postJson("/api/v1/cte-agent/documents/{$document->public_id}/result", $payload)
            ->assertConflict();
    }

    /** @return array{CteAgent, CteDocument, string} */
    private function documentInState(CteDocumentStatusEnum $status): array
    {
        $agent = CteAgent::factory()->create();
        $claimToken = str_repeat('a', 64);
        $document = CteDocument::factory()->create([
            'status' => $status,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', $claimToken),
            'claim_expires_at' => now()->addMinutes(10),
        ]);

        return [$agent, $document, $claimToken];
    }

    /** @return array<string, mixed> */
    private function authorizedPayload(CteDocument $document, string $claimToken): array
    {
        return [
            'idempotency_key' => $document->idempotency_key,
            'claim_token' => $claimToken,
            'outcome' => 'authorized',
            'occurred_at' => now()->toIso8601String(),
            'cte' => [
                'number' => '002670',
                'access_key' => '35260812563112000130570010000026701338262343',
                'series' => '1',
                'protocol' => '135263860830097',
                'issued_at' => '2026-08-06T00:00:00-03:00',
                'authorized_at' => '2026-08-06T14:51:36-03:00',
                'status_code' => '100',
                'status_message' => 'Autorizado o uso do CT-e.',
            ],
            'evidence' => [
                'xml_sha256' => str_repeat('a', 64),
                'vehicle_plate' => 'ESN4A20',
                'payment_code' => 'T691299',
                'origin_city' => 'Ourinhos',
                'destination_city' => 'Pirapora do Bom Jesus',
                'cargo_value' => '43897.00',
                'service_value' => '744.44',
                'xml_filename' => '35260812563112000130570010000026701338262343-cte.xml',
                'xml_created_at' => now()->toIso8601String(),
            ],
        ];
    }
}
