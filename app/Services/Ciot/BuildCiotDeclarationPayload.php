<?php

namespace App\Services\Ciot;

use App\Enums\CiotLineEnum;
use App\Enums\CiotOperationTypeEnum;
use App\Enums\CiotStatusEnum;
use App\Models\Ciot;
use DomainException;

/**
 * Monta o JSON de `DeclaracaoOperacaoTransporte` (DCS) a partir do CIOT.
 * Valida as regras da ANTT que podemos rejeitar no client (B82/B83/B84/B111/B119),
 * para não gastar tentativa na homologação/produção.
 */
class BuildCiotDeclarationPayload
{
    /**
     * @return array<string, mixed>
     */
    public function handle(Ciot $ciot): array
    {
        $this->assertBusinessRules($ciot);

        $payload = [
            // Propriedade raiz exigida pelo binder do /gerar (validada em homologação).
            'cpfCnpj' => (string) config('ciot.company.cnpj'),
            'TipoOperacao' => $ciot->operation_type->code(),
            'CpfCnpjContratado' => (string) config('ciot.company.cnpj'),
            'RNTRCContratado' => (string) config('ciot.company.rntrc'),
            'CpfCnpjContratante' => $ciot->payer_cnpj,
            'RNTRCContratante' => '',
            'CpfCnpjDestinatario' => $ciot->delivery_payer_cnpj ?? $ciot->payer_cnpj,
            'ValorFrete' => round($ciot->freight_value_cents / 100, 2),
            'DataDeclaracao' => now()->format('Y-m-d\TH:i:sP'),
            'IndContingencia' => false,
            'DataInicioViagem' => $ciot->travel_start_at?->format('Y-m-d'),
            'DataFimViagem' => $ciot->travel_end_at?->format('Y-m-d'),
            'Veiculos' => $this->buildVehicles($ciot),
            'OrigemDestino' => [$this->buildRoute($ciot)],
            'DadosCarga' => $this->buildCargo($ciot),
            'InfPagamento' => [$this->buildPayment($ciot)],
        ];

        $indicators = $this->buildIndicators($ciot);

        if ($indicators !== []) {
            $payload['InfIndicadoresOperacionais'] = $indicators;
        }

        return $payload;
    }

    protected function assertBusinessRules(Ciot $ciot): void
    {
        if ($ciot->status === CiotStatusEnum::ISSUED) {
            throw new DomainException('Este CIOT já foi emitido.');
        }

        if ($ciot->line === CiotLineEnum::TankAlcohol && $ciot->operation_type !== CiotOperationTypeEnum::Lotation) {
            throw new DomainException('A linha de tanque de álcool opera sempre em lotação.');
        }

        $vehicles = $ciot->vehicles ?? [];

        $automotores = array_filter($vehicles, fn (array $vehicle): bool => ($vehicle['tipo'] ?? '') === 'automotor');

        if (count($automotores) !== 1) {
            throw new DomainException('A composição deve ter exatamente 1 veículo automotor (regras B83/B84 da ANTT).');
        }

        if ($ciot->distance_km === null || (float) $ciot->distance_km <= 0) {
            throw new DomainException('A distância percorrida deve ser maior que zero (regra B82 da ANTT).');
        }

        if (blank($ciot->origin['ibge'] ?? null) || blank($ciot->destination['ibge'] ?? null)) {
            throw new DomainException('Informe o código IBGE da cidade de origem e da cidade de destino.');
        }

        if ((int) $ciot->freight_value_cents <= 0) {
            throw new DomainException('O valor do frete deve ser maior que zero.');
        }

        $additional = $ciot->additional_payers ?? [];

        if ($ciot->operation_type === CiotOperationTypeEnum::Fractioned) {
            if ($additional === []) {
                throw new DomainException('Operação fracionada exige ao menos um contratante adicional (ContratantesCargaFrac).');
            }

            if (in_array($ciot->payer_cnpj, $additional, true)) {
                throw new DomainException('Os contratantes adicionais devem diferir do contratante principal (regra B119 da ANTT).');
            }
        }

        $viagemFim = $ciot->travel_end_at;
        $viagemInicio = $ciot->travel_start_at;

        if ($viagemInicio === null || $viagemFim === null) {
            throw new DomainException('Informe as datas de início e fim da viagem.');
        }

        if ($viagemInicio->startOfDay()->lt(today()->startOfDay())) {
            throw new DomainException('A data de início da viagem não pode estar no passado.');
        }

        if ($viagemInicio->gt($viagemFim) || $viagemInicio->diffInDays($viagemFim) > 90) {
            throw new DomainException('A viagem não pode exceder 90 dias.');
        }
    }

    /**
     * Nomes reais no wire (spec §2.1): `Placa` PascalCase, `rntrc`/`numeroEixos`
     * camelCase. Não existe campo de tipo — a classificação automotor/implemento
     * vem da base RNTRC pela placa.
     *
     * @return list<array<string, mixed>>
     */
    protected function buildVehicles(Ciot $ciot): array
    {
        return array_values(array_map(fn (array $vehicle): array => [
            'Placa' => (string) $vehicle['placa'],
            'rntrc' => (string) ($vehicle['rntrc'] ?? config('ciot.company.rntrc')),
            'numeroEixos' => (int) $vehicle['eixos'],
        ], $ciot->vehicles ?? []));
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRoute(Ciot $ciot): array
    {
        return [
            'Origem' => $this->buildLocation($ciot->origin ?? [], 'Origem'),
            'Destino' => $this->buildLocation($ciot->destination ?? [], 'Destino'),
            'DistanciaPercorrida' => (float) $ciot->distance_km,
        ];
    }

    /**
     * Somente o município IBGE (spec §2.2): a ANTT mantém base própria de CEPs
     * e rejeita CEPs que não conhece ("ainda não está cadastrado em nossos
     * sistemas") mesmo com IBGE presente — município sozinho é determinístico
     * e sempre aceito.
     *
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    protected function buildLocation(array $location, string $suffix): array
    {
        return [
            "CodigoMunicipio{$suffix}" => filled($location['ibge'] ?? null) ? (int) $location['ibge'] : null,
        ];
    }

    /**
     * `ContratantesCargFrac` (nome com o typo da ANTT, sem o "a" — DCS pág. 25
     * e a própria mensagem de rejeição grafam assim) é um List<string> de
     * CNPJs/CPFs: objetos crasham o transformer com NRE 500, e a grafia
     * correta não liga (a ANTT responde "campo obrigatório"). Proibido na
     * lotação (B64) e obrigatório na fracionada.
     *
     * @return array<string, mixed>
     */
    protected function buildCargo(Ciot $ciot): array
    {
        // A base de naturezas da homologação só conhece o código 1 (spec §2.1);
        // em produção vale a tabela oficial por linha (13 remoção / 8 tanque).
        $fallback = (bool) config('ciot.natureza_fallback');

        $cargo = [
            'CodigoNaturezaCarga' => $fallback ? 1 : (int) $ciot->line->naturezaCarga(),
            'CodigoTipoCarga' => $fallback ? 1 : $ciot->line->tipoCarga(),
        ];

        if ($ciot->cargo_weight_kg !== null) {
            $cargo['PesoCarga'] = (float) $ciot->cargo_weight_kg;
        }

        if ($ciot->operation_type === CiotOperationTypeEnum::Fractioned) {
            $cargo['ContratantesCargFrac'] = array_values($ciot->additional_payers ?? []);
        }

        return $cargo;
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildPayment(Ciot $ciot): array
    {
        $line = config("ciot.lines.{$ciot->line->configKey()}");
        $bankCode = $line['bank_code'] ?? null;
        $agency = $line['bank_agency'] ?? null;
        $account = $line['bank_account'] ?? null;

        if (blank($bankCode) || blank($agency) || blank($account)) {
            throw new DomainException(sprintf(
                'Dados bancários da linha "%s" não configurados. Defina as variáveis CIOT_%s_BANK_* no .env.',
                $ciot->line->label(),
                strtoupper($ciot->line->configKey()),
            ));
        }

        return [
            'TipoPagamento' => 2,
            'CodigoInstituicaoFinanceira' => (string) $bankCode,
            'NumeroAgencia' => (string) $agency,
            'NumeroConta' => (string) $account,
            'CpfCnpjCreditado' => (string) config('ciot.company.cnpj'),
            'IndPagamento' => 0,
        ];
    }

    /**
     * Indicadores só existem para lotação (tipo 1). Para fracionada a chave é
     * OMITIDA — array vazio derruba o transformer da ANTT com
     * NullReferenceException (HTTP 500), comprovado por sonda na homologação e
     * reproduzido em produção (25/09/2026).
     *
     * @return array<string, bool>|array{}
     */
    protected function buildIndicators(Ciot $ciot): array
    {
        if ($ciot->operation_type !== CiotOperationTypeEnum::Lotation) {
            return [];
        }

        $indicators = $ciot->indicators ?? [];

        return [
            'IndAltoDesempenho' => (bool) ($indicators['ind_alto_desempenho'] ?? config('ciot.defaults.ind_alto_desempenho')),
            'IndRetornoVazio' => (bool) ($indicators['ind_retorno_vazio'] ?? config('ciot.defaults.ind_retorno_vazio')),
            'ComposicaoVeicular' => (bool) ($indicators['composicao_veicular'] ?? config('ciot.defaults.composicao_veicular')),
        ];
    }
}
