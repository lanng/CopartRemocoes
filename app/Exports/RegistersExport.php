<?php

namespace App\Exports;

use App\Models\Register;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class RegistersExport implements FromCollection, WithHeadings, WithMapping
{
    public function __construct(protected Collection $records) {}

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        return $this->records;
    }

    public function headings(): array
    {
        return [
            'Veículo',
            'Placa',
            'Cidade Origem',
            'Cidade Destino',
            'Data Limite Entrega',
            'Situação',
            'Pátio',
        ];
    }

    /**
     * Maps the data for each row.
     *
     * @param  Register  $register
     */
    public function map($register): array
    {
        return [
            $register->vehicle_model,
            $register->vehicle_plate,
            $register->origin_city,
            $register->destination_city,
            $register->deadline_delivery ? $register->deadline_delivery->format('d/m/Y') : '',
            $register->status ? $register->status->localizedLabel() : '',
            $register->tow_yard,
        ];
    }
}
