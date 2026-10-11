<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Company;
use App\Models\CompanyFiscalAlias;
use Illuminate\Database\Seeder;

class CompanyFiscalAliasSeeder extends Seeder
{
    public function run(): void
    {
        $senders = [
            'HDI SEGUROS SA NOVO' => '29980158000157',
            'LOOVI TECHNOLOGY' => '34504257000100',
            'PORTOSEG S/A - CREDITO FINANCIAMENTO E INVESTIMENTO' => '04862600000110',
            'MAPFRE S.G./ AUTO / MASSIFICADOS - 01' => '61074175000138',
            'LOCALIZA RENT A CAR' => '16670085000155',
            'TOKIO MARINE SEGURADORA S A VEICULOS TMS NOVO' => '33164021000100',
            'ALLIANZ SEGUROS S/A' => '61573796000166',
            'SEGUROS SURA S/A (RSA SURA)' => '33065699000127',
            'ALLSEG SEGURADORA S/A' => '67865360000127',
            'YELUM SEGUROS S.A' => '61550141000172',
            'ZURICH MINAS BRASIL SEGUROS SA' => '17197385000121',
            'GENTE SEGURADORA SA' => '90180605000102',
            'YOUSE CAIXA SEGUROS' => '34020354000110',
            'ALFA SEGURADORA S A' => '02713529000188',
            'EZZE SEGUROS S.A.' => '31534848000124',
            'CNP CONSORCIO' => '05349595000109',
            'PIER SEGURADORA S.A.' => '39380513000100',
            'SANCOR SEGUROS' => '17643407000130',
            'SUHAI SEGURADORA' => '16825255000123',
            'JUSTOS SEGUROS S A' => '40317530000182',
            'LOCALIZA FLEET S.A' => '02286479000108',
            'SANTANDER AUTO SA' => '30617319000121',
            'BANCO TOYOTA DO BRASIL S A' => '03215790000110',
            'PORTO SEGURO' => '61198164000160',
        ];
        $destinations = [
            'EMBU DAS ARTES' => ['14517191000259', '3515004', 'Embu das Artes'],
            'OSASCO' => ['14517191000410', '3534401', 'Osasco'],
            'CACAPAVA' => ['14517191000925', '3508504', 'Caçapava'],
            'ITAQUAQUECETUBA' => ['14517191000178', '3523107', 'Itaquaquecetuba'],
            'PIRAPORA' => ['14517191000330', '3539103', 'Pirapora do Bom Jesus'],
            'LEILAO PATIO PORTO SEGURO' => ['14517191000410', '3534401', 'Osasco'],
        ];
        $created = 0;

        foreach ($senders as $source => $cnpj) {
            $created += $this->seedAlias('sender', $source, $cnpj) ? 1 : 0;
        }
        foreach ($destinations as $source => [$cnpj, $ibge, $cityName]) {
            $created += $this->seedAlias('destination', $source, $cnpj, $ibge, $cityName) ? 1 : 0;
        }

        $skipped = count($senders) + count($destinations) - $created;
        $this->command?->info("Traduções fiscais: {$created} criadas; {$skipped} já existentes ou sem empresa ativa.");
    }

    private function seedAlias(string $role, string $source, string $cnpj, ?string $ibge = null, ?string $cityName = null): bool
    {
        $company = Company::query()->where('cnpj', $cnpj)->where('is_active', true)->first();
        if ($company === null) {
            return false;
        }

        $city = $ibge === null ? null : City::query()->firstOrCreate(
            ['ibge_code' => $ibge],
            ['name' => $cityName, 'state' => 'SP'],
        );
        $alias = CompanyFiscalAlias::query()->firstOrCreate([
            'role' => $role,
            'normalized_name' => CompanyFiscalAlias::normalize($source),
        ], [
            'source_name' => $source,
            'company_id' => $company->id,
            'city_id' => $city?->id,
        ]);

        return $alias->wasRecentlyCreated;
    }
}
