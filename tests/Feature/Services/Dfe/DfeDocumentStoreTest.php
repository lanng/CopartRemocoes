<?php

namespace Tests\Feature\Services\Dfe;

use App\Services\Dfe\DfeDocumentStore;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    public function test_stores_cte_event_proc_under_the_event_id_path(): void
    {
        $accessKey = str_repeat('7', 44);
        $eventId = 'ID110111'.$accessKey.'001';
        $proc = '<?xml version="1.0" encoding="UTF-8"?><procEventoCTe versao="4.00"/>';

        $stored = $this->store->storeCteEventProc($accessKey, $eventId, $proc);

        $this->assertSame("cte/{$accessKey}/eventos/{$eventId}-proc.xml", $stored['path']);
        $this->assertSame(hash('sha256', $proc), $stored['sha256']);
        $this->assertSame($proc, $this->store->read($stored['path']));
    }

    public function test_stores_nfe_originals_immutably_by_access_key_and_content_hash(): void
    {
        $accessKey = str_repeat('4', 44);
        $firstXml = '<nfeProc><NFe>original</NFe></nfeProc>';
        $secondXml = '<nfeProc><NFe>corrected copy</NFe></nfeProc>';

        $first = $this->store->storeNfeOriginal($accessKey, $firstXml);
        $second = $this->store->storeNfeOriginal($accessKey, $secondXml);

        $this->assertSame("nfe/{$accessKey}/".hash('sha256', $firstXml).'.xml', $first['path']);
        $this->assertSame(hash('sha256', $firstXml), $first['sha256']);
        $this->assertNotSame($first['path'], $second['path']);
        $this->assertSame($firstXml, $this->store->read($first['path']));
        $this->assertSame($secondXml, $this->store->read($second['path']));
    }

    public function test_does_not_report_an_artifact_as_stored_when_the_disk_write_fails(): void
    {
        $disk = \Mockery::mock();
        $disk->shouldReceive('put')->once()->with('failed.xml', 'payload')->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('dfe')->andReturn($disk);

        $this->expectException(RuntimeException::class);

        $this->store->store('failed.xml', 'payload');
    }
}
