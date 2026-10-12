<?php

namespace App\Services\Cte;

use App\Models\City;
use App\Models\Company;
use App\Models\CompanyFiscalAlias;
use App\Models\CteDocument;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * Enriquece o snapshot v1 do documento (dados da remocao) com os blocos
 * fiscais de remetente/destinatário e os códigos IBGE
 * (spec dfe §2.5, decisões do cadastro companies).
 *
 * Cidades: nomes dos registros chegam abreviados/sem acento da extração de
 * PDF; a resolução é case/acento-insensível sobre a base IBGE local (cities),
 * com aliases configuráveis (dfe.city_aliases) e preferência SP → PR → único
 * match nacional.
 *
 * Partes: rem = seguradora do registro, dest = empresa no IBGE do destino —
 * sempre no cadastro companies; dado faltante é erro explícito, nunca chute.
 *
 * A linha tanque (tank_alcohol) usa snapshots v2 antigos ou v3 com NF-es
 * arquivadas; ambos são preservados como foram preparados.
 */
class ResolveCteSnapshot
{
    private ?Collection $companies = null;

    private ?Collection $aliases = null;

    private array $resolvedCities = [];

    public function __construct(private AssertCteNfeOriginalsArchived $assertNfeOriginalsArchived) {}

    public function handle(CteDocument $document): array
    {
        $base = (array) $document->snapshot;

        if ((int) ($base['schema_version'] ?? 1) >= 2) {
            $this->assertNfeOriginalsArchived->handle($document);

            return $base;
        }

        $line = (string) ($base['line'] ?? 'vehicle_removal');

        if ($line !== 'vehicle_removal') {
            throw new DomainException("A linha {$line} ainda não é resolvida a partir de registros — informe o snapshot v2 completo.");
        }

        $origin = $this->resolveCity((string) $base['origin_city'], 'origem');
        $sender = $this->resolveSender((string) $base['insurance']);
        $destination = $this->resolveDestination((string) $base['destination_city']);

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
            'destination' => $destination['city'],
            'rem' => $sender['party'],
            'dest' => $destination['party'],
            'rem_company_id' => $sender['company_id'],
            'dest_company_id' => $destination['company_id'],
        ];
    }

    /** @return array{company_id: int, party: array<string, string|null>} */
    public function resolveSender(string $name): array
    {
        $company = $this->resolveCompanyByName($name, 'seguradora (rem)');

        return ['company_id' => $company->id, 'party' => $this->companyBlock($company)];
    }

    /** @return array{company_id: int, city_id: int, party: array<string, string|null>, city: array{ibge: string, name: string, uf: string}} */
    public function resolveDestination(string $name): array
    {
        $alias = $this->findAlias('destination', $name);

        if ($alias !== null) {
            if ($alias->city === null) {
                throw new DomainException("O destino \"{$name}\" está sem município confirmado.");
            }

            $company = $this->activeAliasCompany($alias);
            $city = $this->cityBlock($alias->city);
        } else {
            $city = $this->resolveCity($name, 'destino');
            $company = $this->resolveCompanyByIbge($city['ibge'], 'pátio/destinatário (dest)');
        }

        return [
            'company_id' => $company->id,
            'city_id' => $alias?->city_id ?? City::query()->where('ibge_code', $city['ibge'])->value('id'),
            'party' => $this->companyBlock($company),
            'city' => $city,
        ];
    }

    /**
     * @return array{ibge: string, name: string, uf: string}
     */
    public function resolveCity(string $rawName, string $role): array
    {
        $rawName = trim($rawName);
        $cacheKey = $role.'|'.$this->normalize($rawName);

        if (isset($this->resolvedCities[$cacheKey])) {
            return $this->resolvedCities[$cacheKey];
        }

        if ($rawName === '') {
            throw new DomainException("Cidade de {$role} vazia no snapshot.");
        }

        $canonical = (string) (config('dfe.city_aliases')[$this->normalize($rawName)] ?? $rawName);

        // Prefixo de UMA letra: nomes com acento na 2ª+ letra ("São Carlos")
        // não casam com like de prefixo maior em maiúsculas sem acento; a
        // comparação exata é feita normalizada em PHP abaixo.
        $candidates = City::query()
            ->whereLike('name', mb_substr($canonical, 0, 1).'%')
            ->get()
            ->filter(fn (City $city): bool => $this->normalize($city->name) === $this->normalize($canonical));

        if ($candidates->isEmpty()) {
            throw new DomainException("Cidade de {$role} \"{$rawName}\" não encontrada na base IBGE local (cities). Rode ciot:seed-cities ou ajuste dfe.city_aliases.");
        }

        foreach (['SP', 'PR'] as $preferred) {
            $match = $candidates->first(fn (City $city): bool => $city->state === $preferred);

            if ($match !== null) {
                return $this->resolvedCities[$cacheKey] = $this->cityBlock($match);
            }
        }

        if ($candidates->count() === 1) {
            return $this->resolvedCities[$cacheKey] = $this->cityBlock($candidates->first());
        }

        $states = $candidates->pluck('state')->unique()->implode(', ');

        throw new DomainException("Cidade de {$role} \"{$rawName}\" é ambígua na base IBGE ({$states}) e não há preferência SP/PR — ajuste dfe.city_aliases.");
    }

    protected function resolveCompanyByName(string $name, string $role): Company
    {
        $alias = $this->findAlias('sender', $name);

        if ($alias !== null) {
            return $this->activeAliasCompany($alias);
        }

        $normalized = $this->normalize($name);

        $matches = $this->activeCompanies()
            ->filter(fn (Company $company): bool => $this->normalize($company->name) === $normalized);

        if ($matches->count() > 1) {
            throw new DomainException("Há mais de uma empresa para \"{$name}\"; confirme o CNPJ do remetente.");
        }

        $company = $matches->first();

        if ($company === null) {
            throw new DomainException("Empresa {$role} \"{$name}\" não encontrada no cadastro companies (com dados fiscais e endereço completos).");
        }

        return $company;
    }

    protected function resolveCompanyByIbge(string $ibge, string $role): Company
    {
        $matches = $this->activeCompanies()->where('ibge_code', $ibge);

        if ($matches->count() > 1) {
            throw new DomainException("Há mais de uma empresa no município {$ibge}; confirme o CNPJ do destinatário.");
        }

        $company = $matches->first();

        if ($company === null) {
            throw new DomainException("Nenhuma empresa ativa do cadastro companies no IBGE {$ibge} para {$role}.");
        }

        return $company;
    }

    /**
     * Bloco fiscal no formato do snapshot v2 (spec §2.5).
     *
     * @return array<string, string|null>
     */
    public function companyBlock(Company $company): array
    {
        $incomplete = collect(['cnpj', 'street', 'number', 'district', 'city', 'state', 'zipcode', 'ibge_code'])
            ->filter(fn (string $field): bool => blank($company->{$field}));

        if ($incomplete->isNotEmpty()) {
            throw new DomainException("A empresa \"{$company->name}\" (CNPJ {$company->cnpj}) está sem dados fiscais obrigatórios: {$incomplete->implode(', ')}.");
        }

        $ie = Company::normalizeIe($company->ie);

        if ($ie !== null && (in_array($ie, ['0', '1'], true) || ! preg_match('/^(?:[0-9]{1,14}|ISENTO)$/', $ie))) {
            throw new DomainException("A empresa \"{$company->name}\" possui IE inválida. Informe a inscrição real, ISENTO ou deixe vazio para não contribuinte.");
        }

        return [
            'cnpj' => (string) $company->cnpj,
            'ie' => $ie,
            'xNome' => trim((string) $company->name),
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
        return CompanyFiscalAlias::normalize($value);
    }

    private function activeCompanies(): Collection
    {
        return $this->companies ??= Company::query()->where('is_active', true)->get();
    }

    private function findAlias(string $role, string $name): ?CompanyFiscalAlias
    {
        $this->aliases ??= CompanyFiscalAlias::query()->with(['company', 'city'])->get();

        return $this->aliases->first(fn (CompanyFiscalAlias $alias): bool => $alias->role === $role
            && $alias->normalized_name === CompanyFiscalAlias::normalize($name));
    }

    private function activeAliasCompany(CompanyFiscalAlias $alias): Company
    {
        if ($alias->company === null || ! $alias->company->is_active) {
            throw new DomainException("A empresa associada a \"{$alias->source_name}\" está inativa ou não existe.");
        }

        return $alias->company;
    }
}
