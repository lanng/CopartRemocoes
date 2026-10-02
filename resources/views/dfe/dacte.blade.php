{{-- DACTE — leiaute de mercado (padrão de facto ACBr) sobre os grupos do MOC CT-e 4.00 Anexo II.
    Dompdf: CSS simples, tabelas com bordas, DejaVu Sans (acentuação OK). --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #000; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { border: 1px solid #000; padding: 2px 3px; vertical-align: top; }
        .label { font-size: 7px; color: #333; text-transform: uppercase; }
        .bold { font-weight: bold; }
        .big { font-size: 14px; font-weight: bold; }
        .center { text-align: center; }
        .right { text-align: right; }
        .muted { font-size: 8px; color: #444; }
        .gap td { border: none; padding: 1px; }
        .homolog { background: #000; color: #fff; font-weight: bold; font-size: 11px; text-align: center; padding: 4px; border: 1px solid #000; }
        .qr img { width: 92px; height: 92px; }
    </style>
</head>
<body>
@php
    $money = fn (?string $v): string => 'R$ ' . number_format((float) str_replace(',', '.', (string) $v), 2, ',', '.');
    $money4 = fn (?string $v): string => number_format((float) str_replace(',', '.', (string) $v), 4, ',', '.');
    $dt = fn (?string $v): string => $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1
        ? \Carbon\Carbon::parse($v)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s')
        : (string) $v;
    $cteNumberPadded = str_pad($dacte->cteNumber, 9, '0', STR_PAD_LEFT);
    $numeroDotted = substr($cteNumberPadded, 0, 3).'.'.substr($cteNumberPadded, 3, 3).'.'.substr($cteNumberPadded, 6, 3);
    $unit = match ($dacte->cargo['cUnid'] ?? null) {
        '01' => 'KG',
        '04' => 'LT',
        default => 'UN',
    };
    $doc = $dacte->document;
    $origin = trim(($dacte->originCity ?? '').' - SP').' - '.($dacte->originIbge ?? '');
    $destination = trim(($dacte->destinationCity ?? '').' - SP').' - '.($dacte->destinationIbge ?? '');
@endphp

{{-- ========================= CANHOTO ========================= --}}
<table>
    <tr>
        <td colspan="3" class="center bold" style="font-size: 8.5px">
            DECLARO QUE RECEBI OS VOLUMES DESTE CONHECIMENTO EM PERFEITO ESTADO PELO QUE DOU POR CUMPRIDO O PRESENTE CONTRATO DE TRANSPORTE
        </td>
    </tr>
    <tr>
        <td style="width: 46%">
            <div class="label">Nome</div>
            <div>&nbsp;</div>
            <div class="label">RG</div>
            <div>&nbsp;</div>
        </td>
        <td style="width: 30%" class="center">
            <div class="label">Chegada &nbsp;&nbsp;&nbsp; Data/Hora</div>
            <div>___/___/___ &nbsp; ___:___</div>
            <div class="label">Saída &nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Data/Hora</div>
            <div>___/___/___ &nbsp; ___:___</div>
        </td>
        <td style="width: 24%">
            <div class="right bold" style="font-size: 12px">CT-E</div>
            <div class="right" style="font-size: 7.5px">N.</div>
            <div class="right big">{{ $numeroDotted }}</div>
            <div class="right" style="font-size: 7.5px">SÉRIE: <span class="bold" style="font-size: 11px">{{ $dacte->serie }}</span></div>
        </td>
    </tr>
    <tr>
        <td colspan="2" class="center label">Assinatura / Carimbo</td>
        <td class="gap"></td>
    </tr>
</table>

{{-- ========================= CABEÇALHO ========================= --}}
<div class="gap" style="height: 3px"></div>

<table>
    <tr>
        <td style="width: 33%">
            <div class="bold" style="font-size: 11px">{{ $dacte->emit['xNome'] }}</div>
            <div>{{ $dacte->emit['xLgr'] }}, {{ $dacte->emit['nro'] }}</div>
            <div>{{ $dacte->emit['xBairro'] }}</div>
            <div>CEP: {{ substr((string) $dacte->emit['CEP'], 0, 5) }}-{{ substr((string) $dacte->emit['CEP'], 5) }} - {{ $dacte->emit['xMun'] }} - {{ $dacte->emit['UF'] }}</div>
            <div class="muted">CNPJ: {{ substr((string) $dacte->emit['CNPJ'], 0, 2) }}.{{ substr((string) $dacte->emit['CNPJ'], 2, 3) }}.{{ substr((string) $dacte->emit['CNPJ'], 5, 3) }}/{{ substr((string) $dacte->emit['CNPJ'], 8, 4) }}-{{ substr((string) $dacte->emit['CNPJ'], 12) }}</div>
            <div class="muted">INSCRIÇÃO ESTADUAL: {{ $dacte->emit['IE'] }}</div>
            <div class="muted">TELEFONE:</div>
        </td>
        <td style="width: 44%">
            <div class="center bold" style="font-size: 12px">DACTE</div>
            <div class="center muted">Documento Auxiliar do Conhecimento de Transporte Eletrônico</div>
            <table style="margin-top: 2px">
                <tr>
                    <td style="width: 16%; border-left: none; border-top: none"><div class="label">Modelo</div><div class="bold center">57</div></td>
                    <td style="width: 14%; border-top: none"><div class="label">Série</div><div class="bold center">{{ $dacte->serie }}</div></td>
                    <td style="width: 24%; border-top: none"><div class="label">Número</div><div class="bold center">{{ $numeroDotted }}</div></td>
                    <td style="width: 14%; border-top: none"><div class="label">Folha</div><div class="bold center">01/01</div></td>
                    <td style="width: 32%; border-right: none; border-top: none"><div class="label">Data e hora de emissão</div><div class="bold center">{{ $dt($dacte->dhEmi) }}</div></td>
                </tr>
            </table>
            <div style="margin-top: 3px" class="label">Chave de acesso</div>
            <div class="bold" style="font-size: 10px; letter-spacing: 0.4px">{{ implode(' ', $dacte->groupedAccessKey()) }}</div>
            <div class="center muted" style="margin-top: 2px">
                Consulta de autenticidade no portal nacional do CT-e, no site da Sefaz
                Autorizadora, ou em http://www.cte.fazenda.gov.br/portal
            </div>
        </td>
        <td style="width: 23%">
            <div class="label">Modal</div>
            <div class="bold center" style="font-size: 11px">RODOVIÁRIO</div>
            <div class="label" style="margin-top: 2px">Insc. Suframa do destinatário</div>
            <div>&nbsp;</div>
            <div class="qr center">
                @if ($qrImage !== null)
                    <img src="{{ $qrImage }}" alt="QR Code">
                @elseif ($dacte->qrUrl !== null)
                    <div class="muted" style="word-break: break-all; font-size: 6.5px">{{ $dacte->qrUrl }}</div>
                @endif
            </div>
        </td>
    </tr>
</table>

@if ($dacte->isHomolog())
    <div class="homolog" style="margin-top: 3px">AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL</div>
@endif

{{-- ========================= TIPOS + PROTOCOLO ========================= --}}
<table style="margin-top: 3px">
    <tr>
        <td style="width: 17%"><div class="label">Tipo do CT-e</div><div class="bold">{{ ($dacte->tpCte === '0' || $dacte->tpCte === '') ? 'NORMAL' : $dacte->tpCte }}</div></td>
        <td style="width: 17%"><div class="label">Tipo do serviço</div><div class="bold">{{ ($dacte->tpServ === '0' || $dacte->tpServ === '') ? 'NORMAL' : $dacte->tpServ }}</div></td>
        <td style="width: 15%"><div class="label">Indicador do CT-e globalizado</div><div class="bold">NÃO</div></td>
        <td style="width: 14%"><div class="label">Informações do CT-e globalizado</div></td>
        <td style="width: 37%">
            <div class="label">Protocolo de autorização de uso</div>
            <div class="bold">{{ $dacte->protocol !== '' ? $dacte->protocol.' — '.$dt($dacte->receivedAt) : '&nbsp;' }}</div>
        </td>
    </tr>
</table>

{{-- ========================= CFOP + PRESTAÇÃO ========================= --}}
<table style="margin-top: 3px">
    <tr>
        <td colspan="2"><div class="label">CFOP — Natureza da operação</div><div class="bold">5353 — {{ $dacte->natOp }}</div></td>
    </tr>
    <tr>
        <td style="width: 50%"><div class="label">Origem da prestação</div><div class="bold">{{ $origin }}</div></td>
        <td style="width: 50%"><div class="label">Destino da prestação</div><div class="bold">{{ $destination }}</div></td>
    </tr>
</table>

{{-- ========================= REMETENTE / DESTINATÁRIO ========================= --}}
<table style="margin-top: 3px">
    <tr>
        <td style="width: 50%">
            <div class="label">Remetente</div>
            <div class="bold">{{ $dacte->rem['xNome'] }}</div>
            <div class="label" style="margin-top: 2px">Endereço</div>
            <div>{{ $dacte->rem['xLgr'] }}, {{ $dacte->rem['nro'] }} — {{ $dacte->rem['xBairro'] }}</div>
            <table style="margin-top: 2px">
                <tr>
                    <td style="width: 40%; border: none; padding-left: 0"><div class="label">Município</div><div>{{ $dacte->rem['xMun'] }}</div></td>
                    <td style="width: 33%; border: none"><div class="label">CNPJ/CPF</div><div>{{ $dacte->rem['CNPJ'] }}</div></td>
                    <td style="width: 27%; border: none; padding-right: 0"><div class="label">CEP</div><div>{{ $dacte->rem['CEP'] }}</div></td>
                </tr>
                <tr>
                    <td style="width: 40%; border: none; padding-left: 0"><div class="label">Inscrição estadual</div><div>{{ $dacte->rem['IE'] }}</div></td>
                    <td style="width: 33%; border: none"><div class="label">País</div><div>Brasil</div></td>
                    <td style="width: 27%; border: none; padding-right: 0"><div class="label">Fone</div><div>&nbsp;</div></td>
                </tr>
            </table>
        </td>
        <td style="width: 50%">
            <div class="label">Destinatário</div>
            <div class="bold">{{ $dacte->dest['xNome'] }}</div>
            <div class="label" style="margin-top: 2px">Endereço</div>
            <div>{{ $dacte->dest['xLgr'] }}, {{ $dacte->dest['nro'] }} — {{ $dacte->dest['xBairro'] }}</div>
            <table style="margin-top: 2px">
                <tr>
                    <td style="width: 40%; border: none; padding-left: 0"><div class="label">Município</div><div>{{ $dacte->dest['xMun'] }}</div></td>
                    <td style="width: 33%; border: none"><div class="label">CNPJ/CPF</div><div>{{ $dacte->dest['CNPJ'] }}</div></td>
                    <td style="width: 27%; border: none; padding-right: 0"><div class="label">CEP</div><div>{{ $dacte->dest['CEP'] }}</div></td>
                </tr>
                <tr>
                    <td style="width: 40%; border: none; padding-left: 0"><div class="label">Inscrição estadual</div><div>{{ $dacte->dest['IE'] }}</div></td>
                    <td style="width: 33%; border: none"><div class="label">País</div><div>Brasil</div></td>
                    <td style="width: 27%; border: none; padding-right: 0"><div class="label">Fone</div><div>&nbsp;</div></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">Expedidor</div>
            <div>&nbsp;</div>
        </td>
        <td>
            <div class="label">Recebedor</div>
            <div>&nbsp;</div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="label">Tomador do serviço</div>
            <div class="bold">{{ $dacte->dest['xNome'] }}</div>
            <div>{{ $dacte->dest['xLgr'] }}, {{ $dacte->dest['nro'] }} — {{ $dacte->dest['xBairro'] }}</div>
            <span class="muted">Município {{ $dacte->dest['xMun'] }} - {{ $dacte->dest['UF'] }} &nbsp;&nbsp; País Brasil &nbsp;&nbsp; CNPJ/CPF {{ $dacte->dest['CNPJ'] }} &nbsp;&nbsp; IE {{ $dacte->dest['IE'] }} &nbsp;&nbsp; CEP {{ $dacte->dest['CEP'] }}</span>
        </td>
    </tr>
</table>

{{-- ========================= CARGA ========================= --}}
<table style="margin-top: 3px">
    <tr>
        <td colspan="2"><div class="label">Produto predominante</div><div class="bold">{{ $dacte->cargo['proPred'] ?? ' ' }}</div></td>
        <td colspan="2"><div class="label">Outras características da carga</div><div class="bold">{{ $dacte->cargo['xOutCat'] ?? ' ' }}</div></td>
        <td><div class="label">Valor total da mercadoria</div><div class="right big">{{ isset($dacte->cargo['vCarga']) ? $money($dacte->cargo['vCarga']) : ' ' }}</div></td>
    </tr>
    <tr>
        <td><div class="label">Peso bruto (Kg)</div><div class="bold">{{ (($dacte->cargo['cUnid'] ?? '') === '01') ? $money4($dacte->cargo['qCarga'] ?? null) : ' ' }}</div></td>
        <td><div class="label">Peso base cálc. (Kg)</div><div>&nbsp;</div></td>
        <td><div class="label">Peso aferido (Kg)</div><div>&nbsp;</div></td>
        <td><div class="label">Cubagem (M3)</div><div>&nbsp;</div></td>
        <td><div class="label">Qtde. volumes (Unid)</div><div class="right bold">{{ ($dacte->cargo['tpMed'] ?? '') !== '' ? ($dacte->cargo['tpMed'].': '.$money4($dacte->cargo['qCarga'] ?? null).' '.$unit) : ' ' }}</div></td>
    </tr>
</table>

{{-- ========================= PRESTAÇÃO DO SERVIÇO ========================= --}}
<table style="margin-top: 3px">
    <tr><td colspan="4" class="center label" style="font-size: 8.5px">Componentes do valor da prestação de serviço</td></tr>
    <tr>
        @for ($i = 0; $i < 3; $i++)
            <td style="width: 18%">
                <div class="label">Nome</div>
                <div>{{ $dacte->components[$i]['xNome'] ?? ' ' }}</div>
                <div class="label">Valor</div>
                <div class="bold">{{ isset($dacte->components[$i]['vComp']) ? $money($dacte->components[$i]['vComp']) : ' ' }}</div>
            </td>
        @endfor
        <td style="width: 46%">
            <div class="label">Valor total do serviço</div>
            <div class="right big">{{ $money($dacte->vTPrest) }}</div>
            <div class="label" style="margin-top: 2px">Valor a receber</div>
            <div class="right big">{{ $money($dacte->vRec) }}</div>
        </td>
    </tr>
</table>

{{-- ========================= IMPOSTO ========================= --}}
<table style="margin-top: 3px">
    <tr>
        <td colspan="2" class="center label" style="font-size: 8.5px">Informações relativas ao imposto</td>
    </tr>
    <tr>
        <td style="width: 45%"><div class="label">Situação tributária</div><div class="bold">{{ $dacte->cst !== '' ? $dacte->cst.' - SIMPLES NACIONAL' : ' ' }}</div></td>
        <td>
            <table>
                <tr>
                    <td style="width: 30%; border: none; padding-left: 0"><div class="label">Base de cálculo</div><div>&nbsp;</div></td>
                    <td style="width: 20%; border: none"><div class="label">Alíq. ICMS</div><div>&nbsp;</div></td>
                    <td style="width: 28%; border: none"><div class="label">Valor ICMS</div><div>&nbsp;</div></td>
                    <td style="width: 22%; border: none; padding-right: 0"><div class="label">% Red.BC.Calc.</div><div>&nbsp;</div></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ========================= DOCUMENTOS ORIGINÁRIOS ========================= --}}
<table style="margin-top: 3px">
    <tr><td colspan="2" class="center label" style="font-size: 8.5px">Documentos originários</td></tr>
    <tr>
        <td style="width: 50%">
            @if (($doc['kind'] ?? null) === 'nfe')
                <span class="bold">NF-E {{ substr((string) $doc['chave'], 25, 9) }}</span>
                <div class="bold" style="letter-spacing: 0.3px">{{ implode(' ', str_split((string) $doc['chave'], 4)) }}</div>
            @elseif (($doc['kind'] ?? null) === 'outros')
                <span class="bold">{{ $doc['descOutros'] }} (TP DOC. {{ $doc['tpDoc'] }})</span>
                <div class="muted">Emissão: {{ $doc['dEmi'] }} — nDoc {{ $doc['nDoc'] }}</div>
            @else
                &nbsp;
            @endif
        </td>
        <td style="width: 50%">
            @if (($doc['kind'] ?? null) === 'nfe')
                <div class="label">Data prevista de entrega</div>
                <div>{{ $doc['dPrev'] }}</div>
            @else
                &nbsp;
            @endif
        </td>
    </tr>
</table>

{{-- ========================= OBSERVAÇÕES + MODAL ========================= --}}
<table style="margin-top: 3px">
    <tr><td colspan="2" class="center label" style="font-size: 8.5px">Observações</td></tr>
    <tr><td colspan="2"><div>&nbsp;</div></td></tr>
    <tr><td colspan="2" class="center label" style="font-size: 8.5px">Dados específicos do modal rodoviário</td></tr>
    <tr>
        <td style="width: 30%"><div class="label">RNTRC da empresa</div><div class="bold">{{ $dacte->rntrc ?? ' ' }}</div></td>
        <td style="width: 70%">
            <div class="label">Data prevista de entrega</div>
            <div>{{ (($doc['kind'] ?? null) === 'nfe' && filled($doc['dPrev'])) ? $dt($doc['dPrev']) : 'ESTE CONHECIMENTO DE TRANSPORTE ATENDE À LEGISLAÇÃO DE TRANSPORTE RODOVIÁRIO EM VIGOR' }}</div>
        </td>
    </tr>
</table>

{{-- ========================= FECHO ========================= --}}
<table style="margin-top: 3px">
    <tr>
        <td style="width: 55%"><div class="label">Uso exclusivo do emissor do CT-e</div><div>&nbsp;</div></td>
        <td style="width: 45%"><div class="label">Reservado ao fisco</div><div class="muted">{{ $dacte->cStat !== '' ? $dacte->cStat.' — '.($dacte->xMotivo ?? '') : ' ' }}</div></td>
    </tr>
</table>

<div class="right muted" style="margin-top: 2px">Emitido por {{ config('dfe.cte.verProc') }} — leiaute MOC CT-e 4.00 Anexo II</div>

</body>
</html>
