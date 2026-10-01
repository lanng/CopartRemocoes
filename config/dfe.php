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

    // Mesmo A1 do CIOT: por padrão reusa as chaves CIOT_CERT_* (decisão do
    // orquestrador); DFE_CERT_* sobrepõe se um dia o A1 do DFe divergir.
    'cert_path' => env('DFE_CERT_PATH', env('CIOT_CERT_PATH')),

    'cert_password' => env('DFE_CERT_PASSWORD', env('CIOT_CERT_PASSWORD')),

    // Cadeia CA da SEFAZ embutida — CURLOPT_SSL_VERIFYPEER=true é obrigatório
    // (spec §7); sem o arquivo em produção o cliente falha fast.
    'ca_bundle' => env('DFE_CA_BUNDLE', storage_path('certs/sefaz-ca.pem')),

    'timeout' => (int) env('DFE_TIMEOUT', 30),

    'cte_version' => env('DFE_CTE_VERSION', '4.00'),

    'log_channel' => env('DFE_LOG_CHANNEL', 'dfe'),

    // XSDs locais para validação pré-transmissão (spec §2.5): cte_v4.00.xsd
    // valida o CTe isolado; procCTe_v4.00.xsd, o proc completo com protocolo.
    'xsd_path' => env('DFE_XSD_PATH', resource_path('xsds/cte')),

    'paths' => [
        'status' => env('DFE_PATH_STATUS', 'CTeStatusServicoV4.asmx'),
        'recepcao_sinc' => env('DFE_PATH_RECEPCAO_SINC', 'CTeRecepcaoSincV4.asmx'),
        'consulta' => env('DFE_PATH_CONSULTA', 'CTeConsultaV4.asmx'),
        'evento' => env('DFE_PATH_EVENTO', 'CTeRecepcaoEventoV4.asmx'),
    ],

    // Cabeçalho fiscal do emissor (fixo — gabaritos 2962/2963, spec §2.5).
    'emit' => [
        'cnpj' => env('DFE_EMIT_CNPJ', '12563112000130'),
        'ie' => env('DFE_EMIT_IE', '150046654114'),
        'xNome' => env('DFE_EMIT_XNOME', 'FERNANDES & FERNANDES LTDA'),
        'xFant' => env('DFE_EMIT_XFANT', 'AUTO SOCORRO ESTRELA'),
        'xLgr' => env('DFE_EMIT_XLGR', 'RUA PRUDENTE DE MORAES'),
        'nro' => env('DFE_EMIT_NRO', '130'),
        'xBairro' => env('DFE_EMIT_XBAIRRO', 'PQ RES ITAMARATI'),
        'cMun' => env('DFE_EMIT_CMUN', '3500105'),
        'xMun' => env('DFE_EMIT_XMUN', 'Adamantina'),
        'cep' => env('DFE_EMIT_CEP', '17800000'),
        'uf' => env('DFE_EMIT_UF', 'SP'),
        'crt' => env('DFE_EMIT_CRT', '1'),
    ],

    'cte' => [
        'cfop' => env('DFE_CTE_CFOP', '5353'),
        'natOp' => env('DFE_CTE_NATOP', 'PREST SERV TRANSPORTE'),
        'serie' => env('DFE_CTE_SERIE', '1'),
        'tpEmis' => env('DFE_CTE_TP_EMIS', '1'),
        'modal' => env('DFE_CTE_MODAL', '01'),
        'rntrc' => env('DFE_CTE_RNTRC', '45963122'),
        'verProc' => env('DFE_CTE_VER_PROC', 'COPART-DFE-1.0'),
        // Peso default por veículo na linha de remoção (qCarga do infQ).
        'default_weight_kg' => env('DFE_CTE_DEFAULT_WEIGHT_KG', '2000.0000'),
    ],

    // Primeiro nCT da sequência própria quando a linha de numeração não existe
    // ainda. Homologação usa o número livre de teste (spec §2.4); em produção a
    // seed manual no switch é OBRIGATÓRIA (último nCT do Lab + 1, spec §7) —
    // o AllocateCteNumber recusa criar a linha sem a env explícita.
    'numbering_start' => env('DFE_CTE_NUMBER_START'),

];
