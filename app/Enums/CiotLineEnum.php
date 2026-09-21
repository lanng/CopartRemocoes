<?php

namespace App\Enums;

enum CiotLineEnum: string
{
    case VehicleRemoval = 'vehicle_removal';
    case TankAlcohol = 'tank_alcohol';

    public function label(): string
    {
        return match ($this) {
            self::VehicleRemoval => 'Remoção de veículos',
            self::TankAlcohol => 'Tanque de álcool',
        };
    }

    /**
     * Natureza da carga (tabela oficial do DCS, 4 dígitos).
     */
    public function naturezaCarga(): string
    {
        return match ($this) {
            self::VehicleRemoval => '0013',
            self::TankAlcohol => '0008',
        };
    }

    /**
     * Tipo de carga: 5 = carga geral, 8 = perigosa granel líquido.
     */
    public function tipoCarga(): int
    {
        return match ($this) {
            self::VehicleRemoval => 5,
            self::TankAlcohol => 8,
        };
    }

    /**
     * Chave da linha em config/ciot.php (dados bancários por linha).
     */
    public function configKey(): string
    {
        return match ($this) {
            self::VehicleRemoval => 'vehicle_removal',
            self::TankAlcohol => 'tank_alcohol',
        };
    }
}
