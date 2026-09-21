<?php

namespace App\Filament\Resources\CiotResource\Pages;

use App\Filament\Resources\CiotResource;
use App\Models\Ciot;
use App\Models\CiotPayer;
use App\Models\CiotVehicle;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateCiot extends CreateRecord
{
    protected static string $resource = CiotResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $payer = CiotPayer::query()->findOrFail($data['payer_id']);
        $delivery = CiotPayer::query()->find($data['delivery_payer_id'] ?? null) ?? $payer;
        $vehicles = CiotVehicle::query()->whereIn('id', $data['vehicle_ids'] ?? [])->get();

        $data['public_id'] = (string) Str::uuid();
        $data['payer_cnpj'] = $payer->cnpj;
        $data['payer_name'] = $payer->name;
        $data['delivery_payer_cnpj'] = $delivery->cnpj;
        $data['delivery_payer_name'] = $delivery->name;
        $data['vehicles'] = $vehicles->map(fn (CiotVehicle $vehicle): array => $vehicle->snapshot())->all();
        $data['freight_value_cents'] = (int) round(((float) str_replace(',', '.', (string) $data['freight_value'])) * 100);
        unset($data['freight_value'], $data['vehicle_ids']);

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Ciot $ciot */
        $ciot = static::getModel()::create($data);

        activity()
            ->performedOn($ciot)
            ->log('CIOT criado pelo painel (avulso).');

        return $ciot;
    }
}
