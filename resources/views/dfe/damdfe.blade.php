<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 18pt 20pt; }
        * { box-sizing: border-box; }
        body { color: #111; font-family: 'DejaVu Sans', sans-serif; font-size: 7pt; line-height: 1.15; }
        .issuer { height: 82pt; padding: 5pt 82pt 0 35pt; text-align: center; }
        .issuer-name { font-size: 9pt; font-weight: bold; margin-bottom: 7pt; }
        .issuer-details { line-height: 10pt; }
        .qr { height: 78pt; position: absolute; right: 2pt; top: 0; width: 78pt; }
        .title { font-size: 9pt; font-weight: bold; margin: 8pt 0 4pt; }
        .title span { font-size: 8pt; font-weight: normal; margin-left: 8pt; }
        table { border-collapse: collapse; width: 100%; }
        .metadata { background: #91bce3; margin-bottom: 3pt; }
        .metadata td { border-right: 2pt solid #fff; height: 29pt; padding: 3pt; text-align: center; }
        .label { display: block; font-size: 6pt; margin-bottom: 3pt; }
        .value { font-size: 8pt; font-weight: bold; }
        .modal-grid { width: 100%; }
        .modal-grid td { padding: 1pt 4pt 3pt; text-align: center; }
        .summary { margin: 2pt 0 4pt; width: 47%; }
        .summary th, .summary td { background: #e5e5e5; padding: 4pt; text-align: left; }
        .summary th { font-size: 6pt; font-weight: normal; }
        .summary td { font-size: 8pt; font-weight: bold; }
        .protocol-row { height: 118pt; position: relative; }
        .protocol { left: 0; position: absolute; top: 52pt; width: 43%; }
        .fiscal-control { position: absolute; right: 0; text-align: center; top: 0; width: 55%; }
        .barcode { height: 30mm; margin-top: 2pt; width: 90mm; }
        .access-key { font-size: 8pt; font-weight: bold; margin-top: 2pt; }
        .consult { font-size: 6pt; margin-top: 3pt; overflow-wrap: anywhere; }
        .two-columns { margin-top: 4pt; }
        .two-columns > tbody > tr > td { vertical-align: top; width: 50%; }
        .panel-title { font-size: 8pt; font-weight: bold; padding: 3pt 4pt; }
        .panel-table th { border-bottom: .8pt solid #888; font-size: 6pt; font-weight: normal; padding: 3pt 4pt; text-align: left; }
        .panel-table td { font-size: 7pt; padding: 5pt 4pt; }
        .panel-table .key { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.5pt; }
        .vehicle-table td { padding-bottom: 4pt; }
        .insurance-table { margin-top: 32pt; }
        .documents { margin-top: 45pt; }
        .documents .panel-table td { padding: 4pt; }
        .observations { bottom: 43pt; left: 0; position: absolute; right: 0; }
        .observations-title { border-bottom: .8pt solid #888; font-size: 8pt; font-weight: bold; padding: 2pt 4pt; }
        .observations-content { min-height: 18pt; padding: 5pt 4pt; white-space: pre-wrap; }
        .homolog { border: 1pt solid #111; font-size: 8pt; font-weight: bold; margin: 2pt 0; padding: 3pt; text-align: center; }
        .preview-warning { border: 1pt solid #9f1239; color: #9f1239; font-size: 8pt; font-weight: bold; margin: 3pt 0; padding: 4pt; text-align: center; }
    </style>
</head>
<body>
@php
    $date = fn (?string $value): string => filled($value) ? \Carbon\Carbon::parse($value)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s') : '';
    $number = implode('.', str_split(str_pad((string) $damdfe['number'], 9, '0', STR_PAD_LEFT), 3));
    $series = str_pad((string) $damdfe['series'], 3, '0', STR_PAD_LEFT);
    $quantityUnit = match ($damdfe['cargo']['unit']) { '01' => 'Kg', '04' => 'm³', default => $damdfe['cargo']['unit'] };
    $cnpj = fn (?string $value): string => strlen((string) $value) === 14 ? substr($value, 0, 2).'.'.substr($value, 2, 3).'.'.substr($value, 5, 3).'/'.substr($value, 8, 4).'-'.substr($value, 12) : (string) $value;
    $cpf = fn (?string $value): string => strlen((string) $value) === 11 ? substr($value, 0, 3).'.'.substr($value, 3, 3).'.'.substr($value, 6, 3).'-'.substr($value, 9, 2) : (string) $value;
    $cep = fn (?string $value): string => strlen((string) $value) === 8 ? substr($value, 0, 5).'-'.substr($value, 5) : (string) $value;
    $plate = (string) $damdfe['vehicle']['plate'];
    $formattedPlate = strlen($plate) === 7 ? substr($plate, 0, 3).'-'.substr($plate, 3) : $plate;
    $documents = collect($damdfe['cte_access_keys'])->map(fn (string $key): array => ['type' => 'CT-e', 'key' => $key])
        ->merge(collect($damdfe['nfe_access_keys'])->map(fn (string $key): array => ['type' => 'NF-e', 'key' => $key]))
        ->values()
        ->chunk(2)
        ->map(fn ($row): \Illuminate\Support\Collection => $row->values())
        ->values();
    $qrConsultation = filled($damdfe['qr_url']) ? parse_url($damdfe['qr_url'], PHP_URL_HOST).parse_url($damdfe['qr_url'], PHP_URL_PATH) : null;
    $responsible = match ($damdfe['insurance']['responsible']) { '1' => 'EMITENTE', '2' => 'CONTRATANTE', default => $damdfe['insurance']['responsible'] };
@endphp

<div class="issuer">
    <div class="issuer-name">{{ $damdfe['emitter']['name'] }}</div>
    <div class="issuer-details">
        {{ $damdfe['emitter']['street'] }}, {{ $damdfe['emitter']['district'] }}, {{ $damdfe['emitter']['number'] }}<br>
        {{ $damdfe['emitter']['city'] }} - {{ $damdfe['emitter']['uf'] }} CEP: {{ $cep($damdfe['emitter']['cep']) }}<br>
        CNPJ: {{ $cnpj($damdfe['emitter']['cnpj']) }} IE: {{ $damdfe['emitter']['ie'] }}
    </div>
    @if ($qrImage !== null)
        <img class="qr" src="{{ $qrImage }}" alt="QR Code do MDF-e">
    @endif
</div>

@if ($visualPreview)
    <div class="preview-warning">PRÉVIA DE LAYOUT — DADOS SINTÉTICOS — NÃO É MDF-e AUTORIZADO</div>
@endif

@if ($damdfe['homologation'])
    <div class="homolog">EMITIDO EM AMBIENTE DE HOMOLOGAÇÃO – SEM VALOR FISCAL</div>
@endif

<div class="title">DAMDFE<span>Documento Auxiliar de Manifesto Eletrônico de Documentos Fiscais</span></div>
<table class="metadata">
    <tr>
        <td style="width: 10%"><span class="label">MODELO</span><span class="value">58</span></td>
        <td style="width: 10%"><span class="label">SÉRIE</span><span class="value">{{ $series }}</span></td>
        <td style="width: 15%"><span class="label">NÚMERO</span><span class="value">{{ $number }}</span></td>
        <td style="width: 11%"><span class="label">FOLHA</span><span class="value">01/01</span></td>
        <td style="width: 28%"><span class="label">DATA E HORA DE EMISSÃO</span><span class="value">{{ $date($damdfe['issue_at']) }}</span></td>
        <td style="width: 13%"><span class="label">UF Carrega</span><span class="value">{{ $damdfe['origin_uf'] }}</span></td>
        <td style="width: 13%"><span class="label">UF Descar.</span><span class="value">{{ $damdfe['destination_uf'] }}</span></td>
    </tr>
</table>

<table class="modal-grid">
    <tr><td><span class="value">MODAL RODOVIÁRIO DE CARGA</span></td><td><span class="value">Controle do Fisco</span></td></tr>
</table>
<div class="protocol-row">
    <table class="summary">
        <tr><th>QTDE CT-e</th><th>QTDE NF-e</th><th>PESO TOTAL ({{ $quantityUnit }})</th></tr>
        <tr><td>{{ $damdfe['cargo']['cte_count'] }}</td><td>{{ $damdfe['cargo']['nfe_count'] }}</td><td>{{ number_format((float) $damdfe['cargo']['quantity'], 4, ',', '.') }}</td></tr>
    </table>
    <div class="protocol">
        <span class="value">Protocolo de autorização de uso</span><br>
        <span class="value">{{ $damdfe['protocol'] }} &nbsp; {{ $date($damdfe['authorized_at']) }}</span>
    </div>
    <div class="fiscal-control">
        <img class="barcode" src="{{ $barcodeSvg }}" alt="Código de barras da chave do MDF-e">
        <div class="value">Chave de acesso</div>
        <div class="access-key">{{ $damdfe['grouped_access_key'] }}</div>
        @if ($qrConsultation !== null)
            <div class="consult">Consulte em: {{ $qrConsultation }}</div>
        @endif
    </div>
</div>

<table class="two-columns vehicle-table">
    <tr>
        <td>
            <div class="panel-title">Veículo</div>
            <table class="panel-table"><tr><th>Placa</th><th>RNTRC</th></tr><tr><td>{{ $formattedPlate }} - {{ $damdfe['vehicle']['uf'] }}</td><td>{{ $damdfe['rntrc'] }}</td></tr></table>
        </td>
        <td>
            <div class="panel-title">Condutor</div>
            <table class="panel-table"><tr><th>CPF</th><th>Nome</th></tr>
                @foreach ($damdfe['vehicle']['drivers'] as $driver)
                    <tr><td>{{ $cpf($driver['cpf']) }}</td><td>{{ $driver['name'] }}</td></tr>
                @endforeach
            </table>
        </td>
    </tr>
</table>

<table class="two-columns insurance-table">
    <tr>
        <td>
            <div class="panel-title">Vale Pedágio</div>
            <table class="panel-table"><tr><th>Responsável CNPJ</th><th>Fornecedor CNPJ</th><th>N. Comprovante</th></tr></table>
        </td>
        <td>
            <div class="panel-title">Responsável pelo Seguro - &nbsp; {{ $responsible }}</div>
            <table class="panel-table"><tr><th>Nome da Seguradora</th><th>Número da Apólice</th></tr>
                <tr><td>{{ $damdfe['insurance']['insurer'] }}</td><td>{{ $damdfe['insurance']['policy'] }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<div class="documents">
    <div class="panel-title" style="text-align: center">Relação dos Documentos Fiscais Eletrônicos</div>
    <table class="two-columns">
        <tr>
            <td>
                <table class="panel-table"><tr><th>Tp. Doc.</th><th>Chave de acesso do documento fiscal</th></tr>
                    @foreach ($documents as $row)
                        <tr><td>{{ $row[0]['type'] }}</td><td class="key">{{ implode(' ', str_split($row[0]['key'], 4)) }}</td></tr>
                    @endforeach
                </table>
            </td>
            <td>
                <table class="panel-table"><tr><th>Tp. Doc.</th><th>Chave de acesso do documento fiscal</th></tr>
                    @foreach ($documents as $row)
                        @if (isset($row[1]))
                            <tr><td>{{ $row[1]['type'] }}</td><td class="key">{{ implode(' ', str_split($row[1]['key'], 4)) }}</td></tr>
                        @else
                            <tr><td></td><td></td></tr>
                        @endif
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
</div>

<div class="observations">
    <div class="observations-title">Observação</div>
    <div class="observations-content">{{ $damdfe['additional_info'] }}</div>
</div>
</body>
</html>
