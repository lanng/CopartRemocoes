<?php

namespace App\Services\Dfe;

use App\Models\City;
use App\Models\MdfeDocument;
use Carbon\CarbonImmutable;
use DomainException;

class BuildMdfeClosureEvent
{
    /** @var array<string, string> */
    private const UF_CODES = [
        'RO' => '11', 'AC' => '12', 'AM' => '13', 'RR' => '14', 'PA' => '15', 'AP' => '16', 'TO' => '17',
        'MA' => '21', 'PI' => '22', 'CE' => '23', 'RN' => '24', 'PB' => '25', 'PE' => '26', 'AL' => '27',
        'SE' => '28', 'BA' => '29', 'MG' => '31', 'ES' => '32', 'RJ' => '33', 'SP' => '35', 'PR' => '41',
        'SC' => '42', 'RS' => '43', 'MS' => '50', 'MT' => '51', 'GO' => '52', 'DF' => '53',
    ];

    public function __construct(private readonly XsdValidator $validator) {}

    public function handle(MdfeDocument $document, City $city, ?CarbonImmutable $closedAt = null): BuiltMdfeEvent
    {
        if (blank($document->access_key) || blank($document->protocol)) {
            throw new DomainException('O MDF-e precisa ter chave de acesso e protocolo de autorização para ser encerrado.');
        }

        if (! preg_match('/^[0-9]{44}$/', (string) $document->access_key)) {
            throw new DomainException('A chave de acesso do MDF-e não possui 44 dígitos.');
        }

        if (! preg_match('/^[0-9]{7}$/', (string) $city->ibge_code)) {
            throw new DomainException('A cidade de encerramento não possui código IBGE válido.');
        }

        $closedAt ??= CarbonImmutable::now();
        $eventId = 'ID110112'.$document->access_key.'01';
        $cOrgao = str_pad((string) config('dfe.cuf'), 2, '0', STR_PAD_LEFT);
        $cUf = self::UF_CODES[$city->state] ?? '';

        if ($cUf === '') {
            throw new DomainException('A UF da cidade de encerramento não possui código IBGE configurado.');
        }

        if (substr((string) $city->ibge_code, 0, 2) !== $cUf) {
            throw new DomainException('A UF cadastrada para a cidade não corresponde ao prefixo do código IBGE.');
        }

        $detail = '<evEncMDFe><descEvento>Encerramento</descEvento>'
            .'<nProt>'.e((string) $document->protocol).'</nProt>'
            .'<dtEnc>'.$closedAt->format('Y-m-d').'</dtEnc>'
            .'<cUF>'.$cUf.'</cUF><cMun>'.e((string) $city->ibge_code).'</cMun></evEncMDFe>';
        $detailXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .str_replace('<evEncMDFe>', '<evEncMDFe xmlns="http://www.portalfiscal.inf.br/mdfe">', $detail);
        $this->validator->validateMdfeClosureDetail($detailXml);

        $eventXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<eventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00">'
            .'<infEvento Id="'.$eventId.'"><cOrgao>'.e($cOrgao).'</cOrgao>'
            .'<tpAmb>'.e((string) config('dfe.tp_amb')).'</tpAmb>'
            .'<CNPJ>'.e((string) config('dfe.emit.cnpj')).'</CNPJ>'
            .'<chMDFe>'.e((string) $document->access_key).'</chMDFe>'
            .'<dhEvento>'.$closedAt->format('Y-m-d\TH:i:sP').'</dhEvento>'
            .'<tpEvento>110112</tpEvento><nSeqEvento>1</nSeqEvento>'
            .'<detEvento versaoEvento="3.00">'.$detail.'</detEvento>'
            .'</infEvento></eventoMDFe>';

        return new BuiltMdfeEvent($eventXml, $eventId, $closedAt, $cUf, (string) $city->ibge_code);
    }
}
