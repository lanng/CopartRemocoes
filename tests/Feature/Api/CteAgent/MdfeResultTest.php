<?php

namespace Tests\Feature\Api\CteAgent;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteAgent;
use App\Models\MdfeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MdfeResultTest extends TestCase
{
    use RefreshDatabase;

    protected MdfeDocument $document;

    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->document = MdfeDocument::factory()->create([
            'execution_mode' => 'dry_run',
            'snapshot' => [
                'schema_version' => 1,
                'trip_id' => 1,
                'company' => 'copart',
                'origin_city' => 'Osvaldo Cruz',
                'destination_city' => 'Caçapava',
                'driver_code' => '8',
                'vehicle_code' => '12',
                'ciot' => '520032956638',
                'cargo_value' => '10000.00',
                'cargo_weight_kg' => '2000.00',
                'origin_cep' => '17700000',
                'destination_cep' => '12286140',
                'payment_doc' => '14517191000925',
                'payment_name' => 'Copart Caçapava',
                'payment_value' => '500.00',
                'payment_bank' => '104',
                'payment_agency' => '0073',
                'cte_access_keys' => [
                    '35260912563112000130570010000028171839975374',
                    '35260912563112000130570010000028171998887776',
                ],
            ],
        ]);

        /** @var CteAgent $agent */
        $agent = CteAgent::factory()->create(['is_dry_run' => true]);
        $this->token = $agent->createToken('test-agent', ['cte-agent'])->plainTextToken;

        $this->document->forceFill([
            'status' => CteDocumentStatusEnum::CLAIMED,
            'claimed_by' => $agent->id,
            'claim_token_hash' => hash('sha256', str_repeat('a', 64)),
            'claimed_at' => now(),
            'claim_expires_at' => now()->addMinutes(10),
        ])->save();
    }

    protected function progress(string $stage): void
    {
        $this->withToken($this->token)->postJson("/api/v1/cte-agent/mdfe/documents/{$this->document->public_id}/progress", [
            'claim_token' => str_repeat('a', 64),
            'stage' => $stage,
            'occurred_at' => now()->toIso8601String(),
            'details' => [],
        ])->assertOk();
    }

    protected function authorizedPayload(): array
    {
        return [
            'idempotency_key' => $this->document->idempotency_key,
            'claim_token' => str_repeat('a', 64),
            'outcome' => 'authorized',
            'occurred_at' => now()->toIso8601String(),
            'mdfe' => [
                'number' => '77',
                'access_key' => '35260912563112000130580010000000771234567890',
                'series' => '1',
                'protocol' => '135260000001234',
                'issued_at' => now()->toIso8601String(),
                'authorized_at' => now()->toIso8601String(),
                'status_code' => '100',
                'status_message' => 'Autorizado o uso do MDF-e',
            ],
            'evidence' => [
                'xml_sha256' => str_repeat('ab', 32),
                'xml_filename' => '35260912563112000130580010000000771234567890-mdfe.xml',
                'q_cte' => 2,
                'cte_access_keys' => [
                    '35260912563112000130570010000028171839975374',
                    '35260912563112000130570010000028171998887776',
                ],
                'cargo_value' => '10000.00',
            ],
        ];
    }

    public function test_progress_flows_through_stages(): void
    {
        $this->progress('filling');

        $this->assertSame(CteDocumentStatusEnum::FILLING, $this->document->refresh()->status);
    }

    public function test_progress_with_a_wrong_token_is_rejected(): void
    {
        $this->withToken($this->token)->postJson("/api/v1/cte-agent/mdfe/documents/{$this->document->public_id}/progress", [
            'claim_token' => str_repeat('b', 64),
            'stage' => 'filling',
            'occurred_at' => now()->toIso8601String(),
            'details' => [],
        ])->assertConflict();
    }

    public function test_records_an_authorized_result(): void
    {
        $this->progress('filling');
        $this->progress('validating');
        $this->progress('ready_to_authorize');
        $this->progress('authorizing');
        $this->progress('waiting_for_xml');

        $response = $this->withToken($this->token)->postJson("/api/v1/cte-agent/mdfe/documents/{$this->document->public_id}/result", $this->authorizedPayload());

        $response->assertOk();

        $this->document->refresh();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $this->document->status);
        $this->assertSame('77', $this->document->mdfe_number);
        $this->assertSame('100', $this->document->fiscal_status_code);
    }

    public function test_rejects_a_result_with_divergent_cte_keys(): void
    {
        $this->progress('filling');

        $payload = $this->authorizedPayload();
        $payload['evidence']['cte_access_keys'] = [
            '35260912563112000130570010000028171839975374',
        ];

        $this->withToken($this->token)->postJson("/api/v1/cte-agent/mdfe/documents/{$this->document->public_id}/result", $payload)
            ->assertConflict();

        $this->assertSame(CteDocumentStatusEnum::FILLING, $this->document->refresh()->status);
    }

    public function test_rejects_a_result_with_a_wrong_xml_filename(): void
    {
        $payload = $this->authorizedPayload();
        $payload['evidence']['xml_filename'] = 'outro-arquivo.xml';

        $this->withToken($this->token)->postJson("/api/v1/cte-agent/mdfe/documents/{$this->document->public_id}/result", $payload)
            ->assertConflict();
    }

    public function test_result_is_idempotent(): void
    {
        $this->progress('filling');
        $this->progress('validating');
        $this->progress('ready_to_authorize');
        $this->progress('authorizing');
        $this->progress('waiting_for_xml');

        $payload = $this->authorizedPayload();

        $this->withToken($this->token)->postJson("/api/v1/cte-agent/mdfe/documents/{$this->document->public_id}/result", $payload)->assertOk();

        $second = $this->withToken($this->token)->postJson("/api/v1/cte-agent/mdfe/documents/{$this->document->public_id}/result", $payload);
        $second->assertOk();

        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $this->document->refresh()->status);
    }
}
