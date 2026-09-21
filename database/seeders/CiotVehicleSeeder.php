<?php

namespace Database\Seeders;

use App\Models\CiotVehicle;
use Illuminate\Database\Seeder;

class CiotVehicleSeeder extends Seeder
{
    public function run(): void
    {
        CiotVehicle::query()->firstOrCreate(
            ['plate' => 'PUC8E55'],
            [
                'rntrc' => config('ciot.company.rntrc'),
                'axles' => 3,
                'type' => CiotVehicle::TYPE_AUTOMOTOR,
                'is_active' => true,
            ],
        );

        CiotVehicle::query()->firstOrCreate(
            ['plate' => 'TIX7D32'],
            [
                'rntrc' => config('ciot.company.rntrc'),
                'axles' => 2,
                'type' => CiotVehicle::TYPE_TRAILER,
                'is_active' => true,
            ],
        );
    }
}
