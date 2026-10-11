{{-- Medidas em pontos do formulário de referência 30-09.pdf, em A4 retrato. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 0; }
        * { margin: 0; padding: 0; }
        body { font-family: 'Times-Roman', serif; font-size: 7pt; line-height: 1; color: #000; }
        .section { position: absolute; left: 17.5pt; width: 562pt; border: .5pt solid #000; }
        .field { position: absolute; }
        .label { font-size: 5pt; }
        .small { font-size: 6pt; }
        .bold { font-weight: bold; }
        .center { text-align: center; }
        .right { text-align: right; }
        .rule { position: absolute; border-top: .5pt solid #000; height: 0; }
        .divider { position: absolute; border-left: .5pt solid #000; width: 0; }
        .title { position: absolute; left: 0; top: 2pt; width: 100%; text-align: center; font-size: 7pt; }
        .party { position: absolute; width: 276pt; height: 43pt; }
        .party-name { left: 36pt; top: 2pt; width: 237pt; font-size: 6.5pt; }
        .party-name.long { font-size: 5.5pt; }
        .party.destination .party-name, .party.destination .party-address { left: 47pt; width: 227pt; }
        .party.destination .identity { left: 47pt !important; }
        .party-address { left: 36pt; top: 9pt; width: 237pt; font-size: 6.5pt; }
        .party-value { font-size: 6.5pt; }
        .homolog { font-size: 8pt; font-weight: bold; text-align: center; }
    </style>
</head>
<body>
@php
    $money = fn (?string $value): string => number_format((float) str_replace(',', '.', (string) $value), 2, ',', '.');
    $quantity = fn (?string $value): string => number_format((float) str_replace(',', '.', (string) $value), 4, ',', '.');
    $date = fn (?string $value): string => filled($value) ? \Carbon\Carbon::parse($value)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') : '';
    $cnpj = fn (string $value): string => strlen($value) === 14
        ? substr($value, 0, 2).'.'.substr($value, 2, 3).'.'.substr($value, 5, 3).'/'.substr($value, 8, 4).'-'.substr($value, 12)
        : $value;
    $cpf = fn (string $value): string => strlen($value) === 11
        ? substr($value, 0, 3).'.'.substr($value, 3, 3).'.'.substr($value, 6, 3).'-'.substr($value, 9)
        : $value;
    $taxId = fn (array $party): string => filled($party['CNPJ'] ?? null)
        ? $cnpj((string) $party['CNPJ'])
        : $cpf((string) ($party['CPF'] ?? ''));
    $cep = fn (string $value): string => strlen($value) === 8 ? substr($value, 0, 5).'-'.substr($value, 5) : $value;
    $number = implode('.', str_split(str_pad($dacte->cteNumber, 9, '0', STR_PAD_LEFT), 3));
    $unit = match ($dacte->cargo['cUnid'] ?? null) { '01' => 'KG', '04' => 'LT', default => 'UN' };
    $documents = $dacte->documents !== [] ? $dacte->documents : ($dacte->document !== null ? [$dacte->document] : []);
    $documentColumns = $documents !== [] ? array_chunk($documents, (int) ceil(count($documents) / 2)) : [[], []];
    $documentColumns = array_pad($documentColumns, 2, []);
    $deliveryDates = collect($documents)->where('kind', 'nfe')->pluck('dPrev')->filter()->unique()->values();
    $deliveryDate = $deliveryDates->count() === 1 ? $deliveryDates->first() : null;
@endphp

<div id="receipt" class="section" style="top: 22.5pt; height: 51.5pt">
    <div class="field center small" style="left: 0; top: 2pt; width: 562pt">DECLARO QUE RECEBI OS VOLUMES DESTE CONHECIMENTO EM PERFEITO ESTADO PELO QUE DOU POR CUMPRIDO O PRESENTE CONTRATO DE TRANSPORTE</div>
    <div class="rule" style="left: 0; top: 9.5pt; width: 562pt"></div>
    <div class="field" style="left: 4pt; top: 12pt">NOME</div>
    <div class="rule" style="left: 0; top: 28pt; width: 150pt"></div>
    <div class="field" style="left: 4pt; top: 33pt">RG</div>
    <div class="divider" style="left: 151.5pt; top: 10pt; height: 41pt"></div>
    <div class="field small center" style="left: 152pt; top: 42pt; width: 203pt">ASSINATURA / CARIMBO</div>
    <div class="divider" style="left: 355.5pt; top: 10pt; height: 41pt"></div>
    <div class="field center label" style="left: 356pt; top: 13pt; width: 89pt">CHEGADA DATA/HORA</div>
    <div class="field center" style="left: 356pt; top: 20pt; width: 89pt; font-family: Helvetica; font-size: 9pt">__/__/__ &nbsp; __:__</div>
    <div class="field center label" style="left: 356pt; top: 30pt; width: 89pt">SAÍDA DATA/HORA</div>
    <div class="field center" style="left: 356pt; top: 38pt; width: 89pt; font-family: Helvetica; font-size: 9pt">__/__/__ &nbsp; __:__</div>
    <div class="divider" style="left: 445.5pt; top: 10pt; height: 41pt"></div>
    <div class="field center bold" style="left: 446pt; top: 15pt; width: 116pt; font-size: 8pt">CT-E</div>
    <div class="field" style="left: 462pt; top: 27pt">N. <span class="bold" style="font-size: 10pt">&nbsp; {{ $number }}</span></div>
    <div class="field" style="left: 450pt; top: 38pt">SÉRIE: &nbsp; <span class="bold" style="font-size: 8pt">{{ $dacte->serie }}</span></div>
</div>
<div class="rule" style="left: 17.5pt; top: 81.5pt; width: 554pt"></div>

<div id="header" class="section" style="top: 85.5pt; height: 137pt">
    <div class="field center bold" style="left: 0; top: 3pt; width: 234pt; font-size: 9pt">{{ $dacte->emit['xNome'] }}</div>
    <div class="field small" style="left: 4pt; top: 25.5pt; line-height: 7.5pt">
        {{ $dacte->emit['xLgr'] }}, {{ $dacte->emit['nro'] }}<br>
        {{ $dacte->emit['xBairro'] }}<br>
        CEP: {{ $cep($dacte->emit['CEP']) }} - {{ $dacte->emit['xMun'] }} - {{ $dacte->emit['UF'] }}<br>
        CNPJ: {{ $cnpj($dacte->emit['CNPJ']) }}<br>
        INSCRIÇÃO ESTADUAL: {{ $dacte->emit['IE'] }}<br>
        TELEFONE:
    </div>
    <div class="divider" style="left: 234pt; top: 15pt; height: 122pt"></div>
    <div class="field center bold" style="left: 234pt; top: 3pt; width: 227pt; font-size: 9pt">DACTE</div>
    <div class="field center small bold" style="left: 234pt; top: 14pt; width: 227pt">Documento Auxiliar do Conhecimento de Transporte Eletrônico</div>
    <div class="divider" style="left: 461pt; top: 0; height: 115pt"></div>
    <div class="field center bold" style="left: 461pt; top: 4pt; width: 101pt; font-size: 8pt; line-height: 10pt">MODAL<br>RODOVIÁRIO</div>
    <div class="rule" style="left: 234pt; top: 23.5pt; width: 319pt"></div>
    @foreach ([['MODELO', '57', 234, 27], ['SÉRIE', $dacte->serie, 261, 19], ['NÚMERO', $number, 280, 67], ['FOLHA', '01/01', 347, 33], ['DATA E HORA DE EMISSÃO', $date($dacte->dhEmi), 380, 81]] as [$label, $value, $left, $width])
        <div class="field label center" style="left: {{ $left }}pt; top: 25pt; width: {{ $width }}pt">{{ $label }}</div>
        <div class="field bold center" style="left: {{ $left }}pt; top: 32pt; width: {{ $width }}pt; font-size: {{ $label === 'NÚMERO' ? 10 : 8 }}pt">{{ $value }}</div>
        @if ($left > 234)
            <div class="divider" style="left: {{ $left }}pt; top: 24pt; height: 18pt"></div>
        @endif
    @endforeach
    <div class="field label" style="left: 463pt; top: 25pt">INSC. SUFRAMA DO DESTINATÁRIO</div>
    <div class="rule" style="left: 234pt; top: 42pt; width: 319pt"></div>
    @if ($dacte->isHomolog())
        <div class="field homolog" style="left: 235pt; top: 47pt; width: 225pt">AMBIENTE DE HOMOLOGAÇÃO<br>SEM VALOR FISCAL</div>
    @endif
    <div class="rule" style="left: 234pt; top: 67.5pt; width: 227pt"></div>
    <div class="field small bold" style="left: 237pt; top: 69pt">Chave de acesso</div>
    <div class="field bold" style="left: 237pt; top: 78pt; font-size: 8pt">{{ implode(' ', $dacte->groupedAccessKey()) }}</div>
    <div class="rule" style="left: 0; top: 89.5pt; width: 461pt"></div>
    <div class="field center bold" style="left: 237pt; top: 92pt; width: 221pt; line-height: 9pt">Consulta de autenticidade no portal nacional do CT-e, no site da Sefaz<br>Autorizadora, ou em http://www.cte.fazenda.gov.br/portal</div>
    @if ($qrImage !== null)
        <img class="field" src="{{ $qrImage }}" alt="QR Code" style="left: 468pt; top: 39pt; width: 80pt; height: 80pt">
    @elseif ($dacte->qrUrl !== null)
        <div class="field label" style="left: 465pt; top: 47pt; width: 92pt; word-wrap: break-word">{{ $dacte->qrUrl }}</div>
    @endif
    <div class="divider" style="left: 131pt; top: 89.5pt; height: 44.5pt"></div>
    <div class="field label" style="left: 3pt; top: 94pt">TIPO DO CT-E</div>
    <div class="field" style="left: 3pt; top: 103pt">{{ $dacte->tpCte === '0' || $dacte->tpCte === '' ? 'NORMAL' : $dacte->tpCte }}</div>
    <div class="field label" style="left: 134pt; top: 94pt">TIPO DO SERVIÇO</div>
    <div class="field" style="left: 134pt; top: 103pt">{{ $dacte->tpServ === '0' || $dacte->tpServ === '' ? 'NORMAL' : $dacte->tpServ }}</div>
    <div class="rule" style="left: 0; top: 115pt; width: 562pt"></div>
    <div class="field label" style="left: 3pt; top: 117pt">INDICADOR DO CT-E GLOBALIZADO</div>
    <div class="field" style="left: 3pt; top: 126pt">NÃO</div>
    <div class="field label" style="left: 134pt; top: 117pt">INFORMAÇÕES DO CT-E GLOBALIZADO</div>
    <div class="field label bold" style="left: 250pt; top: 117pt">PROTOCOLO DE AUTORIZAÇÃO DE USO</div>
    <div class="field bold right" style="left: 237pt; top: 126pt; width: 224pt; font-size: 8pt">{{ $dacte->protocol }} &nbsp; {{ $date($dacte->receivedAt) }}</div>
</div>

<div class="section" style="top: 223.5pt; height: 37.25pt">
    <div class="field label" style="left: 3pt; top: 2pt">CFOP - NATUREZA DA OPERAÇÃO</div>
    <div class="field" style="left: 3pt; top: 10pt; font-size: 8pt">5353 - {{ $dacte->natOp }}</div>
    <div class="rule" style="left: 0; top: 18.75pt; width: 562pt"></div>
    <div class="divider" style="left: 276.75pt; top: 19pt; height: 18pt"></div>
    @foreach ([[$dacte->originCity, $dacte->originIbge, 'ORIGEM', 3], [$dacte->destinationCity, $dacte->destinationIbge, 'DESTINO', 280]] as [$city, $ibge, $label, $left])
        <div class="field label" style="left: {{ $left }}pt; top: 21pt">{{ $label }} DA PRESTAÇÃO</div>
        <div class="field" style="left: {{ $left }}pt; top: 29pt; font-size: 8pt">{{ $city }} - SP - {{ $ibge }}</div>
    @endforeach
</div>

<div id="parties" class="section" style="top: 261.75pt; height: 86pt">
    <div class="divider" style="left: 276.75pt; top: 0; height: 86pt"></div>
    <div class="rule" style="left: 0; top: 43pt; width: 562pt"></div>
    @foreach ([['REMETENTE', $dacte->rem, 0, 0], ['DESTINATÁRIO', $dacte->dest, 277, 0], ['EXPEDIDOR', [], 0, 43.5], ['RECEBEDOR', $dacte->receb, 277, 43.5]] as [$label, $party, $left, $top])
        <div class="party {{ $left > 0 ? 'destination' : '' }}" style="left: {{ $left }}pt; top: {{ $top }}pt">
            <div class="field label" style="left: 3pt; top: 2pt">{{ $label }}</div>
            <div class="field party-name {{ strlen($party['xNome'] ?? '') > 55 ? 'long' : '' }}">{{ $party['xNome'] ?? '' }}</div>
            <div class="field label" style="left: 3pt; top: 9pt">ENDEREÇO</div>
            <div class="field party-address">{{ isset($party['xLgr']) ? $party['xLgr'].', '.$party['nro'].(filled($party['xCpl'] ?? null) ? ' - '.$party['xCpl'] : '') : '' }}<br>{{ isset($party['xBairro']) ? $party['xBairro'] : '' }}</div>
            <div class="field label" style="left: 3pt; top: 20pt">MUNICÍPIO</div>
            <div class="field party-value identity" style="left: 36pt; top: 20pt">{{ isset($party['xMun']) ? $party['xMun'].' - '.$party['UF'] : '' }}</div>
            <div class="field label" style="left: 213pt; top: 20pt">CEP</div>
            <div class="field party-value" style="left: 226pt; top: 20pt">{{ $cep($party['CEP'] ?? '') }}</div>
            <div class="field label" style="left: 3pt; top: 27pt">CNPJ/CPF</div>
            <div class="field party-value identity" style="left: 36pt; top: 27pt">{{ $taxId($party) }}</div>
            <div class="field label" style="left: 130pt; top: 27pt">INSCRIÇÃO ESTADUAL</div>
            <div class="field party-value" style="left: 192pt; top: 27pt">{{ $party['IE'] ?? '' }}</div>
            <div class="field label" style="left: 3pt; top: 34pt">PAÍS</div>
            <div class="field party-value identity" style="left: 36pt; top: 34pt">{{ $party !== [] ? 'Brasil' : '' }}</div>
            <div class="field label" style="left: 196pt; top: 34pt">FONE</div>
        </div>
    @endforeach
</div>

<div class="section" style="top: 348.75pt; height: 25.25pt">
    <div class="field label" style="left: 3pt; top: 2pt">TOMADOR DO SERVIÇO</div>
    <div class="field" style="left: 66pt; top: 2pt; width: 210pt; font-size: {{ strlen($dacte->dest['xNome']) > 55 ? 5.5 : 6.5 }}pt">{{ $dacte->dest['xNome'] }}</div>
    <div class="field label" style="left: 280pt; top: 2pt">MUNICÍPIO</div>
    <div class="field" style="left: 312pt; top: 2pt">{{ $dacte->dest['xMun'] }} - {{ $dacte->dest['UF'] }}</div>
    <div class="field label" style="left: 489pt; top: 2pt">CEP</div>
    <div class="field" style="left: 502pt; top: 2pt">{{ $cep($dacte->dest['CEP']) }}</div>
    <div class="field label" style="left: 3pt; top: 9pt">ENDEREÇO</div>
    <div class="field" style="left: 36pt; top: 9pt; width: 335pt; font-size: 6.5pt">{{ $dacte->dest['xLgr'] }}, {{ $dacte->dest['nro'] }} - {{ $dacte->dest['xBairro'] }}</div>
    <div class="field label" style="left: 375pt; top: 9pt">PAÍS</div>
    <div class="field" style="left: 390pt; top: 9pt">Brasil</div>
    <div class="field label" style="left: 3pt; top: 16pt">CNPJ/CPF</div>
    <div class="field" style="left: 30pt; top: 16pt">{{ $taxId($dacte->dest) }}</div>
    <div class="field label" style="left: 125pt; top: 16pt">INSCRIÇÃO ESTADUAL</div>
    <div class="field" style="left: 191pt; top: 16pt">{{ $dacte->dest['IE'] }}</div>
    <div class="field label" style="left: 280pt; top: 16pt">FONE</div>
</div>

<div id="cargo" class="section" style="top: 375pt; height: 38pt">
    <div class="field label" style="left: 3pt; top: 2pt">PRODUTO PREDOMINANTE</div>
    <div class="field" style="left: 3pt; top: 10pt; width: 208pt">{{ $dacte->cargo['proPred'] ?? '' }}</div>
    <div class="divider" style="left: 211.5pt; top: 0; height: 18pt"></div>
    <div class="field label" style="left: 214pt; top: 2pt">OUTRAS CARACTERÍSTICAS DA CARGA</div>
    <div class="field" style="left: 214pt; top: 10pt; width: 189pt">{{ $dacte->cargo['xOutCat'] ?? '' }}</div>
    <div class="divider" style="left: 404.25pt; top: 0; height: 18pt"></div>
    <div class="field label" style="left: 409pt; top: 2pt">VALOR TOTAL DA MERCADORIA</div>
    <div class="field right bold" style="left: 407pt; top: 10pt; width: 137pt">{{ isset($dacte->cargo['vCarga']) ? $money($dacte->cargo['vCarga']) : '' }}</div>
    <div class="rule" style="left: 0; top: 18.5pt; width: 562pt"></div>
    @foreach (['PESO BRUTO (Kg)', 'PESO BASE CÁLC. (Kg)', 'PESO AFERIDO (Kg)', 'CUBAGEM (M3)'] as $index => $label)
        <div class="field label center" style="left: {{ $index * 84 }}pt; top: 21pt; width: 84pt">{{ $label }}</div>
        <div class="divider" style="left: {{ ($index + 1) * 84 }}pt; top: 19pt; height: 19pt"></div>
    @endforeach
    <div class="field center" style="left: 0; top: 29pt; width: 84pt">{{ ($dacte->cargo['cUnid'] ?? '') === '01' ? $quantity($dacte->cargo['qCarga']) : '' }}</div>
    <div class="field label center" style="left: 336pt; top: 21pt; width: 226pt">QTDE. VOLUMES (Unid)</div>
    <div class="field right" style="left: 339pt; top: 29pt; width: 208pt">{{ filled($dacte->cargo['tpMed'] ?? null) ? $dacte->cargo['tpMed'].': '.$quantity($dacte->cargo['qCarga']).' '.$unit : '' }}</div>
</div>

<div id="service" class="section" style="top: 414pt; height: 57.5pt">
    <div class="title">COMPONENTES DO VALOR DA PRESTAÇÃO DE SERVIÇO</div>
    <div class="rule" style="left: 0; top: 11pt; width: 562pt"></div>
    @for ($column = 0; $column < 3; $column++)
        <div class="field label" style="left: {{ 3 + $column * 139 }}pt; top: 14pt">NOME</div>
        <div class="field label right" style="left: {{ 98 + $column * 139 }}pt; top: 14pt; width: 36pt">VALOR</div>
        <div class="divider" style="left: {{ ($column + 1) * 139 }}pt; top: 11pt; height: 46pt"></div>
        @foreach (array_slice($dacte->components, $column * 4, 4) as $row => $component)
            <div class="field" style="left: {{ 3 + $column * 139 }}pt; top: {{ 23 + $row * 8 }}pt; width: 92pt">{{ $component['xNome'] }}</div>
            <div class="field right" style="left: {{ 95 + $column * 139 }}pt; top: {{ 23 + $row * 8 }}pt; width: 39pt">{{ $money($component['vComp']) }}</div>
        @endforeach
    @endfor
    <div class="field label" style="left: 420pt; top: 14pt">VALOR TOTAL DO SERVIÇO</div>
    <div class="field bold right" style="left: 420pt; top: 23pt; width: 122pt; font-size: 8pt">{{ $money($dacte->vTPrest) }}</div>
    <div class="rule" style="left: 417pt; top: 34.5pt; width: 136pt"></div>
    <div class="field label" style="left: 420pt; top: 37pt">VALOR A RECEBER</div>
    <div class="field bold right" style="left: 420pt; top: 47pt; width: 122pt; font-size: 8pt">{{ $money($dacte->vRec) }}</div>
</div>

<div class="section" style="top: 472.5pt; height: 28.25pt">
    <div class="title">INFORMAÇÕES RELATIVAS AO IMPOSTO</div>
    <div class="rule" style="left: 0; top: 11pt; width: 562pt"></div>
    <div class="field label" style="left: 3pt; top: 13pt">SITUAÇÃO TRIBUTÁRIA</div>
    <div class="field" style="left: 3pt; top: 20pt">{{ $dacte->cst !== '' ? $dacte->cst.' - SIMPLES NACIONAL' : '' }}</div>
    @foreach ([[259, 'BASE DE CÁLCULO'], [335, 'ALÍQ. ICMS'], [374, 'VALOR ICMS'], [487, '% RED.BC.CALC.']] as [$left, $label])
        <div class="divider" style="left: {{ $left }}pt; top: 11pt; height: 17pt"></div>
        <div class="field label" style="left: {{ $left + 4 }}pt; top: 13pt">{{ $label }}</div>
    @endforeach
</div>

<div id="documents" class="section" style="top: 501.75pt; height: 62pt">
    <div class="title">DOCUMENTOS ORIGINÁRIOS</div>
    <div class="rule" style="left: 0; top: 10.5pt; width: 562pt"></div>
    <div class="divider" style="left: 276.75pt; top: 11pt; height: 50pt"></div>
    @foreach ($documentColumns as $column => $columnDocuments)
        @php($left = $column * 277)
        <div class="field label" style="left: {{ $left + 3 }}pt; top: 13pt">TP DOC.</div>
        <div class="field label" style="left: {{ $left + 66 }}pt; top: 13pt">CHAVE DO DF-e</div>
        @foreach ($columnDocuments as $row => $originDocument)
            @php($top = 21 + ($row * 7.5))
            @if ($originDocument['kind'] === 'nfe')
                <div class="field small" style="left: {{ $left + 3 }}pt; top: {{ $top }}pt">NF-E {{ substr((string) $originDocument['chave'], 25, 9) }}</div>
                <div class="field small" style="left: {{ $left + 66 }}pt; top: {{ $top }}pt; width: 204pt">{{ implode(' ', str_split((string) $originDocument['chave'], 4)) }}</div>
            @elseif ($originDocument['kind'] === 'outros')
                <div class="field small" style="left: {{ $left + 3 }}pt; top: {{ $top }}pt; width: 75pt">{{ $originDocument['tpDoc'] }} {{ $originDocument['nDoc'] }}</div>
                <div class="field small" style="left: {{ $left + 66 }}pt; top: {{ $top }}pt; width: 204pt">{{ $originDocument['descOutros'] }} - Emissão: {{ $originDocument['dEmi'] }}</div>
            @endif
        @endforeach
    @endforeach
</div>

<div id="observations" class="section" style="top: 564.75pt; height: 32pt">
    <div class="title" style="top: 3pt">OBSERVAÇÕES</div>
    <div class="rule" style="left: 0; top: 13pt; width: 562pt"></div>
</div>
<div class="section" style="top: 597.75pt; height: 32pt">
    <div class="title">DADOS ESPECÍFICOS DO MODAL RODOVIÁRIO</div>
    <div class="rule" style="left: 0; top: 11pt; width: 562pt"></div>
    <div class="field label" style="left: 4pt; top: 13pt">RNTRC DA EMPRESA</div>
    <div class="field" style="left: 4pt; top: 20pt">{{ $dacte->rntrc }}</div>
    <div class="field label" style="left: 147pt; top: 13pt">DATA PREVISTA DE ENTREGA</div>
    <div class="divider" style="left: 224pt; top: 11pt; height: 21pt"></div>
    <div class="field center" style="left: 150pt; top: 20pt; width: 70pt">{{ filled($deliveryDate) ? \Carbon\Carbon::parse($deliveryDate)->format('d/m/Y') : '' }}</div>
    <div class="field small center" style="left: 227pt; top: 20pt; width: 332pt">ESTE CONHECIMENTO DE TRANSPORTE ATENDE À LEGISLAÇÃO DE TRANSPORTE RODOVIÁRIO EM VIGOR</div>
</div>
<div id="closing" class="section" style="top: 630.75pt; height: 50pt">
    <div class="field center" style="left: 0; top: 3pt; width: 374pt">USO EXCLUSIVO DO EMISSOR DO CT-e</div>
    <div class="field center" style="left: 374pt; top: 3pt; width: 188pt">RESERVADO AO FISCO</div>
    <div class="rule" style="left: 0; top: 11pt; width: 562pt"></div>
    <div class="divider" style="left: 374pt; top: 0; height: 50pt"></div>
</div>
<div class="field right" style="left: 17.5pt; top: 683pt; width: 550pt">Emitido por Sistema Interno</div>
</body>
</html>
