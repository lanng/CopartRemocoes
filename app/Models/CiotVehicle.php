<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CiotVehicle extends Model
{
    /** @use HasFactory<\Database\Factories\CiotVehicleFactory> */
    use HasFactory;

    public const TYPE_AUTOMOTOR = 'automotor';

    public const TYPE_TRAILER = 'reboque';

    protected $fillable = [
        'plate', 'rntrc', 'axles', 'type', 'line', 'description', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'axles' => 'integer',
        ];
    }

    public function isAutomotor(): bool
    {
        return $this->type === self::TYPE_AUTOMOTOR;
    }

    /**
     * Linha para a qual o veículo é composto (nulo = disponível para todas).
     */
    public function servesLine(string $line): bool
    {
        return $this->is_active && ($this->line === null || $this->line === $line);
    }

    /**
     * Snapshot no formato enviado no payload `Veiculos[]` do CIOT.
     *
     * @return array{placa: string, rntrc: ?string, eixos: int, tipo: string}
     */
    public function snapshot(): array
    {
        return [
            'placa' => $this->plate,
            'rntrc' => $this->rntrc,
            'eixos' => $this->axles,
            'tipo' => $this->type,
        ];
    }
}
