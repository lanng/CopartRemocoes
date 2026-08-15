<?php

namespace App\Services\Cte;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteAgent;
use App\Models\CteDocument;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

class RecordCteAgentResult
{
    public function handle(CteAgent $agent, string $publicId, array $payload): CteDocument
    {
        return DB::transaction(function () use ($agent, $publicId, $payload): CteDocument {
            $document = CteDocument::query()
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

            if ($outcome === 'authorized' && ($payload['cte']['status_code'] ?? null) !== '100') {
                throw new DomainException('An authorized result must have fiscal status code 100.');
            }

            if ($outcome === 'authorized') {
                $this->assertAuthorizedEvidence($document, $payload);
            }

            app(CteDocumentWorkflow::class)->transition($document, $status);

            $cte = $payload['cte'] ?? [];
            $error = $payload['error'] ?? [];
            $document->forceFill([
                'result_payload_hash' => $payloadHash,
                'issued_at' => isset($cte['issued_at']) ? Carbon::parse($cte['issued_at']) : null,
                'authorized_at' => isset($cte['authorized_at']) ? Carbon::parse($cte['authorized_at']) : null,
                'cte_number' => $cte['number'] ?? null,
                'access_key' => $cte['access_key'] ?? null,
                'series' => $cte['series'] ?? null,
                'protocol' => $cte['protocol'] ?? null,
                'fiscal_status_code' => $cte['status_code'] ?? null,
                'fiscal_status_message' => $cte['status_message'] ?? null,
                'error_stage' => $error['stage'] ?? null,
                'error_code' => $error['code'] ?? null,
                'error_message' => $error['message'] ?? null,
            ])->save();

            $document = $document->refresh();
            app(CteBatchStatusUpdater::class)->handle($document->batch);

            return $document;
        });
    }

    private function assertClaim(CteDocument $document, CteAgent $agent, string $claimToken): void
    {
        if ($document->claimed_by !== $agent->id || ! hash_equals((string) $document->claim_token_hash, hash('sha256', $claimToken))) {
            throw new DomainException('The claim token is invalid for this document.');
        }
    }

    /** @param array<string, mixed> $payload */
    private function assertAuthorizedEvidence(CteDocument $document, array $payload): void
    {
        $snapshot = $document->snapshot;
        $evidence = $payload['evidence'];
        $snapshotPlate = strtoupper(str_replace('-', '', (string) ($snapshot['vehicle_plate'] ?? '')));
        $evidencePlate = strtoupper(str_replace('-', '', (string) $evidence['vehicle_plate']));

        if ($snapshotPlate !== $evidencePlate) {
            throw new DomainException('The XML plate does not match the approved snapshot.');
        }

        if ((string) ($snapshot['payment_code'] ?? '') !== (string) $evidence['payment_code']) {
            throw new DomainException('The XML payment code does not match the approved snapshot.');
        }

        $accessKey = $payload['cte']['access_key'];
        $expectedFilename = strtolower($accessKey.'-cte.xml');

        if (strtolower(basename((string) $evidence['xml_filename'])) !== $expectedFilename) {
            throw new DomainException('The XML filename does not match the access key.');
        }
    }
}
