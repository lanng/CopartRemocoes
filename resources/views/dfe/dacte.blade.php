{{-- DACTE — leiaute essencial do MOC CT-e 4.00, Anexo II (decisão de implementação, spec dfe §5).
    Dompdf: CSS simples, tabelas com bordas, DejaVu Sans (acentuação OK). --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #000; }
        .page { width: 100%; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        td, th { border: 1px solid #000; padding: 3px 4px; vertical-align: top; }
        .noborder td { border: none; }
        .label { font-size: 7.5px; color: #333; text-transform: uppercase; }
        .big { font-size: 13px; font-weight: bold; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: bold; }
        .muted { font-size: 8px; color: #444; }
        .row { display: table; width: 100%; table-layout: fixed; border-spacing: 0; }
        .row > .cell { display: table-cell; vertical-align: top; }
        .homolog { background: #000; color: #fff; font-weight: bold; font-size: 11px; text-align: center; padding: 4px; border: 1px solid #000; }
        .fields span { display: block; line-height: 1.45; }
    </style>
</head>
<body>
<div class="page">

    @php
        $formatMoney = fn (?string $v): string => 'R$ ' . number_format((float) str_replace(',', '.', (string) $v), 2, ',', '.');
        $formatDate = fn (?string $v): ?string => $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $v) === 1
            ? \Carbon\Carbon::parse($v)->timezone('America/Sao_Paulo')->format('d/m/Y H:i')
            : $v;
        $unit = match ($dacte->cargo['cUnid'] ?? null) {
            '01' => 'KG',
            '04' => 'LITROS',
            default => 'UN',
        };
    @endphp

    {{-- Canhoto/cabeçalho --}}
    <table>
        <tr>
            <td style="width: 30%">
                <div class="label">Modal</div>
                <div class="bold">RODOVIÁRIO (01)</div>
                <div class="label" style="margin-top: 4px">Modelo / Série / Número / Folha</div>
                <div class="bold">57 / {{ $dacte->serie }} / {{ $dacte->cteNumber }} / 1</div>
            </td>
            <td style="width: 40%">
                <div class="bold" style="font-size: 11px">{{ $dacte->emit['xNome'] }}</div>
                <div>{{ $dacte->emit['xFant'] !== '' ? $dacte->emit['xFant'] : $dacte->emit['xNome'] }}</div>
                <div class="muted">
                    CNPJ {{ $dacte->emit['CNPJ'] }} — IE {{ $dacte->emit['IE'] }}<br>
                    {{ $dacte->emit['xLgr'] }}, {{ $dacte->emit['nro'] }} — {{ $dacte->emit['xBairro'] }}<br>
                    {{ $dacte->emit['xMun'] }}/{{ $dacte->emit['UF'] }} — CEP {{ $dacte->emit['CEP'] }}
                </div>
            </td>
            <td style="width: 30%">
                <div class="bold center">DACTE</div>
                <div class="center muted">Documento Auxiliar do Conhecimento<br>de Transporte Eletrônico</div>
                <div class="center" style="margin-top: 3px">CHAVE DE ACESSO</div>
                <div class="center bold" style="font-size: 10px; letter-spacing: 0.5px">{{ implode(' ', $dacte->groupedAccessKey()) }}</div>
                <div class="label center" style="margin-top: 3px">Consulta de autenticidade no portal nacional do CT-e</div>
            </td>
        </tr>
    </table>

    @if ($dacte->isHomolog())
        <div class="homolog" style="margin-top: 4px">AMBIENTE DE HOMOLOGAÇÃO — SEM VALOR FISCAL</div>
    @endif

    {{-- Protocolo de autorização --}}
    <table style="margin-top: 4px">
        <tr>
            <td style="width: 50%">
                <span class="label">Protocolo de autorização</span>
                <div class="bold">{{ $dacte->protocol }} — {{ $formatDate($dacte->receivedAt) }}</div>
            </td>
            <td style="width: 50%">
                <span class="label">Data e hora de emissão</span>
                <div class="bold">{{ $formatDate($dacte->dhEmi) }}</div>
            </td>
        </tr>
    </table>

    {{--CFOP / natureza + municípios--}}
    <table style="margin-top: 4px">
        <tr>
            <td style="width: 15%"><span class="label">CFOP</span><div class="bold">5353</div></td>
            <td style="width: 85%"><span class="label">Natureza da operação</span><div>{{ $dacte->natOp }}</div></td>
        </tr>
        <tr>
            <td style="width: 15%"><span class="label">Início da prestação</span><div>{{ $dacte->originCity }}</div></td>
            <td style="width: 85%"><span class="label">Término da prestação</span><div>{{ $dacte->destinationCity }}</div></td>
        </tr>
    </table>

    {{-- Partes --}}
    <table style="margin-top: 4px">
        <tr>
            <td style="width: 50%">
                <span class="label">Remetente</span>
                <div class="fields">
                    <span class="bold">{{ $dacte->rem['xNome'] }}</span>
                    <span>CNPJ {{ $dacte->rem['CNPJ'] }} — IE {{ $dacte->rem['IE'] }}</span>
                    <span>{{ $dacte->rem['xLgr'] }}, {{ $dacte->rem['nro'] }} — {{ $dacte->rem['xBairro'] }}</span>
                    <span>{{ $dacte->rem['xMun'] }}/{{ $dacte->rem['UF'] }} — CEP {{ $dacte->rem['CEP'] }}</span>
                </div>
            </td>
            <td style="width: 50%">
                <span class="label">Destinatário</span>
                <div class="fields">
                    <span class="bold">{{ $dacte->dest['xNome'] }}</span>
                    <span>CNPJ {{ $dacte->dest['CNPJ'] }} — IE {{ $dacte->dest['IE'] }}</span>
                    <span>{{ $dacte->dest['xLgr'] }}, {{ $dacte->dest['nro'] }} — {{ $dacte->dest['xBairro'] }}</span>
                    <span>{{ $dacte->dest['xMun'] }}/{{ $dacte->dest['UF'] }} — CEP {{ $dacte->dest['CEP'] }}</span>
                </div>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <span class="label">Tomador do serviço</span>
                <div class="bold">{{ $dacte->dest['xNome'] }} (toma 3 — destinatário)</div>
            </td>
        </tr>
    </table>

    {{-- Prestação do serviço --}}
    <table style="margin-top: 4px">
        <tr>
            <td style="width: 15%"><span class="label">Valor total do serviço</span><div class="big">{{ $formatMoney($dacte->vTPrest) }}</div></td>
            <td style="width: 15%"><span class="label">Valor a receber</span><div class="big">{{ $formatMoney($dacte->vRec) }}</div></td>
            <td style="width: 70%">
                <span class="label">Componentes do valor da prestação</span>
                <table style="border-collapse: collapse">
                    <tr class="noborder">
                        @foreach ($dacte->components as $component)
                            <td style="border: none; border-bottom: 1px dotted #666">
                                <span class="label">{{ $component['xNome'] }}</span>
                                <div class="bold">{{ $formatMoney($component['vComp']) }}</div>
                            </td>
                        @endforeach
                        @if (count($dacte->components) === 0)
                            <td style="border: none" class="muted">—</td>
                        @endif
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    {{-- Carga + documentos originários --}}
    <table style="margin-top: 4px">
        <tr>
            <td style="width: 50%">
                <span class="label">Informações da carga</span>
                <div class="fields">
                    <span>Quantidade: <span class="bold">{{ $dacte->cargo['qCarga'] ?? '—' }} {{ $unit }}</span></span>
                    <span>Valor da carga: <span class="bold">{{ isset($dacte->cargo['vCarga']) ? $formatMoney($dacte->cargo['vCarga']) : '—' }}</span></span>
                    <span>Produto predominante: <span class="bold">{{ $dacte->cargo['proPred'] ?? '—' }}</span></span>
                    @if (filled($dacte->cargo['xOutCat'] ?? null))
                        <span>Outras características: <span class="bold">{{ $dacte->cargo['xOutCat'] }}</span></span>
                    @endif
                </div>
            </td>
            <td style="width: 50%">
                <span class="label">Documentos originários</span>
                @if (($dacte->document['kind'] ?? null) === 'nfe')
                    <div class="fields">
                        <span>NF-e: <span class="bold">{{ $dacte->document['chave'] }}</span></span>
                        <span>Previsão de entrega: {{ $dacte->document['dPrev'] }}</span>
                    </div>
                @elseif (($dacte->document['kind'] ?? null) === 'outros')
                    <div class="fields">
                        <span>{{ $dacte->document['descOutros'] }} (tpDoc {{ $dacte->document['tpDoc'] }})</span>
                        <span>Emissão: {{ $dacte->document['dEmi'] }} — nDoc {{ $dacte->document['nDoc'] }}</span>
                    </div>
                @else
                    <div class="muted">—</div>
                @endif
            </td>
        </tr>
    </table>

    {{-- Modal + observações --}}
    <table style="margin-top: 4px">
        <tr>
            <td style="width: 30%"><span class="label">Rodoviário — RNTRC</span><div class="bold">{{ $dacte->rntrc ?? '—' }}</div></td>
            <td style="width: 70%">
                <span class="label">Observações gerais</span>
                <div>Carta de remoção anexada à carga. Sistema próprio de emissão ({{ config('dfe.cte.verProc') }}).</div>
            </td>
        </tr>
    </table>

    {{-- QR + rodapé --}}
    <table style="margin-top: 4px">
        <tr>
            <td style="width: 70%">
                <span class="label">Consulta via QR Code</span>
                <div style="font-size: 7.5px; word-break: break-all">{{ $dacte->qrUrl ?? '—' }}</div>
            </td>
            <td style="width: 30%">
                <span class="label">Reservado ao fisco</span>
                <div class="muted">{{ $dacte->cStat }} — {{ $dacte->xMotivo }}</div>
            </td>
        </tr>
        <tr>
            <td colspan="2" class="center muted">
                DECLARO QUE RECEBI OS VOLUMES DESCRITOS NESTE CONHECIMENTO EM PERFEITO ESTADO —
                DOCUMENTO AUXILIAR DO CONHECIMENTO DE TRANSPORTE ELETRÔNICO — MODELO 57
            </td>
        </tr>
    </table>

</div>
</body>
</html>
