<?php

namespace Tests\Unit;

use Tests\TestCase;

class DatabaseConfigurationTest extends TestCase
{
    public function test_phpunit_uses_ephemeral_sqlite_instead_of_the_application_config_cache(): void
    {
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
