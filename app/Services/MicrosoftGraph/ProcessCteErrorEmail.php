<?php

namespace App\Services\MicrosoftGraph;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\CteDocument;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProcessCteErrorEmail
{
    public function __construct(private readonly CteErrorEmailParser $parser) {}

    /** @param array<string, mixed> $message */
    public function handle(array $message): ?IntegrationInboxItem
    {
        if (strtolower(trim((string) ($message['sender'] ?? ''))) !== 'remocao@copart.com.br'
            || ! preg_match('/^\s*copart\s*-\s*erro\s+no\s+documento\b/i', Str::ascii((string) ($message['subject'] ?? '')))) {
            return null;
        }

        try {
            return DB::transaction(function () use ($message): IntegrationInboxItem {
                $existing = $this->findExisting((string) $message['external_id']);

                if ($existing) {
                    return $existing;
                }

                $parsed = $this->parser->parse([
                    'sender' => (string) ($message['sender'] ?? ''),
                    'subject' => (string) ($message['subject'] ?? ''),
                    'body' => (string) ($message['body'] ?? ''),
                ]);
                $attributes = [
                    'source' => 'microsoft_graph',
                    'message_type' => 'cte_error',
                    'external_id' => $message['external_id'],
                    'status' => 'pending',
                    'sender' => $message['sender'] ?? null,
                    'subject' => $message['subject'] ?? null,
                    'received_at' => Carbon::parse($message['receivedDateTime'])->utc(),
                    'failure_reason' => $parsed['reason'] ?? null,
                    'extracted_data' => array_intersect_key($parsed, array_flip(['cte_number', 'error_message'])),
                ];

                if (! $parsed['valid']) {
                    return IntegrationInboxItem::query()->create($attributes);
                }

                $documents = CteDocument::query()
                    ->where('cte_number', $parsed['cte_number'])
                    ->where('execution_mode', '!=', 'dry_run')
                    ->where(function (Builder $query): void {
                        $query->whereNotNull('authorized_at')
                            ->orWhere('fiscal_status', FiscalDocumentStatusEnum::AUTHORIZED)
                            ->orWhere('status', CteDocumentStatusEnum::AUTHORIZED);
                    })
                    ->whereHas('register', fn (Builder $query): Builder => $query->where('company', 'copart'))
                    ->get();

                if ($documents->count() !== 1) {
                    return IntegrationInboxItem::query()->create(array_replace($attributes, [
                        'failure_reason' => 'cte_not_found_or_ambiguous',
                    ]));
                }

                $document = $documents->first();
                $register = Register::query()->whereKey($document->register_id)->lockForUpdate()->firstOrFail();
                $existing = $this->findExisting((string) $message['external_id'], lock: true);

                if ($existing) {
                    return $existing;
                }

                $fingerprint = hash('sha256', Str::lower(Str::ascii($parsed['error_message'])));
                $extractedData = [
                    'cte_document_id' => $document->id,
                    'cte_number' => $parsed['cte_number'],
                    'error_message' => $parsed['error_message'],
                    'error_fingerprint' => $fingerprint,
                ];
                $attributes = array_replace($attributes, [
                    'register_id' => $register->id,
                    'extracted_vehicle_id' => $register->vehicle_id,
                    'extracted_vehicle_plate' => $register->vehicle_plate,
                    'extracted_data' => $extractedData,
                ]);
                $canonical = IntegrationInboxItem::query()
                    ->where('source', 'microsoft_graph')
                    ->where('message_type', 'cte_error')
                    ->where('extracted_data->cte_document_id', $document->id)
                    ->where('extracted_data->error_fingerprint', $fingerprint)
                    ->whereNull('extracted_data->duplicate_of')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($canonical) {
                    return IntegrationInboxItem::query()->create(array_replace($attributes, [
                        'status' => 'duplicate',
                        'resolved_at' => now(),
                        'extracted_data' => array_replace($extractedData, ['duplicate_of' => $canonical->id]),
                    ]));
                }

                $previousStatus = $register->status->value;

                if ($register->status === RegisterStatusEnum::ANALYSIS) {
                    $previousStatus = $register->integrationInboxItems()
                        ->openRegisterAnalyses()
                        ->whereNotNull('previous_register_status')
                        ->where('previous_register_status', '!=', RegisterStatusEnum::ANALYSIS->value)
                        ->orderBy('id')
                        ->value('previous_register_status') ?? $previousStatus;
                }

                $register->forceFill([
                    'status' => RegisterStatusEnum::ANALYSIS,
                    'notes' => filled($register->notes) ? $register->notes."\n\n".$parsed['notes'] : $parsed['notes'],
                ])->save();

                return IntegrationInboxItem::query()->create(array_replace($attributes, [
                    'status' => 'alert',
                    'previous_register_status' => $previousStatus,
                ]));
            });
        } catch (UniqueConstraintViolationException $exception) {
            return $this->findExisting((string) $message['external_id']) ?? throw $exception;
        }
    }

    private function findExisting(string $externalId, bool $lock = false): ?IntegrationInboxItem
    {
        return IntegrationInboxItem::query()
            ->where('source', 'microsoft_graph')
            ->where('external_id', $externalId)
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->first();
    }
}
