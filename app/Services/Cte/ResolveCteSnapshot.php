<?php

namespace App\Services\Cte;

use App\Models\City;
use App\Models\Company;
use App\Models\CteDocument;
use DomainException;

/**
 * Enriquece o snapshot v1 do documento (dados da remocao) com os blocos
 * fiscais de rem/dest e os códigos IBGE — o que o Lab digitava à mão
 * (spec dfe §2.5, decisões do cadastro companies).
 *
 * Cidades: nomes dos registros chegam abreviados/sem acento da extração de
 * PDF; a resolução é case/acento-insensível sobre a base IBGE local (cities),
 * com aliases configuráveis (dfe.city_aliases, portado do CITY_CONFIG do
 * script Python) e preferência SP → PR → único match nacional.
 *
 * Partes: rem = seguradora do registro, dest = empresa no IBGE do destino —
 * sempre no cadastro companies; dado faltante é erro explícito, nunca chute.
 *
 * A linha tanque (tank_alcohol) ainda não nasce de registros; snapshots já
 * completos (v2, ex.: smoke) passam intocados.
 */
class ResolveCteSnapshot
{
    public function handle(CteDocument $document): array
    {
        $base = (array) $document->snapshot;

        if ((int) ($base['schema_version'] ?? 1) >= 2) {
            return $base;
        }

        $line = (string) ($base['line'] ?? 'vehicle_removal');

        if ($line !== 'vehicle_removal') {
            throw new DomainException("A linha {$line} ainda não é resolvida a partir de registros — informe o snapshot v2 completo.");
        }

        $origin = $this->resolveCity((string) $base['origin_city'], 'origem');
        $destination = $this->resolveCity((string) $base['destination_city'], 'destino');

        return [
            'schema_version' => 2,
            'line' => $line,
            'register_id' => (int) $base['register_id'],
            'value' => (string) $base['value'],
            'fipe_value' => (string) $base['fipe_value'],
            'vehicle_model' => (string) $base['vehicle_model'],
            'vehicle_plate' => (string) $base['vehicle_plate'],
            'payment_code' => (string) $base['payment_code'],
            'origin' => $origin,
            'destination' => $destination,
            'rem' => $this->companyBlock($this->resolveCompanyByName((string) $base['insurance'], 'seguradora (rem)')),
            'dest' => $this->companyBlock($this->resolveCompanyByIbge($destination['ibge'], 'pátio/destinatário (dest)')),
        ];
    }

    /**
     * @return array{ibge: string, name: string, uf: string}
     */
    protected function resolveCity(string $rawName, string $role): array
    {
        $rawName = trim($rawName);

        if ($rawName === '') {
            throw new DomainException("Cidade de {$role} vazia no snapshot.");
        }

        $canonical = (string) (config('dfe.city_aliases')[$this->normalize($rawName)] ?? $rawName);

        // Prefixo de UMA letra: nomes com acento na 2ª+ letra ("São Carlos")
        // não casam com like de prefixo maior em maiúsculas sem acento; a
        // comparação exata é feita normalizada em PHP abaixo.
        $candidates = City::query()
            ->where('name', 'like', mb_substr($canonical, 0, 1).'%')
            ->get()
            ->filter(fn (City $city): bool => $this->normalize($city->name) === $this->normalize($canonical));

        if ($candidates->isEmpty()) {
            throw new DomainException("Cidade de {$role} \"{$rawName}\" não encontrada na base IBGE local (cities). Rode ciot:seed-cities ou ajuste dfe.city_aliases.");
        }

        foreach (['SP', 'PR'] as $preferred) {
            $match = $candidates->first(fn (City $city): bool => $city->state === $preferred);

            if ($match !== null) {
                return $this->cityBlock($match);
            }
        }

        if ($candidates->count() === 1) {
            return $this->cityBlock($candidates->first());
        }

        $states = $candidates->pluck('state')->unique()->implode(', ');

        throw new DomainException("Cidade de {$role} \"{$rawName}\" é ambígua na base IBGE ({$states}) e não há preferência SP/PR — ajuste dfe.city_aliases.");
    }

    protected function resolveCompanyByName(string $name, string $role): Company
    {
        $normalized = $this->normalize($name);

        $company = Company::query()
            ->where('is_active', true)
            ->get()
            ->first(fn (Company $company): bool => $this->normalize($company->name) === $normalized);

        if ($company === null) {
            throw new DomainException("Empresa {$role} \"{$name}\" não encontrada no cadastro companies (com IE e endereço completos).");
        }

        return $company;
    }

    protected function resolveCompanyByIbge(string $ibge, string $role): Company
    {
        $company = Company::query()
            ->where('is_active', true)
            ->where('ibge_code', $ibge)
            ->orderBy('id')
            ->first();

        if ($company === null) {
            throw new DomainException("Nenhuma empresa ativa do cadastro companies no IBGE {$ibge} para {$role}.");
        }

        return $company;
    }

    /**
     * Bloco fiscal no formato do snapshot v2 (spec §2.5).
     *
     * @return array<string, string>
     */
    protected function companyBlock(Company $company): array
    {
        $incomplete = collect(['cnpj', 'ie', 'street', 'number', 'district', 'city', 'state', 'zipcode', 'ibge_code'])
            ->filter(fn (string $field): bool => blank($company->{$field}));

        if ($incomplete->isNotEmpty()) {
            throw new DomainException("A empresa \"{$company->name}\" (CNPJ {$company->cnpj}) está sem dados fiscais obrigatórios: {$incomplete->implode(', ')}.");
        }

        return [
            'cnpj' => (string) $company->cnpj,
            'ie' => (string) $company->ie,
            'xNome' => (string) $company->name,
            'xLgr' => (string) $company->street,
            'nro' => (string) $company->number,
            'xBairro' => (string) $company->district,
            'cMun' => (string) $company->ibge_code,
            'xMun' => (string) $company->city,
            'cep' => (string) $company->zipcode,
            'uf' => (string) $company->state,
        ];
    }

    /**
     * @return array{ibge: string, name: string, uf: string}
     */
    protected function cityBlock(City $city): array
    {
        return [
            'ibge' => (string) $city->ibge_code,
            'name' => (string) $city->name,
            'uf' => (string) $city->state,
        ];
    }

    /**
     * Chave de comparação: maiúsculas, sem acentos, sem espaços extras.
     */
    protected function normalize(string $value): string
    {
        $transliterated = strtr(trim($value), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N',
        ]);

        return mb_strtoupper(preg_replace('/\s+/', ' ', $transliterated) ?? $transliterated, 'UTF-8');
    }
}
