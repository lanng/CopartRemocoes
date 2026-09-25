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
     * Natureza da carga (spec §2.1): a API de produção só tem os códigos 1–3
     * carregados hoje. Remoção = Carga Geral (histórico de produção); tanque =
     * Granel Líquido, a categoria fisicamente correta disponível. Migrar para
     * 0013/0008 quando a ANTT publicar os códigos.
     */
    public function naturezaCarga(): string
    {
        return match ($this) {
            self::VehicleRemoval => '0001',
            self::TankAlcohol => '0003',
        };
    }

    /**
     * Tipo de carga pareado à natureza: 5 = carga geral, 2 = granel líquido.
     */
    public function tipoCarga(): int
    {
        return match ($this) {
            self::VehicleRemoval => 5,
            self::TankAlcohol => 2,
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
