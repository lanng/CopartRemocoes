<?php

namespace App\Filament\Support;

use App\Models\IntegrationInboxItem;
use App\Models\Register;

class IntegrationInboxItemPresentation
{
    /** @return array<int|string, string> */
    public static function matchingRegisterOptions(IntegrationInboxItem $item): array
    {
        $options = Register::query()
            ->where('company', 'copart')
            ->where('vehicle_id', $item->extracted_vehicle_id)
            ->get()
            ->filter(fn (Register $register): bool => strtoupper(str_replace('-', '', $register->vehicle_plate)) === strtoupper((string) $item->extracted_vehicle_plate))
            ->mapWithKeys(fn (Register $register): array => [$register->id => "{$register->vehicle_id} - {$register->vehicle_plate}"]);

        $associated = $item->register_id !== null ? $item->register : null;

        if ($associated !== null && ! $options->has($associated->id)) {
            $options->put($associated->id, "{$associated->vehicle_id} - {$associated->vehicle_plate}");
        }

        return $options->all();
    }
}
