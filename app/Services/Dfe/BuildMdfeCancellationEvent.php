<?php

namespace App\Services\Dfe;

use App\Models\MdfeDocument;
use Carbon\CarbonImmutable;
use DomainException;

class BuildMdfeCancellationEvent
{
    public function __construct(private readonly XsdValidator $validator) {}

    public function handle(
        MdfeDocument $document,
        string $justification,
        ?CarbonImmutable $eventDate = null,
    ): BuiltCancellationEvent {
        $accessKey = (string) $document->access_key;
        $protocol = (string) $document->protocol;
        $justification = trim($justification);
        $issuerCnpj = (string) config('dfe.emit.cnpj');

        if (! preg_match('/^[0-9]{44}$/', $accessKey)) {
            throw new DomainException('A chave de acesso do MDF-e deve conter 44 dígitos.');
        }

        if (! preg_match('/^[0-9]{15}$/', $protocol)) {
            throw new DomainException('O MDF-e precisa ter protocolo de autorização válido com 15 dígitos.');
        }

        if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
            throw new DomainException('A justificativa do cancelamento do MDF-e deve conter de 15 a 255 caracteres.');
        }

        if (! preg_match('/^[0-9]{14}$/', $issuerCnpj)) {
            throw new DomainException('O CNPJ do emitente do MDF-e deve conter 14 dígitos.');
        }

        $eventDate ??= CarbonImmutable::now();
        $eventId = 'ID110111'.$accessKey.'01';
        $cOrgao = str_pad((string) config('dfe.cuf'), 2, '0', STR_PAD_LEFT);
        $detail = '<evCancMDFe><descEvento>Cancelamento</descEvento>'
            .'<nProt>'.e($protocol).'</nProt><xJust>'.e($justification).'</xJust></evCancMDFe>';
        $detailXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .str_replace('<evCancMDFe>', '<evCancMDFe xmlns="http://www.portalfiscal.inf.br/mdfe">', $detail);

        $this->validator->validateMdfeCancellationDetail($detailXml);

        $eventXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<eventoMDFe xmlns="http://www.portalfiscal.inf.br/mdfe" versao="3.00">'
            .'<infEvento Id="'.$eventId.'"><cOrgao>'.e($cOrgao).'</cOrgao>'
            .'<tpAmb>'.e((string) config('dfe.tp_amb')).'</tpAmb>'
            .'<CNPJ>'.e($issuerCnpj).'</CNPJ><chMDFe>'.e($accessKey).'</chMDFe>'
            .'<dhEvento>'.$eventDate->format('Y-m-d\TH:i:sP').'</dhEvento>'
            .'<tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento>'
            .'<detEvento versaoEvento="3.00">'.$detail.'</detEvento>'
            .'</infEvento></eventoMDFe>';

        return new BuiltCancellationEvent($eventXml, $eventId, $eventDate);
    }
}
