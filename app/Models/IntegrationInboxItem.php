<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'failure_reason', 'resolved_by', 'resolved_at', 'acknowledged_by', 'acknowledged_at',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
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

    public function requiresUserAction(): bool
    {
        if ($this->acknowledged_at !== null) {
            return false;
        }

        if ($this->message_type === 'checklist') {
            return $this->status === 'pending' || $this->delivery_alert !== null;
        }

        return $this->message_type === 'removal_request'
            && in_array($this->status, ['pending', 'alert'], true)
            && $this->resolved_at === null;
    }

    public function scopeRequiringUserAction(Builder $query): Builder
    {
        return $query
            ->whereNull('acknowledged_at')
            ->where(function (Builder $query): void {
                $query
                    ->where(function (Builder $query): void {
                        $query->where('message_type', 'checklist')
                            ->where(function (Builder $query): void {
                                $query->where('status', 'pending')->orWhereNotNull('delivery_alert');
                            });
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->where('message_type', 'removal_request')
                            ->whereIn('status', ['pending', 'alert'])
                            ->whereNull('resolved_at');
                    });
            });
    }

    public function scopeByActionPriority(Builder $query): Builder
    {
        return $query
            ->orderByRaw("CASE
                WHEN delivery_alert = 'missing_authorized_cte' THEN 1
                WHEN message_type = 'removal_request' AND status IN ('pending', 'alert') THEN 2
                WHEN delivery_alert = 'unexpected_status' THEN 3
                WHEN status = 'pending' THEN 4
                WHEN status = 'alert' THEN 5
                ELSE 6
            END")
            ->orderByDesc('received_at')
            ->orderByDesc('id');
    }

    public function actionPriority(): int
    {
        return match (true) {
            $this->delivery_alert === 'missing_authorized_cte' => 1,
            $this->isRemovalRequest() && in_array($this->status, ['pending', 'alert'], true) => 2,
            $this->delivery_alert === 'unexpected_status' => 3,
            $this->status === 'pending' => 4,
            $this->status === 'alert' => 5,
            default => 6,
        };
    }

    public function actionLabel(): string
    {
        return match (true) {
            $this->delivery_alert === 'missing_authorized_cte' => 'Entrega sem CT-e',
            $this->delivery_alert === 'unexpected_status' => 'Entrega fora do fluxo',
            $this->isRemovalRequest() && $this->failure_reason !== null => $this->failureReasonLabel() ?? 'Falha na importação',
            $this->isRemovalRequest() && $this->hasRemovalAlert() => implode(', ', $this->removalAlertLabels()),
            $this->status === 'pending' => 'Conciliação pendente',
            default => 'Revisão pendente',
        };
    }

    public function messageTypeLabel(): string
    {
        return match ($this->message_type) {
            'removal_request' => 'Inclusão de registro',
            'checklist' => 'Baixa de entrega',
            default => $this->message_type,
        };
    }

    public function removalAlertLabels(): array
    {
        return collect($this->alerts ?? [])
            ->map(fn (string $alert): string => match ($alert) {
                'freight_changed' => 'Frete alterado',
                'zero_fipe' => 'FIPE zerada',
                'consignor_letter_failed' => 'Falha ao salvar Carta do Comitente',
                default => $alert,
            })
            ->values()
            ->all();
    }

    public function hasRemovalAlert(): bool
    {
        return $this->removalAlertLabels() !== [];
    }

    public function removalAlertColor(): string
    {
        return $this->hasRemovalAlert() ? 'warning' : 'gray';
    }

    /** @return list<array{field: string, current: mixed, proposed: mixed}> */
    public function proposedChangesForDisplay(): array
    {
        return collect($this->proposed_changes ?? [])
            ->map(fn (array $change, string $field): array => [
                'field' => match ($field) {
                    'vehicle_model' => 'Veículo',
                    'origin_city' => 'Cidade de origem',
                    'destination_city' => 'Cidade de destino',
                    'deadline_withdraw' => 'Data limite de retirada',
                    'deadline_delivery' => 'Data limite de entrega',
                    'value' => 'Frete',
                    'insurance' => 'Seguradora',
                    'fipe_value' => 'FIPE',
                    'payment_code' => 'Código de pagamento',
                    'notes' => 'Observações',
                    'pdf_path' => 'PDF',
                    default => $field,
                },
                'current' => $change['current'] ?? null,
                'proposed' => $change['proposed'] ?? null,
            ])
            ->values()
            ->all();
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'pending' => 'Pendente',
            'processed' => 'Processado',
            'alert' => 'Alerta',
            'no_changes' => 'Sem alterações',
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
            'identity_conflict' => 'Identidade ambígua ou divergente',
            'missing_body_fields' => 'Campos do corpo ausentes',
            'missing_pdf_fields' => 'Campos do PDF ausentes',
            'invalid_constraints' => 'Restrições inválidas',
            'update_blocked_by_status' => 'Atualização bloqueada pelo status',
            'domain_error' => 'Falha na validação da importação',
            'processing_failed' => 'Falha no processamento',
            'graph_connection_missing' => 'Conexão com o Graph ausente',
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
