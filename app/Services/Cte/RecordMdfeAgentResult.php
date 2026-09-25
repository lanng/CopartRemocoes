<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteAgent;
use App\Models\MdfeDocument;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordMdfeAgentResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(CteAgent $agent, string $publicId, array $payload): MdfeDocument
    {
        return DB::transaction(function () use ($agent, $publicId, $payload): MdfeDocument {
            $document = MdfeDocument::query()
                ->where('public_id', $publicId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertClaim($document, $agent, $payload['claim_token']);

            if ($document->idempotency_key !== $payload['idempotency_key']) {
                throw new DomainException('The idempotency key does not belong to this document.');
            }

            $payloadHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            if ($document->result_payload_hash) {
                if ($document->result_payload_hash !== $payloadHash) {
                    throw new DomainException('A different result was already recorded for this document.');
                }

                return $document;
            }

            $outcome = $payload['outcome'];
            $status = match ($outcome) {
                'authorized' => CteDocumentStatusEnum::AUTHORIZED,
                'rejected' => CteDocumentStatusEnum::REJECTED,
                'failed_before_authorization' => CteDocumentStatusEnum::FAILED_BEFORE_AUTHORIZATION,
                'reconciliation_required' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED,
                'dry_run_completed' => CteDocumentStatusEnum::DRY_RUN_COMPLETED,
            };

            if ($outcome === 'authorized' && ($payload['mdfe']['status_code'] ?? null) !== '100') {
                throw new DomainException('An authorized result must have fiscal status code 100.');
            }

            if ($outcome === 'authorized') {
                $this->assertAuthorizedEvidence($document, $payload);
            }

            app(MdfeDocumentWorkflow::class)->transition($document, $status);

            $mdfe = $payload['mdfe'] ?? [];
            $error = $payload['error'] ?? [];
            $document->forceFill([
                'result_payload_hash' => $payloadHash,
                'issued_at' => isset($mdfe['issued_at']) ? Carbon::parse($mdfe['issued_at']) : null,
                'authorized_at' => isset($mdfe['authorized_at']) ? Carbon::parse($mdfe['authorized_at']) : null,
                'mdfe_number' => $mdfe['number'] ?? null,
                'access_key' => $mdfe['access_key'] ?? null,
                'series' => $mdfe['series'] ?? null,
                'protocol' => $mdfe['protocol'] ?? null,
                'fiscal_status_code' => $mdfe['status_code'] ?? null,
                'fiscal_status_message' => $mdfe['status_message'] ?? null,
                'error_stage' => $error['stage'] ?? null,
                'error_code' => $error['code'] ?? null,
                'error_message' => $error['message'] ?? null,
            ])->save();

            return $document->refresh();
        });
    }

    private function assertClaim(MdfeDocument $document, CteAgent $agent, string $claimToken): void
    {
        if ($document->claimed_by !== $agent->id || ! hash_equals((string) $document->claim_token_hash, hash('sha256', $claimToken))) {
            throw new DomainException('The claim token is invalid for this document.');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertAuthorizedEvidence(MdfeDocument $document, array $payload): void
    {
        $snapshot = $document->snapshot;
        $evidence = $payload['evidence'];

        $expectedKeys = $snapshot['cte_access_keys'] ?? [];
        $reportedKeys = array_values($evidence['cte_access_keys'] ?? []);

        if ($reportedKeys !== $expectedKeys) {
            throw new DomainException('The manifested CT-e access keys do not match the snapshot.');
        }

        if ((int) ($evidence['q_cte'] ?? 0) !== count($expectedKeys)) {
            throw new DomainException('The reported qCTe does not match the number of CT-e in the snapshot.');
        }

        $expectedFilename = strtolower(($payload['mdfe']['access_key'] ?? '').'-mdfe.xml');

        if (strtolower(basename((string) $evidence['xml_filename'])) !== $expectedFilename) {
            throw new DomainException('The XML filename does not match the MDF-e access key.');
        }
    }
}
