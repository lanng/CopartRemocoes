<?php

namespace App\Exports;

use App\Models\Register;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class RegistersJsonExport
{
    public function __construct(protected Collection $records) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toArray(): array
    {
        return $this->records->map(fn (Register $register) => [
            'vehicle_model' => $register->vehicle_model,
            'vehicle_plate' => $register->vehicle_plate,
            'origin_city' => $register->origin_city,
            'destination_city' => $register->destination_city,
            'payment_code' => $register->payment_code,
            'insurance' => $register->insurance,
            'fipe_value' => (float) $register->fipe_value,
            'value' => (float) $register->value,
        ])->all();
    }

    public function download(string $filename): Response
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'json_export_');
        file_put_contents($tempPath, json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return response()->download($tempPath, $filename, [
            'Content-Type' => 'application/json',
        ])->deleteFileAfterSend();
    }
}
