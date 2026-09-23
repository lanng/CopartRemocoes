<?php

namespace Database\Seeders;

use App\Models\CiotPayer;
use App\Models\City;
use Illuminate\Database\Seeder;

class CiotPayerSeeder extends Seeder
{
    /**
     * Pátios Copart confirmados (spec, seção 5 — o CNPJ 14.517.191/0001-78
     * permanece fora até confirmação). Endereços/CEPs são preenchidos pelo operador.
     *
     * @return array<string, array{name: string, cnpj: string, city: string, state: string}>
     */
    public static function payers(): array
    {
        return [
            'cacapava' => [
                'name' => 'Copart Caçapava',
                'cnpj' => '14517191000925',
                'city' => 'Caçapava',
                'state' => 'SP',
            ],
            'pirapora' => [
                'name' => 'Copart Pirapora do Bom Jesus',
                'cnpj' => '14517191000330',
                'city' => 'Pirapora do Bom Jesus',
                'state' => 'SP',
            ],
            'osasco' => [
                'name' => 'Copart Osasco',
                'cnpj' => '14517191000410',
                'city' => 'Osasco',
                'state' => 'SP',
            ],
            'embu' => [
                'name' => 'Copart Embú das Artes',
                'cnpj' => '14517191000259',
                'city' => 'Embú das Artes',
                'state' => 'SP',
            ],
        ];
    }

    public function run(): void
    {
        foreach (self::payers() as $payer) {
            $patio = CiotPayer::query()->firstOrCreate(
                ['cnpj' => $payer['cnpj']],
                [
                    'name' => $payer['name'],
                    'city' => $payer['city'],
                    'state' => $payer['state'],
                    'is_active' => true,
                ],
            );

            $this->resolveIbge($patio, $payer['city'], $payer['state']);
        }
    }

    /**
     * Resolve o código IBGE na base local de municípios (ciot:seed-cities).
     */
    protected function resolveIbge(CiotPayer $patio, string $city, string $state): void
    {
        if (filled($patio->ibge_code)) {
            return;
        }

        $found = City::query()
            ->where('state', $state)
            ->where(fn ($query) => $query
                ->where('name', $city)
                ->orWhere('name', 'like', "{$city}%"))
            ->orderByRaw('case when name = ? then 0 else 1 end', [$city])
            ->first();

        if ($found !== null) {
            $patio->forceFill(['ibge_code' => $found->ibge_code])->save();
        }
    }
}
