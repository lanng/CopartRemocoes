<?php

namespace App\Services\Cte;

use App\Services\Dfe\AccessKey;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DomainException;

/**
 * CT-e construído do snapshot para transmissão (spec §2.4, §2.5): numeração
 * atômica, chave/cDV, regras de ambiente derivadas de dfe.tp_amb e ordem
 * estrita de xs:sequence dos gabaritos autorizados (2962/2963). Saída = XML
 * assinável (sem Signature — o XmlSigner entra depois, F3).
 *
 * O snapshot é o contrato v2: além dos dados da viagem/frete, carrega os
 * blocos fiscais de rem/dest (que o Lab digitava à mão) e a bifurcação da
 * linha (veículos = infOutros; tanque = infNFe).
 */
class BuildCteXml
{
    protected const HOMOLOG_PARTY_CNPJ = '00000000000191';

    protected const HOMOLOG_PARTY_NAME = 'CTE EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';

    public function __construct(
        protected AllocateCteNumber $allocator,
        protected AccessKey $accessKey,
    ) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function handle(array $snapshot): BuiltCte
    {
        $this->assertSnapshotComplete($snapshot);

        $serie = (int) config('dfe.cte.serie');
        $number = $this->allocator->handle();
        $cct = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);

        $accessKey = $this->accessKey->build(
            cnpj: (string) config('dfe.emit.cnpj'),
            aamm: $this->emissionDate($snapshot)->format('ym'),
            serie: $serie,
            number: $number,
            modelo: AccessKey::MODELO_CTE,
            tpEmis: (string) config('dfe.cte.tpEmis'),
            cct: $cct,
        );

        return new BuiltCte(
            xml: $this->render($snapshot, $accessKey, $number, $serie, $cct),
            accessKey: $accessKey,
            number: $number,
            serie: $serie,
            cct: $cct,
        );
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function render(array $snapshot, string $accessKey, int $number, int $serie, string $cct): string
    {
        $emittedAt = $this->emissionDate($snapshot);
        $isHomolog = (string) config('dfe.tp_amb') !== '1';
        $freight = $this->decimal($snapshot['value'], 2);

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<CTe xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<infCte Id="CTe'.$accessKey.'" versao="'.$this->version().'">'
            .'<ide>'
            .'<cUF>'.(int) config('dfe.cuf').'</cUF>'
            .'<cCT>'.str_pad($cct, 8, '0', STR_PAD_LEFT).'</cCT>'
            .'<CFOP>'.e((string) config('dfe.cte.cfop')).'</CFOP>'
            .'<natOp>'.e((string) config('dfe.cte.natOp')).'</natOp>'
            .'<mod>'.AccessKey::MODELO_CTE.'</mod>'
            .'<serie>'.$serie.'</serie>'
            .'<nCT>'.$number.'</nCT>'
            .'<dhEmi>'.$emittedAt->format('Y-m-d\TH:i:sP').'</dhEmi>'
            .'<tpImp>1</tpImp>'
            .'<tpEmis>'.e((string) config('dfe.cte.tpEmis')).'</tpEmis>'
            .'<cDV>'.substr($accessKey, -1).'</cDV>'
            .'<tpAmb>'.($isHomolog ? '2' : '1').'</tpAmb>'
            .'<tpCTe>0</tpCTe>'
            .'<procEmi>0</procEmi>'
            .'<verProc>'.e((string) config('dfe.cte.verProc')).'</verProc>'
            .'<cMunEnv>'.e((string) config('dfe.emit.cMun')).'</cMunEnv>'
            .'<xMunEnv>'.e((string) config('dfe.emit.xMun')).'</xMunEnv>'
            .'<UFEnv>'.e((string) config('dfe.emit.uf')).'</UFEnv>'
            .'<modal>'.e((string) config('dfe.cte.modal')).'</modal>'
            .'<tpServ>0</tpServ>'
            .$this->cityGroup('Ini', $snapshot['origin'])
            .$this->cityGroup('Fim', $snapshot['destination'])
            .'<retira>0</retira>'
            .'<indIEToma>'.($isHomolog ? '9' : '1').'</indIEToma>'
            .'<toma3><toma>3</toma></toma3>'
            .'</ide>'
            .'<compl><Entrega><semData><tpPer>0</tpPer></semData><semHora><tpHor>0</tpHor></semHora></Entrega></compl>'
            .$this->emitGroup()
            .$this->partyGroup('rem', $snapshot['rem'], $isHomolog, withFantasyName: true)
            .$this->partyGroup('dest', $snapshot['dest'], $isHomolog, withFantasyName: false)
            .'<vPrest><vTPrest>'.$freight.'</vTPrest><vRec>'.$freight.'</vRec>'
            .($this->isVehicleLine($snapshot) ? '<Comp><xNome>FRETE</xNome><vComp>'.$freight.'</vComp></Comp>' : '')
            .'</vPrest>'
            .'<imp><ICMS><ICMSSN><CST>90</CST><indSN>1</indSN></ICMSSN></ICMS></imp>'
            .'<infCTeNorm>'
            .$this->cargoGroup($snapshot)
            .$this->documentGroup($snapshot)
            .'<infModal versaoModal="'.$this->version().'"><rodo><RNTRC>'.e((string) config('dfe.cte.rntrc')).'</RNTRC></rodo></infModal>'
            .'</infCTeNorm>'
            .'</infCte>'
            .'<infCTeSupl xmlns="http://www.portalfiscal.inf.br/cte"><qrCodCTe><![CDATA['.$this->qrUrl($accessKey).']]></qrCodCTe></infCTeSupl>'
            .'</CTe>';

        return $xml;
    }

    /**
     * @param  array<string, mixed>  $city
     */
    protected function cityGroup(string $suffix, array $city): string
    {
        return '<cMun'.$suffix.'>'.e((string) $city['ibge']).'</cMun'.$suffix.'>'
            .'<xMun'.$suffix.'>'.e((string) $city['name']).'</xMun'.$suffix.'>'
            .'<UF'.$suffix.'>'.e((string) $city['uf']).'</UF'.$suffix.'>';
    }

    protected function emitGroup(): string
    {
        $emit = (array) config('dfe.emit');

        return '<emit>'
            .'<CNPJ>'.e((string) $emit['cnpj']).'</CNPJ>'
            .'<IE>'.e((string) $emit['ie']).'</IE>'
            .'<xNome>'.e((string) $emit['xNome']).'</xNome>'
            .'<xFant>'.e((string) $emit['xFant']).'</xFant>'
            .'<enderEmit>'
            .'<xLgr>'.e((string) $emit['xLgr']).'</xLgr>'
            .'<nro>'.e((string) $emit['nro']).'</nro>'
            .'<xBairro>'.e((string) $emit['xBairro']).'</xBairro>'
            .'<cMun>'.e((string) $emit['cMun']).'</cMun>'
            .'<xMun>'.e((string) $emit['xMun']).'</xMun>'
            .'<CEP>'.e((string) $emit['cep']).'</CEP>'
            .'<UF>'.e((string) $emit['uf']).'</UF>'
            .'</enderEmit>'
            .'<CRT>'.e((string) $emit['crt']).'</CRT>'
            .'</emit>';
    }

    /**
     * rem/dest (spec §2.4): em homologação CNPJ/nome de letreiro, sem IE e sem
     * xFant (gabarito homolog autorizado); em produção os dados reais do
     * snapshot — rem mantém xFant (2962), dest nunca teve xFant.
     *
     * @param  array<string, mixed>  $party
     */
    protected function partyGroup(string $tag, array $party, bool $isHomolog, bool $withFantasyName): string
    {
        $addressTag = $tag === 'rem' ? 'enderReme' : 'enderDest';

        $cnpj = $isHomolog ? self::HOMOLOG_PARTY_CNPJ : (string) $party['cnpj'];
        $name = $isHomolog ? self::HOMOLOG_PARTY_NAME : (string) $party['xNome'];
        $fantasy = ! $isHomolog && $withFantasyName
            ? '<xFant>'.e((string) ($party['xFant'] ?? $party['xNome'])).'</xFant>'
            : '';
        $ie = ! $isHomolog ? '<IE>'.e((string) $party['ie']).'</IE>' : '';

        return '<'.$tag.'>'
            .'<CNPJ>'.e($cnpj).'</CNPJ>'
            .$ie
            .'<xNome>'.e($name).'</xNome>'
            .$fantasy
            .'<'.$addressTag.'>'
            .'<xLgr>'.e((string) $party['xLgr']).'</xLgr>'
            .'<nro>'.e((string) $party['nro']).'</nro>'
            .'<xBairro>'.e((string) $party['xBairro']).'</xBairro>'
            .'<cMun>'.e((string) $party['cMun']).'</cMun>'
            .'<xMun>'.e((string) $party['xMun']).'</xMun>'
            .'<CEP>'.e((string) $party['cep']).'</CEP>'
            .'<UF>'.e((string) $party['uf']).'</UF>'
            .'<cPais>1058</cPais><xPais>Brasil</xPais>'
            .'</'.$addressTag.'>'
            .'</'.$tag.'>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function cargoGroup(array $snapshot): string
    {
        if ($this->isVehicleLine($snapshot)) {
            $proPred = strtoupper((string) $snapshot['vehicle_model']).'-PLACA:'.strtoupper((string) $snapshot['vehicle_plate']);
            $weight = $this->decimal($snapshot['cargo_weight_kg'] ?? config('dfe.cte.default_weight_kg'), 4);

            return '<infCarga>'
                .'<vCarga>'.$this->decimal($snapshot['fipe_value'], 2).'</vCarga>'
                .'<proPred>'.e($proPred).'</proPred>'
                .'<xOutCat>'.e((string) $snapshot['payment_code']).'</xOutCat>'
                .'<infQ><cUnid>01</cUnid><tpMed>KG</tpMed><qCarga>'.$weight.'</qCarga></infQ>'
                .'</infCarga>';
        }

        return '<infCarga>'
            .'<vCarga>'.$this->decimal($snapshot['cargo_value'], 2).'</vCarga>'
            .'<proPred>ETANOL HIDRATADO COMBUSTIVEL</proPred>'
            .'<infQ><cUnid>04</cUnid><tpMed>LITROS</tpMed><qCarga>'.$this->decimal($snapshot['cargo_liters'], 4).'</qCarga></infQ>'
            .'</infCarga>';
    }

    /**
     * Bifurcação por linha (spec §2.5): veículos = infOutros (carta de remoção);
     * tanque = infNFe (chave + dPrev).
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function documentGroup(array $snapshot): string
    {
        if ($this->isVehicleLine($snapshot)) {
            $emittedOn = $this->emissionDate($snapshot)->format('Y-m-d');

            return '<infDoc><infOutros>'
                .'<tpDoc>99</tpDoc>'
                .'<descOutros>CARTA DE REMOCAO</descOutros>'
                .'<nDoc>0</nDoc>'
                .'<dEmi>'.e((string) ($snapshot['removal_letter_date'] ?? $emittedOn)).'</dEmi>'
                .'</infOutros></infDoc>';
        }

        return '<infDoc><infNFe>'
            .'<chave>'.e((string) $snapshot['nfe_key']).'</chave>'
            .'<dPrev>'.e((string) $snapshot['dprev']).'</dPrev>'
            .'</infNFe></infDoc>';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function isVehicleLine(array $snapshot): bool
    {
        return ($snapshot['line'] ?? 'vehicle_removal') !== 'tank_alcohol';
    }

    protected function qrUrl(string $accessKey): string
    {
        $base = (string) env('DFE_QR_BASE_URL', match ((string) config('dfe.tp_amb')) {
            '1' => 'https://nfe.fazenda.sp.gov.br/CTeConsulta',
            default => 'https://homologacao.nfe.fazenda.sp.gov.br/CTeConsulta',
        });

        return rtrim($base, '/').'/qrCode?chCTe='.$accessKey.'&tpAmb='.(string) config('dfe.tp_amb');
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function assertSnapshotComplete(array $snapshot): void
    {
        $missing = [];

        if (isset($snapshot['schema_version']) && (int) $snapshot['schema_version'] < 2) {
            throw new DomainException('Snapshot v1 não suportado pelo emissor direto: rem/dest fiscais não foram capturados (spec §2.5).');
        }

        foreach (['value', 'origin', 'destination', 'rem', 'dest'] as $field) {
            if (blank($snapshot[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        foreach (['origin', 'destination'] as $field) {
            foreach (['ibge', 'name', 'uf'] as $key) {
                if (blank($snapshot[$field][$key] ?? null)) {
                    $missing[] = $field.'.'.$key;
                }
            }
        }

        foreach (['rem', 'dest'] as $field) {
            foreach (['cnpj', 'xLgr', 'nro', 'xBairro', 'cMun', 'xMun', 'cep', 'uf'] as $key) {
                if (blank($snapshot[$field][$key] ?? null)) {
                    $missing[] = $field.'.'.$key;
                }
            }

            // IE só existe no documento em produção (homologação usa letreiro).
            if ((string) config('dfe.tp_amb') === '1' && blank($snapshot[$field]['ie'] ?? null)) {
                $missing[] = $field.'.ie';
            }
        }

        if ($this->isVehicleLine($snapshot)) {
            foreach (['vehicle_model', 'vehicle_plate', 'payment_code', 'fipe_value'] as $field) {
                if (blank($snapshot[$field] ?? null)) {
                    $missing[] = $field;
                }
            }
        } else {
            foreach (['nfe_key', 'dprev', 'cargo_value', 'cargo_liters'] as $field) {
                if (blank($snapshot[$field] ?? null)) {
                    $missing[] = $field;
                }
            }

            if (filled($snapshot['nfe_key'] ?? null) && ! preg_match('/^\d{44}$/', (string) $snapshot['nfe_key'])) {
                $missing[] = 'nfe_key (44 dígitos numéricos)';
            }
        }

        if ($missing !== []) {
            throw new DomainException('Snapshot incompleto para o emissor direto: '.implode(', ', $missing).'.');
        }
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    protected function emissionDate(array $snapshot): CarbonInterface
    {
        return isset($snapshot['emitted_at'])
            ? Carbon::parse((string) $snapshot['emitted_at'])->timezone('America/Sao_Paulo')
            : now()->timezone('America/Sao_Paulo');
    }

    protected function decimal(mixed $value, int $decimals): string
    {
        return number_format((float) str_replace(',', '.', (string) $value), $decimals, '.', '');
    }

    protected function version(): string
    {
        return (string) config('dfe.cte_version', '4.00');
    }
}
