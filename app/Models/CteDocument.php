<?php

namespace App\Models;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Enums\FiscalDocumentWorkflowOriginEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CteDocument extends Model
{
    /** @use HasFactory<\Database\Factories\CteDocumentFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id', 'cte_emission_batch_id', 'register_id', 'replaced_document_id',
        'replacement_reason', 'status', 'fiscal_status', 'workflow_origin', 'snapshot', 'idempotency_key', 'execution_mode', 'emitter', 'claimed_by',
        'claim_token_hash', 'claimed_at', 'claim_expires_at', 'authorization_started_at',
        'issued_at', 'authorized_at', 'cte_number', 'access_key', 'series', 'protocol',
        'fiscal_status_code', 'fiscal_status_message', 'payload_xml', 'response_xml',
        'digest_value', 'xml_path', 'pdf_path', 'sha256', 'error_stage', 'error_code',
        'error_message', 'result_payload_hash',
    ];

    protected function casts(): array
    {
        return [
            'status' => CteDocumentStatusEnum::class,
            'fiscal_status' => FiscalDocumentStatusEnum::class,
            'workflow_origin' => FiscalDocumentWorkflowOriginEnum::class,
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

    public function mdfeDocuments(): BelongsToMany
    {
        return $this->belongsToMany(MdfeDocument::class, 'cte_document_mdfe_document')
            ->withPivot('position')
            ->withTimestamps();
    }

    public function nfeDocuments(): HasMany
    {
        return $this->hasMany(CteDocumentNfe::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CteEvent::class);
    }

    /** @param  Builder<CteDocument>  $query */
    public function scopeEligibleForNewMdfe(Builder $query): Builder
    {
        return $query->where('workflow_origin', FiscalDocumentWorkflowOriginEnum::ControlledV1->value);
    }

    public function isEligibleForNewMdfe(): bool
    {
        return $this->workflow_origin === FiscalDocumentWorkflowOriginEnum::ControlledV1;
    }

    public function isCancellationAvailable(): bool
    {
        if ($this->status !== CteDocumentStatusEnum::AUTHORIZED
            || $this->emitter !== 'direct'
            || blank($this->access_key)
            || blank($this->protocol)
            || $this->fiscal_status === FiscalDocumentStatusEnum::CANCELLED
            || $this->fiscal_status_code === '101') {
            return false;
        }

        $lastAttempt = $this->events()
            ->where('event_type', '110111')
            ->orderByDesc('attempt')
            ->first();

        if ($lastAttempt !== null) {
            return in_array($lastAttempt->status, ['pending', 'reconciliation_required'], true);
        }

        return ! $this->mdfeDocuments()
            ->where('mdfe_documents.status', CteDocumentStatusEnum::AUTHORIZED->value)
            ->where(function (Builder $query): void {
                $query->whereNull('mdfe_documents.fiscal_status')
                    ->orWhere('mdfe_documents.fiscal_status', '!=', FiscalDocumentStatusEnum::CANCELLED->value);
            })
            ->where(function (Builder $query): void {
                $query->whereNull('mdfe_documents.fiscal_status_code')
                    ->orWhere('mdfe_documents.fiscal_status_code', '!=', '101');
            })
            ->exists();
    }
}
