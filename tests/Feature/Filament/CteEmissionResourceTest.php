<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CteEmissionResource;
use App\Filament\Resources\CteEmissionResource\Pages\ListCteEmissions;
use App\Models\CteDocument;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CteEmissionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        /** @var User $user */
        $user = User::factory()->create();
        $this->actingAs($user);

        Storage::fake('dfe');
    }

    public function test_lists_emissions_from_both_emitters(): void
    {
        $direct = CteDocument::factory()->authorized()->create(['emitter' => 'direct']);
        $lab = CteDocument::factory()->authorized()->create(['emitter' => 'lab']);

        Livewire::test(ListCteEmissions::class)
            ->assertCanSeeTableRecords([$direct, $lab]);
    }

    public function test_download_actions_only_appear_when_the_file_exists(): void
    {
        $withoutFiles = CteDocument::factory()->authorized()->create(['emitter' => 'direct']);

        Livewire::test(ListCteEmissions::class)
            ->assertTableActionHidden('downloadXml', $withoutFiles)
            ->assertTableActionHidden('downloadPdf', $withoutFiles);

        $accessKey = str_repeat('3', 44);
        Storage::disk('dfe')->put("cte/{$accessKey}/cte-proc.xml", '<cteProc/>');

        $withFiles = CteDocument::factory()->authorized()->create([
            'emitter' => 'direct',
            'access_key' => $accessKey,
            'xml_path' => "cte/{$accessKey}/cte-proc.xml",
            'pdf_path' => "cte/{$accessKey}/dacte.pdf",
        ]);

        Livewire::test(ListCteEmissions::class)
            ->assertTableActionVisible('downloadXml', $withFiles)
            ->assertTableActionVisible('downloadPdf', $withFiles);
    }

    public function test_is_not_creatable(): void
    {
        $this->assertFalse(CteEmissionResource::canCreate());
    }

    public function test_regenerates_the_dacte_from_the_stored_proc(): void
    {
        $accessKey = str_repeat('3', 44);

        Storage::disk('dfe')->put("cte/{$accessKey}/cte-proc.xml", (string) file_get_contents(__DIR__.'/../../Fixtures/Dfe/cte-2962.xml'));

        $document = CteDocument::factory()->authorized()->create([
            'emitter' => 'direct',
            'access_key' => $accessKey,
            'xml_path' => "cte/{$accessKey}/cte-proc.xml",
        ]);

        Livewire::test(ListCteEmissions::class)
            ->callTableAction('regenerateDacte', $document);

        $document->refresh();

        $this->assertNotNull($document->pdf_path);
        Storage::disk('dfe')->assertExists($document->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) Storage::disk('dfe')->get($document->pdf_path));
    }
}
