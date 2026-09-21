<?php

namespace App\Models;

use App\Enums\CiotLineEnum;
use App\Enums\CiotOperationTypeEnum;
use App\Enums\CiotStatusEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Ciot extends Model
{
    /** @use HasFactory<\Database\Factories\CiotFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'public_id', 'status', 'line', 'operation_type', 'id_operacao_transporte',
        'payer_id', 'payer_cnpj', 'payer_name', 'delivery_payer_id',
        'delivery_payer_cnpj', 'delivery_payer_name', 'additional_payers',
        'origin', 'destination', 'distance_km', 'freight_value_cents',
        'cargo_weight_kg', 'vehicles', 'indicators', 'payload', 'response', 'ciot_number',
        'verifier_code', 'protocol', 'carrier_notice', 'travel_start_at',
        'travel_end_at', 'issued_at', 'canceled_at', 'closed_at',
        'cancel_reason', 'error_code', 'error_message', 'cte_emission_batch_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => CiotStatusEnum::class,
            'line' => CiotLineEnum::class,
            'operation_type' => CiotOperationTypeEnum::class,
            'additional_payers' => 'array',
            'origin' => 'array',
            'destination' => 'array',
            'vehicles' => 'array',
            'indicators' => 'array',
            'payload' => 'array',
            'response' => 'array',
            'travel_start_at' => 'datetime',
            'travel_end_at' => 'datetime',
            'issued_at' => 'datetime',
            'canceled_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(CiotPayer::class, 'payer_id');
    }

    public function deliveryPayer(): BelongsTo
    {
        return $this->belongsTo(CiotPayer::class, 'delivery_payer_id');
    }

    public function cteEmissionBatch(): BelongsTo
    {
        return $this->belongsTo(CteEmissionBatch::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CiotStatusEnum::ISSUED;
    }

    /**
     * Número completo de 16 dígitos (12 do CIOT + 4 do verificador).
     */
    public function fullNumber(): ?string
    {
        if ($this->ciot_number === null) {
            return null;
        }

        return $this->ciot_number.($this->verifier_code ?? '');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'line', 'operation_type', 'payer_name', 'ciot_number', 'protocol'])
            ->logOnlyDirty();
    }
}
