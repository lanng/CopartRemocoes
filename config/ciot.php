<?php

return [

    'env' => env('CIOT_ENV', 'homologacao'),

    'base_url' => env('CIOT_BASE_URL', match (env('CIOT_ENV', 'homologacao')) {
        'producao' => 'https://appservices.antt.gov.br/pefServices',
        default => 'https://appservices-hml.antt.gov.br/pefServices',
    }),

    'api_key' => env('CIOT_API_KEY'),

    'cert_path' => env('CIOT_CERT_PATH'),

    'cert_password' => env('CIOT_CERT_PASSWORD'),

    'cert_valid_to' => env('CIOT_CERT_VALID_TO'),

    'timeout' => (int) env('CIOT_TIMEOUT', 30),

    'token_ttl_minutes' => (int) env('CIOT_TOKEN_TTL_MINUTES', 55),

    'log_channel' => env('CIOT_LOG_CHANNEL', 'stack'),

    'company' => [
        'cnpj' => env('CIOT_COMPANY_CNPJ', '12563112000130'),
        'rntrc' => env('CIOT_COMPANY_RNTRC', '045963122'),
        'name' => env('CIOT_COMPANY_NAME', 'FERNANDES E FERNANDES LTDA'),
    ],

    'paths' => [
        // Camada simplificada (wrapper oficial da DLL), mantida como fallback de diagnóstico.
        'token' => env('CIOT_PATH_TOKEN', '/token'),
        'simplified_generate' => env('CIOT_PATH_SIMPLIFIED_GENERATE', '/gerar'),
        // Serviços canônicos do DCS (rotas confirmadas em homologação sob /api/).
        'declare' => env('CIOT_PATH_DECLARE', '/api/DeclaracaoOperacaoTransporte'),
        'cancel' => env('CIOT_PATH_CANCEL', '/api/CancelamentoOperacaoTransporte'),
        'close' => env('CIOT_PATH_CLOSE', '/api/EncerramentoOperacaoTransporte'),
        'query' => env('CIOT_PATH_QUERY', '/api/consultarCIOT'),
    ],

    'lines' => [
        'vehicle_removal' => [
            'bank_code' => env('CIOT_REMOVAL_BANK_CODE'),
            'bank_agency' => env('CIOT_REMOVAL_BANK_AGENCY'),
            'bank_account' => env('CIOT_REMOVAL_BANK_ACCOUNT'),
        ],
        'tank_alcohol' => [
            'bank_code' => env('CIOT_TANK_BANK_CODE'),
            'bank_agency' => env('CIOT_TANK_BANK_AGENCY'),
            'bank_account' => env('CIOT_TANK_BANK_ACCOUNT'),
        ],
    ],

    'defaults' => [
        'ind_retorno_vazio' => (bool) env('CIOT_IND_RETORNO_VAZIO', true),
        'ind_alto_desempenho' => (bool) env('CIOT_IND_ALTO_DESEMPENHO', true),
        'composicao_veicular' => (bool) env('CIOT_COMPOSICAO_VEICULAR', true),
    ],

    // A base de naturezas da homologação só tem o código 1 (base defasada).
    // Ative apenas em homologação; em produção deve ser false (13 remoção / 8 tanque).
    'natureza_fallback' => (bool) env('CIOT_NATUREZA_FALLBACK', false),

    // Consulta de CEP para preencher cidade/UF/IBGE no formulário.
    'lookup' => [
        'brasilapi_url' => env('CIOT_BRASILAPI_URL', 'https://brasilapi.com.br/api/cep/v2'),
        'viacep_url' => env('CIOT_VIACEP_URL', 'https://viacep.com.br/ws'),
        'timeout' => (int) env('CIOT_LOOKUP_TIMEOUT', 10),
    ],

    // Distância rodoviária (OpenRouteService; free tier 2.000 rotas/dia).
    'distance' => [
        'openrouteservice_url' => env('CIOT_ORS_URL', 'https://api.openrouteservice.org/v2/directions/driving-car'),
        'api_key' => env('CIOT_DISTANCE_API_KEY'),
        'timeout' => (int) env('CIOT_DISTANCE_TIMEOUT', 15),
    ],

];
