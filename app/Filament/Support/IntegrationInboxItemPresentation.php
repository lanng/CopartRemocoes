<?php

namespace App\Filament\Support;

use App\Models\IntegrationInboxItem;
use App\Models\Register;

class IntegrationInboxItemPresentation
{
    /** @return array<int|string, string> */
    public static function matchingRegisterOptions(IntegrationInboxItem $item): array
    {
        return Register::query()
            ->where('company', 'copart')
            ->where('vehicle_id', $item->extracted_vehicle_id)
            ->get()
            ->filter(fn (Register $register): bool => strtoupper(str_replace('-', '', $register->vehicle_plate)) === strtoupper((string) $item->extracted_vehicle_plate))
            ->mapWithKeys(fn (Register $register): array => [$register->id => "{$register->vehicle_id} - {$register->vehicle_plate}"])
            ->all();
    }
}
