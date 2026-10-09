<?php

namespace App\Services\Dfe;

use App\Models\CteDocument;
use Carbon\CarbonImmutable;
use DomainException;

class BuildCteCancellationEvent
{
    public function __construct(private readonly XsdValidator $validator) {}

    public function handle(
        CteDocument $document,
        string $justification,
        ?CarbonImmutable $eventDate = null,
    ): BuiltCancellationEvent {
        $accessKey = (string) $document->access_key;
        $protocol = (string) $document->protocol;
        $justification = trim($justification);
        $issuerCnpj = (string) config('dfe.emit.cnpj');

        if (! preg_match('/^[0-9]{44}$/', $accessKey)) {
            throw new DomainException('A chave de acesso do CT-e deve conter 44 dígitos.');
        }

        if (! preg_match('/^[0-9]{15}$/', $protocol)) {
            throw new DomainException('O CT-e precisa ter protocolo de autorização válido com 15 dígitos.');
        }

        if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
            throw new DomainException('A justificativa do cancelamento do CT-e deve conter de 15 a 255 caracteres.');
        }

        if (! preg_match('/^[0-9]{14}$/', $issuerCnpj)) {
            throw new DomainException('O CNPJ do emitente do CT-e deve conter 14 dígitos.');
        }

        $eventDate ??= CarbonImmutable::now();
        $eventId = 'ID110111'.$accessKey.'001';
        $cOrgao = str_pad((string) config('dfe.cuf'), 2, '0', STR_PAD_LEFT);
        $detail = '<evCancCTe><descEvento>Cancelamento</descEvento>'
            .'<nProt>'.e($protocol).'</nProt><xJust>'.e($justification).'</xJust></evCancCTe>';
        $detailXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .str_replace('<evCancCTe>', '<evCancCTe xmlns="http://www.portalfiscal.inf.br/cte">', $detail);

        $this->validator->validateCteCancellationDetail($detailXml);

        $eventXml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<eventoCTe xmlns="http://www.portalfiscal.inf.br/cte" versao="4.00">'
            .'<infEvento Id="'.$eventId.'"><cOrgao>'.e($cOrgao).'</cOrgao>'
            .'<tpAmb>'.e((string) config('dfe.tp_amb')).'</tpAmb>'
            .'<CNPJ>'.e($issuerCnpj).'</CNPJ><chCTe>'.e($accessKey).'</chCTe>'
            .'<dhEvento>'.$eventDate->format('Y-m-d\TH:i:sP').'</dhEvento>'
            .'<tpEvento>110111</tpEvento><nSeqEvento>1</nSeqEvento>'
            .'<detEvento versaoEvento="4.00">'.$detail.'</detEvento>'
            .'</infEvento></eventoCTe>';

        return new BuiltCancellationEvent($eventXml, $eventId, $eventDate);
    }
}
