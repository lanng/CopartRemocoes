<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationInboxItem extends Model
{
    /** @use HasFactory<\Database\Factories\IntegrationInboxItemFactory> */
    use HasFactory;

    protected $fillable = [
        'source', 'message_type', 'external_id', 'status', 'sender', 'subject', 'received_at',
        'extracted_vehicle_id', 'extracted_vehicle_plate', 'extracted_data', 'proposed_changes',
        'alerts', 'candidate_pdf_path', 'candidate_pdf_sha256', 'register_id',
        'previous_register_status', 'delivery_alert', 'authorized_cte_number_at_delivery',
        'failure_reason', 'resolved_by', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'resolved_at' => 'datetime',
            'extracted_data' => 'array',
            'proposed_changes' => 'array',
            'alerts' => 'array',
        ];
    }

    public function requiresAttention(): bool
    {
        return in_array($this->status, ['pending', 'alert'], true)
            && $this->resolved_at === null;
    }

    public function isRemovalRequest(): bool
    {
        return $this->message_type === 'removal_request';
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'processed' => 'Processado',
            'duplicate' => 'Duplicado',
            default => 'Desconhecido',
        };
    }

    public function failureReasonLabel(): ?string
    {
        return match ($this->failure_reason) {
            null => null,
            'untrusted_sender' => 'Remetente não autorizado',
            'invalid_subject' => 'Assunto inválido',
            'invalid_body' => 'Corpo inválido',
            'vehicle_id_mismatch' => 'ID do veículo divergente',
            'register_not_found_or_ambiguous' => 'Registro não encontrado ou ambíguo',
            'vehicle_plate_mismatch' => 'Placa divergente',
            'delivery_already_confirmed' => 'Entrega já confirmada',
            default => $this->failure_reason,
        };
    }

    public function hasDeliveryAlert(): bool
    {
        return $this->delivery_alert !== null;
    }

    public function deliveryAlertLabel(): ?string
    {
        return match ($this->delivery_alert) {
            null => null,
            'unexpected_status' => 'Fluxo inesperado',
            'missing_authorized_cte' => 'Entrega sem CT-e',
            default => $this->delivery_alert,
        };
    }

    public function deliveryAlertColor(): string
    {
        return match ($this->delivery_alert) {
            'unexpected_status' => 'warning',
            'missing_authorized_cte' => 'danger',
            default => 'gray',
        };
    }
}
