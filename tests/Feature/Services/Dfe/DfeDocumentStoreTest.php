<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\DfeDocumentStore;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DfeDocumentStoreTest extends TestCase
{
    protected DfeDocumentStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dfe');

        $this->store = app(DfeDocumentStore::class);
    }

    public function test_stores_the_cte_proc_under_the_access_key_path(): void
    {
        $accessKey = str_repeat('3', 44);
        $proc = '<?xml version="1.0" encoding="UTF-8"?><cteProc versao="4.00"/>';

        $stored = $this->store->storeCteProc($accessKey, $proc);

        $this->assertSame("cte/{$accessKey}/cte-proc.xml", $stored['path']);
        $this->assertSame(hash('sha256', $proc), $stored['sha256']);
        $this->assertTrue($this->store->exists($stored['path']));
        $this->assertSame($proc, $this->store->read($stored['path']));
    }
}
