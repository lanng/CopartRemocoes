<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class CteAgentRoutesRemovedTest extends TestCase
{
    public function test_all_legacy_agent_endpoints_are_removed(): void
    {
        foreach ([
            '/api/v1/cte-agent/heartbeat',
            '/api/v1/cte-agent/claim',
            '/api/v1/cte-agent/documents/1/progress',
            '/api/v1/cte-agent/documents/1/result',
            '/api/v1/cte-agent/mdfe/documents/1/progress',
            '/api/v1/cte-agent/mdfe/documents/1/result',
        ] as $uri) {
            $this->postJson($uri)->assertNotFound();
        }
    }
}
