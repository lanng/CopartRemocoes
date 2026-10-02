{{-- DACTE — réplica do leiaute de mercado (ACBr) sobre os grupos do MOC CT-e 4.00 Anexo II,
    conforme referência fornecida pelo dono do projeto (CT-e 2965, projeto ACBr).
    Dompdf: fonte serifada, bordas finas, margens @page. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 0; }        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'times', 'Times-Roman', serif; font-size: 9px; color: #000; line-height: 1.25; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { border: 0.5px solid #000; padding: 1.5px 3px; vertical-align: top; }
        .label { font-size: 6px; color: #333; text-transform: uppercase; }
        .bold { font-weight: bold; }
        .big { font-size: 14px; font-weight: bold; }
        .section { font-size: 8.5px; text-align: center; }
        .center { text-align: center; }
        .right { text-align: right; }
        .muted { font-size: 7.5px; color: #333; }
        .gap td { border: none; padding: 0; }
        .homolog { background: #000; color: #fff; font-weight: bold; font-size: 10px; text-align: center; padding: 3px; border: 0.5px solid #000; }
        .qr img { width: 84px; height: 84px; }
        .sheet { padding: 10mm 5mm 12mm 5mm; }
    </style>
</head>
<body>
<div class="sheet">
@php
    $money = fn (?string $v): string => number_format((float) str_replace(',', '.', (string) $v), 2, ',', '.');
    $brl = fn (?string $v): string => 'R$ ' . number_format((float) str_replace(',', '.', (string) $v), 2, ',', '.');
    $money4 = fn (?string $v): string => number_format((float) str_replace(',', '.', (string) $v), 4, ',', '.');
    $dt = fn (?string $v): string => $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1
        ? \Carbon\Carbon::parse($v)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s')
        : (string) $v;
    $cnpj = fn (string $v): string => strlen($v) === 14
        ? substr($v, 0, 2).'.'.substr($v, 2, 3).'.'.substr($v, 5, 3).'/'.substr($v, 8, 4).'-'.substr($v, 12)
        : $v;
    $cep = fn (string $v): string => strlen($v) === 8 ? substr($v, 0, 5).'-'.substr($v, 5) : $v;
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

{{-- ============================ CANHOTO ============================ --}}
<table>
    <colgroup>
        <col style="width: 24%">
        <col style="width: 36%">
        <col style="width: 22%">
        <col style="width: 18%">
    </colgroup>
    <tr>
        <td colspan="4" class="center bold" style="font-size: 8px">
            DECLARO QUE RECEBI OS VOLUMES DESTE CONHECIMENTO EM PERFEITO ESTADO PELO QUE DOU POR CUMPRIDO O PRESENTE CONTRATO DE TRANSPORTE
        </td>
    </tr>
    <tr>
        <td style="height: 52px">
            <div class="label" style="font-size: 7.5px">Nome</div>
            <div style="height: 14px">&nbsp;</div>
            <div class="label" style="border-top: 0.5px solid #000; font-size: 7.5px">RG</div>
            <div>&nbsp;</div>
        </td>
        <td style="vertical-align: bottom">
            <div class="center label" style="font-size: 7.5px; padding-bottom: 3px">Assinatura / Carimbo</div>
        </td>
        <td class="center">
            <div class="label" style="font-size: 7px">Chegada &nbsp; Data/Hora</div>
            <div style="font-size: 10px">___/___/___ &nbsp; ___:___</div>
            <div class="label" style="font-size: 7px">Saída &nbsp; Data/Hora</div>
            <div style="font-size: 10px">___/___/___ &nbsp; ___:___</div>
        </td>
        <td>
            <div class="center bold" style="font-size: 10px">CT-E</div>
            <div><span class="label">N.</span> <span class="big" style="font-size: 12px">{{ $numeroDotted }}</span></div>
            <div><span class="label" style="font-size: 7.5px">SÉRIE:</span> <span class="bold" style="font-size: 10px">{{ $dacte->serie }}</span></div>
        </td>
    </tr>
</table>

{{-- ============================ CABEÇALHO ============================ --}}
<div class="gap" style="height: 3px"></div>

<table>
    <tr>
        <td style="width: 32%">
            <div class="center bold" style="font-size: 11px; margin-bottom: 7px">{{ $dacte->emit['xNome'] }}</div>
            <div>{{ $dacte->emit['xLgr'] }}, {{ $dacte->emit['nro'] }}</div>
            <div>{{ $dacte->emit['xBairro'] }}</div>
            <div>CEP: {{ $cep((string) $dacte->emit['CEP']) }} - {{ $dacte->emit['xMun'] }} - {{ $dacte->emit['UF'] }}</div>
            <div class="muted">CNPJ: {{ $cnpj((string) $dacte->emit['CNPJ']) }}</div>
            <div class="muted">INSCRIÇÃO ESTADUAL: {{ $dacte->emit['IE'] }}</div>
            <div class="muted">TELEFONE:</div>
        </td>
        <td style="width: 45%; padding: 0">
            <div class="center bold" style="font-size: 12px; padding-top: 2px">DACTE</div>
            <div class="center muted" style="border-bottom: 0.5px solid #000; padding-bottom: 2px">Documento Auxiliar do Conhecimento de Transporte Eletrônico</div>
            <table style="margin-top: 0">
                <tr>
                    <td style="width: 15%"><div class="label">Modelo</div><div class="bold center">57</div></td>
                    <td style="width: 14%"><div class="label">Série</div><div class="center">{{ $dacte->serie }}</div></td>
                    <td style="width: 26%"><div class="label">Número</div><div class="bold center">{{ $numeroDotted }}</div></td>
                    <td style="width: 13%"><div class="label">Folha</div><div class="center">01/01</div></td>
                    <td style="width: 32%"><div class="label">Data e hora de emissão</div><div class="center">{{ $dt($dacte->dhEmi) }}</div></td>
                </tr>
            </table>
            <div style="margin-top: 2px" class="label">Chave de acesso</div>
            <div class="bold" style="font-size: 10.5px; line-height: 1.35">{{ implode(' ', $dacte->groupedAccessKey()) }}</div>
            <div class="center muted" style="margin-top: 2px">
                Consulta de autenticidade no portal nacional do CT-e, no site da Sefaz
                Autorizadora, ou em http://www.cte.fazenda.gov.br/portal
            </div>
        </td>
        <td style="width: 23%">
            <table>
                <tr><td>
                    <div class="label">Modal</div>
                    <div class="center bold" style="font-size: 10px; line-height: 1.4">RODOVIÁRIO</div>
                </td></tr>
                <tr><td style="border-bottom: 1px solid #000">
                    <div class="label">Insc. Suframa do destinatário</div>
                    <div>&nbsp;</div>
                </td></tr>
            </table>
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

{{-- ============================ TIPOS + PROTOCOLO ============================ --}}
<table style="margin-top: 2px">
    <tr>
        <td style="width: 17%"><div class="label">Tipo do CT-e</div><div>{{ ($dacte->tpCte === '0' || $dacte->tpCte === '') ? 'NORMAL' : $dacte->tpCte }}</div></td>
        <td style="width: 17%"><div class="label">Tipo do serviço</div><div>{{ ($dacte->tpServ === '0' || $dacte->tpServ === '') ? 'NORMAL' : $dacte->tpServ }}</div></td>
        <td style="width: 15%"><div class="label">Indicador do CT-e globalizado</div><div>NÃO</div></td>
        <td style="width: 14%"><div class="label">Informações do CT-e globalizado</div></td>
        <td style="width: 37%">
            <div class="label">Protocolo de autorização de uso</div>
            <div class="bold">{!! $dacte->protocol !== '' ? e($dacte->protocol.'  '.$dt($dacte->receivedAt)) : '&nbsp;' !!}</div>
        </td>
    </tr>
</table>

{{-- ============================ CFOP + PRESTAÇÃO ============================ --}}
<table style="margin-top: 2px">
    <tr>
        <td colspan="2"><div class="label">CFOP - Natureza da operação</div><div>5353 - {{ $dacte->natOp }}</div></td>
    </tr>
    <tr>
        <td style="width: 50%"><div class="label">Origem da prestação</div><div>{{ $origin }}</div></td>
        <td style="width: 50%"><div class="label">Destino da prestação</div><div>{{ $destination }}</div></td>
    </tr>
</table>

{{-- ============================ REMETENTE / DESTINATÁRIO ============================ --}}
<table style="margin-top: 2px">
    <tr>
        <td style="width: 50%">
            <div class="label">Remetente</div>
            <div class="bold">{{ $dacte->rem['xNome'] }}</div>
            <div class="label" style="margin-top: 1px">Endereço</div>
            <div>{{ $dacte->rem['xLgr'] }}, {{ $dacte->rem['nro'] }} - {{ $dacte->rem['xBairro'] }}</div>
            <table style="margin-top: 1px">
                <tr>
                    <td style="width: 28%; border: none; padding-left: 0"><div class="label">Município</div></td>
                    <td style="width: 30%; border: none"><div class="label">CNPJ/CPF</div></td>
                    <td style="width: 26%; border: none"><div class="label">Inscrição estadual</div></td>
                    <td style="width: 16%; border: none; padding-right: 0"><div class="label">CEP</div></td>
                </tr>
                <tr>
                    <td style="border: none; padding-left: 0">{{ $dacte->rem['xMun'] }}</td>
                    <td style="border: none">{{ $cnpj((string) $dacte->rem['CNPJ']) }}</td>
                    <td style="border: none">{{ $dacte->rem['IE'] }}</td>
                    <td style="border: none; padding-right: 0">{{ $cep((string) $dacte->rem['CEP']) }}</td>
                </tr>
                <tr>
                    <td style="border: none; padding-left: 0"><span class="label">País</span> Brasil</td>
                    <td style="border: none" colspan="2"><span class="label">Fone</span></td>
                    <td style="border: none; padding-right: 0"></td>
                </tr>
            </table>
        </td>
        <td style="width: 50%">
            <div class="label">Destinatário</div>
            <div class="bold">{{ $dacte->dest['xNome'] }}</div>
            <div class="label" style="margin-top: 1px">Endereço</div>
            <div>{{ $dacte->dest['xLgr'] }}, {{ $dacte->dest['nro'] }} - {{ $dacte->dest['xBairro'] }}</div>
            <table style="margin-top: 1px">
                <tr>
                    <td style="width: 28%; border: none; padding-left: 0"><div class="label">Município</div></td>
                    <td style="width: 30%; border: none"><div class="label">CNPJ/CPF</div></td>
                    <td style="width: 26%; border: none"><div class="label">Inscrição estadual</div></td>
                    <td style="width: 16%; border: none; padding-right: 0"><div class="label">CEP</div></td>
                </tr>
                <tr>
                    <td style="border: none; padding-left: 0">{{ $dacte->dest['xMun'] }}</td>
                    <td style="border: none">{{ $cnpj((string) $dacte->dest['CNPJ']) }}</td>
                    <td style="border: none">{{ $dacte->dest['IE'] }}</td>
                    <td style="border: none; padding-right: 0">{{ $cep((string) $dacte->dest['CEP']) }}</td>
                </tr>
                <tr>
                    <td style="border: none; padding-left: 0"><span class="label">País</span> Brasil</td>
                    <td style="border: none" colspan="2"><span class="label">Fone</span></td>
                    <td style="border: none; padding-right: 0"></td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td>
            <div class="label">Expedidor</div>
            <div class="label">Endereço &nbsp;&nbsp; Município &nbsp;&nbsp; CNPJ/CPF &nbsp;&nbsp; Inscrição estadual &nbsp;&nbsp; CEP &nbsp;&nbsp; País &nbsp;&nbsp; Fone</div>
            <div>&nbsp;</div>
        </td>
        <td>
            <div class="label">Recebedor</div>
            <div class="label">Endereço &nbsp;&nbsp; Município &nbsp;&nbsp; CNPJ/CPF &nbsp;&nbsp; Inscrição estadual &nbsp;&nbsp; CEP &nbsp;&nbsp; País &nbsp;&nbsp; Fone</div>
            <div>&nbsp;</div>
        </td>
    </tr>
    <tr>
        <td colspan="2">
            <div class="label">Tomador do serviço</div>
            <div class="bold">{{ $dacte->dest['xNome'] }}</div>
            <div>{{ $dacte->dest['xLgr'] }}, {{ $dacte->dest['nro'] }} - {{ $dacte->dest['xBairro'] }}</div>
            <span class="muted">Município {{ $dacte->dest['xMun'] }} - {{ $dacte->dest['UF'] }} &nbsp;&nbsp; País Brasil &nbsp;&nbsp; CNPJ/CPF {{ $cnpj((string) $dacte->dest['CNPJ']) }} &nbsp;&nbsp; Inscrição estadual {{ $dacte->dest['IE'] }} &nbsp;&nbsp; CEP {{ $cep((string) $dacte->dest['CEP']) }}</span>
        </td>
    </tr>
</table>

{{-- ============================ CARGA ============================ --}}
<table style="margin-top: 2px">
    <tr>
        <td colspan="2"><div class="label">Produto predominante</div><div>{{ $dacte->cargo['proPred'] ?? ' ' }}</div></td>
        <td colspan="2"><div class="label">Outras características da carga</div><div>{{ $dacte->cargo['xOutCat'] ?? ' ' }}</div></td>
        <td><div class="label">Valor total da mercadoria</div><div class="right value-big">{{ isset($dacte->cargo['vCarga']) ? $money($dacte->cargo['vCarga']) : ' ' }}</div></td>
    </tr>
    <tr>
        <td><div class="label">Peso bruto (Kg)</div><div>{{ (($dacte->cargo['cUnid'] ?? '') === '01') ? $money4($dacte->cargo['qCarga'] ?? null) : ' ' }}</div></td>
        <td><div class="label">Peso base cálc. (Kg)</div><div>&nbsp;</div></td>
        <td><div class="label">Peso aferido (Kg)</div><div>&nbsp;</div></td>
        <td><div class="label">Cubagem (M3)</div><div>&nbsp;</div></td>
        <td><div class="label">Qtde. volumes (Unid)</div><div class="right">{{ ($dacte->cargo['tpMed'] ?? '') !== '' ? ($dacte->cargo['tpMed'].': '.$money4($dacte->cargo['qCarga'] ?? null).' '.$unit) : ' ' }}</div></td>
    </tr>
</table>

{{-- ============================ PRESTAÇÃO DO SERVIÇO ============================ --}}
<table style="margin-top: 2px">
    <tr><td colspan="4" class="section">COMPONENTES DO VALOR DA PRESTAÇÃO DE SERVIÇO</td></tr>
    <tr>
        @for ($i = 0; $i < 3; $i++)
            <td style="width: 18%">
                <div class="label">Nome</div>
                <div>{!! $dacte->components[$i]['xNome'] ?? '&nbsp;' !!}</div>
                <div class="label">Valor</div>
                <div class="right">{!! isset($dacte->components[$i]['vComp']) ? $money($dacte->components[$i]['vComp']) : '&nbsp;' !!}</div>
            </td>
        @endfor
        <td style="width: 46%">
            <div class="label">Valor total do serviço</div>
            <div class="right" style="font-size: 10px">{{ $money($dacte->vTPrest) }}</div>
            <div class="label" style="margin-top: 2px">Valor a receber</div>
            <div class="right" style="font-size: 10px">{{ $money($dacte->vRec) }}</div>
        </td>
    </tr>
</table>

{{-- ============================ IMPOSTO ============================ --}}
<table style="margin-top: 2px">
    <tr><td colspan="2" class="section">INFORMAÇÕES RELATIVAS AO IMPOSTO</td></tr>
    <tr>
        <td style="width: 45%"><div class="label">Situação tributária</div><div class="bold">{{ $dacte->cst !== '' ? $dacte->cst.' - SIMPLES NACIONAL' : ' ' }}</div></td>
        <td>
            <table>
                <tr>
                    <td style="width: 30%"><div class="label">Base de cálculo</div><div>&nbsp;</div></td>
                    <td style="width: 20%"><div class="label">Alíq. ICMS</div><div>&nbsp;</div></td>
                    <td style="width: 28%"><div class="label">Valor ICMS</div><div>&nbsp;</div></td>
                    <td style="width: 22%"><div class="label">% Red.BC.Calc.</div><div>&nbsp;</div></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

{{-- ============================ DOCUMENTOS ORIGINÁRIOS ============================ --}}
<table style="margin-top: 2px">
    <tr><td colspan="2" class="section">DOCUMENTOS ORIGINÁRIOS</td></tr>
    <tr>
        <td style="width: 50%">
            <table>
                <tr>
                    <td style="width: 22%; border: none; padding-left: 0"><div class="label">Tp Doc.</div></td>
                    <td style="border: none"><div class="label">Chave do DF-e</div></td>
                </tr>
                <tr>
                    <td style="border: none; padding-left: 0">
                        @if (($doc['kind'] ?? null) === 'nfe')
                            <span class="bold">NF-E</span>
                        @elseif (($doc['kind'] ?? null) === 'outros')
                            <span class="bold">{{ $doc['tpDoc'] }}</span>
                        @endif
                    </td>
                    <td style="border: none">
                        @if (($doc['kind'] ?? null) === 'nfe')
                            <span class="bold">{{ substr((string) $doc['chave'], 25, 9) }}</span>
                            <div style="line-height: 1.4">{{ implode(' ', str_split((string) $doc['chave'], 4)) }}</div>
                        @elseif (($doc['kind'] ?? null) === 'outros')
                            <span>{{ $doc['descOutros'] }}</span>
                            <div class="muted">Emissão: {{ $doc['dEmi'] }} — nDoc {{ $doc['nDoc'] }}</div>
                        @else
                            &nbsp;
                        @endif
                    </td>
                </tr>
            </table>
        </td>
        <td style="width: 50%">
            <table>
                <tr>
                    <td style="width: 22%; border: none; padding-left: 0"><div class="label">Tp Doc.</div></td>
                    <td style="border: none"><div class="label">Chave do DF-e</div></td>
                </tr>
                <tr><td style="border: none; padding-left: 0">&nbsp;</td><td style="border: none">&nbsp;</td></tr>
            </table>
        </td>
    </tr>
</table>

{{-- ============================ OBSERVAÇÕES ============================ --}}
<table style="margin-top: 2px">
    <tr><td class="center bold" style="font-size: 9px">OBSERVAÇÕES</td></tr>
    <tr><td style="height: 26px">&nbsp;</td></tr>
</table>

{{-- ============================ DADOS ESPECÍFICOS DO MODAL ============================ --}}
<table style="margin-top: 2px">
    <colgroup>
        <col style="width: 26%">
        <col style="width: 22%">
        <col style="width: 52%">
    </colgroup>
    <tr><td colspan="3" class="section">DADOS ESPECÍFICOS DO MODAL RODOVIÁRIO</td></tr>
    <tr>
        <td><div class="label">RNTRC da empresa</div><div>{{ $dacte->rntrc ?? ' ' }}</div></td>
        <td><div class="label">Data prevista de entrega</div><div>&nbsp;</div></td>
        <td><div>ESTE CONHECIMENTO DE TRANSPORTE ATENDE À LEGISLAÇÃO DE TRANSPORTE RODOVIÁRIO EM VIGOR</div></td>
    </tr>
</table>

{{-- ============================ FECHO ============================ --}}
<table style="margin-top: 2px">
    <tr>
        <td style="width: 55%; height: 26px"><div class="label">Uso exclusivo do emissor do CT-e</div><div>&nbsp;</div></td>
        <td style="width: 45%"><div class="label">Reservado ao fisco</div><div>&nbsp;</div></td>
    </tr>
</table>

<div class="right muted" style="margin-top: 2px">Emitido por {{ config('dfe.cte.verProc') }}</div>

</div>
</body>
</html>
