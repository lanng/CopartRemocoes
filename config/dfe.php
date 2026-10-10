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

    // Limite individual dos XMLs de NF-e lidos pelo importador (10 MiB por arquivo).
    'nfe_xml_max_file_size_bytes' => (int) env('DFE_NFE_XML_MAX_FILE_SIZE_BYTES', 10 * 1024 * 1024),
    'nfe_xml_max_files_per_import' => (int) env('DFE_NFE_XML_MAX_FILES_PER_IMPORT', 10),

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
        'production_enabled' => (bool) env('DFE_CTE_PRODUCTION_ENABLED', false),
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

    // Nomes abreviados/alternativos que chegam dos registros (extração de PDF)
    // → nome canônico na base IBGE local (cities). Chaves já normalizadas
    // (maiúsculas, sem acento). A resolução é case/acento-insensível; aqui
    // ficam só as diferenças reais de nome (portado do CITY_CONFIG do script
    // Python de emissão; teclas do Lab ignoradas).
    'city_aliases' => [
        'PIRAPORA' => 'Pirapora do Bom Jesus',
        'EMBU' => 'Embu das Artes',
    ],

    // Próximo nCT disponível. O valor também funciona como limite mínimo para
    // recuperar uma sequência local menor após restauração de banco.
    'numbering_start' => env('DFE_CTE_NUMBER_START'),

    'mdfe' => [
        'emitter' => env('DFE_MDFE_EMITTER', 'agent'),
        'version' => env('DFE_MDFE_VERSION', '3.00'),
        'series' => (int) env('DFE_MDFE_SERIE', 1),
        'tp_emit' => env('DFE_MDFE_TP_EMIT', '1'),
        'tp_emis' => env('DFE_MDFE_TP_EMIS', '1'),
        'rntrc' => env('DFE_MDFE_RNTRC', '45963122'),
        // Próximo nMDF-e disponível; nunca reduz a sequência salva.
        'numbering_start' => env('DFE_MDFE_NUMBER_START'),
        'xsd_path' => env('DFE_MDFE_XSD_PATH') ?: resource_path('xsds/mdfe'),
        'qr_base_url' => env('DFE_MDFE_QR_BASE_URL', 'https://dfe-portal.svrs.rs.gov.br/mdfe/qrCode'),
        'insurer_name' => env('DFE_MDFE_INSURER_NAME'),
        'insurer_cnpj' => env('DFE_MDFE_INSURER_CNPJ'),
        'policy_number' => env('DFE_MDFE_POLICY_NUMBER'),
        'default_endorsement' => env('DFE_MDFE_DEFAULT_ENDORSEMENT'),
        'ncm_vehicle_removal' => env('DFE_MDFE_NCM_VEHICLE_REMOVAL', '8703.32.10'),
        'ncm_tank_alcohol' => env('DFE_MDFE_NCM_TANK_ALCOHOL', '2207.10.90'),
        'production_enabled' => (bool) env('DFE_MDFE_PRODUCTION_ENABLED', false),
        'base_url' => env('DFE_MDFE_BASE_URL', match ((string) env('DFE_TP_AMB', '2')) {
            '1' => 'https://mdfe.svrs.rs.gov.br',
            default => 'https://mdfe-homologacao.svrs.rs.gov.br',
        }),
        'paths' => [
            'recepcao_sinc' => env('DFE_MDFE_PATH_RECEPCAO_SINC', '/ws/MDFeRecepcaoSinc/MDFeRecepcaoSinc.asmx'),
            'consulta' => env('DFE_MDFE_PATH_CONSULTA', '/ws/MDFeConsulta/MDFeConsulta.asmx'),
            'evento' => env('DFE_MDFE_PATH_EVENTO', '/ws/MDFeRecepcaoEvento/MDFeRecepcaoEvento.asmx'),
        ],
    ],

];
