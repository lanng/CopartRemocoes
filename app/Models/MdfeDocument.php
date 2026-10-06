<?php

namespace App\Models;

use App\Enums\CteDocumentStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MdfeDocument extends Model
{
    /** @use HasFactory<\Database\Factories\MdfeDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id', 'cte_emission_batch_id', 'status', 'snapshot', 'idempotency_key',
        'execution_mode', 'emitter', 'claimed_by', 'claim_token_hash', 'claimed_at',
        'claim_expires_at', 'authorization_started_at', 'issued_at', 'authorized_at',
        'mdfe_number', 'access_key', 'series', 'protocol', 'fiscal_status_code',
        'fiscal_status_message', 'error_stage', 'error_code', 'error_message',
        'result_payload_hash', 'payload_xml', 'response_xml', 'digest_value', 'xml_path', 'pdf_path', 'sha256',
    ];

    protected function casts(): array
    {
        return [
            'status' => CteDocumentStatusEnum::class,
            'snapshot' => 'array',
            'claimed_at' => 'datetime',
            'claim_expires_at' => 'datetime',
            'authorization_started_at' => 'datetime',
            'issued_at' => 'datetime',
            'authorized_at' => 'datetime',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CteEmissionBatch::class, 'cte_emission_batch_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CteAgent::class, 'claimed_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MdfeEvent::class);
    }

    public function isClosureAvailable(): bool
    {
        if ($this->status !== CteDocumentStatusEnum::AUTHORIZED
            || $this->emitter !== 'direct'
            || blank($this->access_key)
            || blank($this->protocol)
            || $this->fiscal_status_code === '132') {
            return false;
        }

        $lastAttempt = $this->events()
            ->where('event_type', '110112')
            ->orderByDesc('attempt')
            ->first();

        return $lastAttempt === null || $lastAttempt->isRetryableRejection();
    }

    /** @param  Builder<MdfeDocument>  $query */
    public function scopeOpenForClosure(Builder $query): Builder
    {
        return $query
            ->where('status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->where('emitter', 'direct')
            ->where(function (Builder $query): void {
                $query->whereNull('fiscal_status_code')
                    ->orWhere('fiscal_status_code', '!=', '132');
            });
    }
}
