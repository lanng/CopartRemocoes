<?php

return [

    // 2 = homologação, 1 = produção (spec §2.4). O BuildCteXml deriva TODAS as
    // regras de ambiente (nomes/CNPJs de teste, indIEToma, QR) daqui — nunca hardcoded.
    'tp_amb' => env('DFE_TP_AMB', '2'),

    // UF do emissor (SP): cUF do consStatServCTe e da chave de acesso.
    'cuf' => env('DFE_CUF', '35'),

    'base_url' => env('DFE_BASE_URL', match ((string) env('DFE_TP_AMB', '2')) {
        '1' => 'https://nfe.fazenda.sp.gov.br/CTeWS/WS',
        default => 'https://homologacao.nfe.fazenda.sp.gov.br/CTeWS/WS',
    }),

    // Mesmo A1 do CIOT (valores idênticos aos de CIOT_CERT_*); P12 direto (spec §7).
    'cert_path' => env('DFE_CERT_PATH'),

    'cert_password' => env('DFE_CERT_PASSWORD'),

    // Cadeia CA da SEFAZ embutida — CURLOPT_SSL_VERIFYPEER=true é obrigatório
    // (spec §7); sem o arquivo em produção o cliente falha fast.
    'ca_bundle' => env('DFE_CA_BUNDLE', storage_path('certs/sefaz-ca.pem')),

    'timeout' => (int) env('DFE_TIMEOUT', 30),

    'cte_version' => env('DFE_CTE_VERSION', '4.00'),

    'log_channel' => env('DFE_LOG_CHANNEL', 'dfe'),

    'paths' => [
        'status' => env('DFE_PATH_STATUS', 'CTeStatusServicoV4.asmx'),
        'recepcao_sinc' => env('DFE_PATH_RECEPCAO_SINC', 'CTeRecepcaoSincV4.asmx'),
        'consulta' => env('DFE_PATH_CONSULTA', 'CTeConsultaV4.asmx'),
        'evento' => env('DFE_PATH_EVENTO', 'CTeRecepcaoEventoV4.asmx'),
    ],

];
