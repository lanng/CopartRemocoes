<?php

return [
    'api_version' => '1',
    'poll_after_seconds' => 10,
    'heartbeat_stale_after_seconds' => 120,
    // Lease do claim: a emissão de um MDF-e com muitos CT-es (digitação no
    // Lab) leva dezenas de minutos — cada progresso do agent renova o lease,
    // mas um agent silencioso por mais que isso pode ter o documento
    // reenfileirado pelo reaper sob ele. Ajustável por ambiente.
    'claim_lease_minutes' => (int) env('CTE_CLAIM_LEASE_MINUTES', 10),
    'xml_root' => env('CTE_XML_ROOT', 'C:\\lab\\cte\\notas'),
];
