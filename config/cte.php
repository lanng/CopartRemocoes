<?php

return [
    'api_version' => '1',
    'poll_after_seconds' => 10,
    'heartbeat_stale_after_seconds' => 120,
    'claim_lease_minutes' => 10,
    'xml_root' => env('CTE_XML_ROOT', 'C:\\lab\\cte\\notas'),
];
