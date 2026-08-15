<?php

namespace App\Models;

use App\Enums\CteDocumentStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CteDocument extends Model
{
    /** @use HasFactory<\Database\Factories\CteDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id', 'cte_emission_batch_id', 'register_id', 'replaced_document_id',
        'status', 'snapshot', 'idempotency_key', 'execution_mode', 'claimed_by',
        'claim_token_hash', 'claimed_at', 'claim_expires_at', 'authorization_started_at',
        'issued_at', 'authorized_at', 'cte_number', 'access_key', 'series', 'protocol',
        'fiscal_status_code', 'fiscal_status_message', 'error_stage', 'error_code',
        'error_message', 'result_payload_hash',
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

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function replacingDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_document_id');
    }

    public function replacedDocuments(): HasMany
    {
        return $this->hasMany(self::class, 'replaced_document_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(CteAgent::class, 'claimed_by');
    }
}
