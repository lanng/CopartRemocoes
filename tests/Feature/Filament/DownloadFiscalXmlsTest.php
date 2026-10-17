<?php

namespace Tests\Feature\Filament;

use App\Enums\CteDocumentStatusEnum;
use App\Filament\Resources\CteEmissionResource\Pages\ListCteEmissions;
use App\Filament\Resources\MdfeDocumentResource\Pages\ManageMdfeDocuments;
use App\Models\CteDocument;
use App\Models\MdfeDocument;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class DownloadFiscalXmlsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 17)->setTime(12, 0));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create());
        Storage::fake('dfe');
    }

    #[DataProvider('documentPages')]
    public function test_export_defaults_to_the_previous_month(string $page, string $model, string $type): void
    {
        Livewire::test($page)
            ->assertActionVisible('downloadXmls')
            ->mountAction('downloadXmls')
            ->assertActionDataSet(['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    }

    #[DataProvider('documentPages')]
    public function test_zip_contains_all_emissions_in_the_period_in_brasilia_time(string $page, string $model, string $type): void
    {
        $first = $this->createDocument($model, '2026-09-01 03:00:00');
        $last = $this->createDocument($model, '2026-10-01 02:59:59', [
            'status' => CteDocumentStatusEnum::CANCELLED,
            'emitter' => 'lab',
            'authorized_at' => '2026-10-02 12:00:00',
            'created_at' => '2026-08-01 12:00:00',
        ]);
        $this->createDocument($model, '2026-09-01 02:59:59');
        $this->createDocument($model, '2026-10-01 03:00:00');
        $otherModel = $model === CteDocument::class ? MdfeDocument::class : CteDocument::class;
        $this->createDocument($otherModel, '2026-09-15 12:00:00');
        $model::factory()->create(['issued_at' => '2026-09-15 12:00:00', 'xml_path' => null]);
        $model::factory()->create(['issued_at' => '2026-09-15 12:00:00', 'xml_path' => '']);

        $component = $this->download($page)
            ->assertHasNoActionErrors()
            ->assertFileDownloaded("{$type}-xmls-2026-09-01-a-2026-09-30.zip");

        $this->assertSame([
            "{$first->access_key}-{$type}.xml" => Storage::disk('dfe')->get($first->xml_path),
            "{$last->access_key}-{$type}.xml" => Storage::disk('dfe')->get($last->xml_path),
        ], $this->zipContents($component));
    }

    #[DataProvider('documentPages')]
    public function test_export_includes_all_pages_and_is_not_restricted_by_table_filters(string $page, string $model, string $type): void
    {
        for ($index = 0; $index < 12; $index++) {
            $this->createDocument($model, '2026-09-15 12:00:00');
        }

        $component = Livewire::test($page)
            ->set('tableRecordsPerPage', 5)
            ->filterTable('status', CteDocumentStatusEnum::REJECTED->value)
            ->callAction('downloadXmls', data: ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded();

        $this->assertCount(12, $this->zipContents($component));
    }

    #[DataProvider('documentPages')]
    public function test_an_empty_period_does_not_download_an_empty_archive(string $page, string $model, string $type): void
    {
        $this->download($page)
            ->assertNotified('Nenhum XML encontrado no período')
            ->assertNoFileDownloaded();
    }

    #[DataProvider('documentPages')]
    public function test_a_missing_archived_xml_prevents_an_incomplete_download(string $page, string $model, string $type): void
    {
        $this->createDocument($model, '2026-09-15 12:00:00');
        $missing = $this->createDocument($model, '2026-09-16 12:00:00');
        Storage::disk('dfe')->delete($missing->xml_path);

        $component = $this->download($page)->assertNoFileDownloaded();

        $this->assertStringContainsString("{$missing->access_key}-{$type}.xml", session('filament.notifications.0.body'));
        $component->assertNotified('Exportação não concluída');
    }

    #[DataProvider('documentPages')]
    public function test_empty_xml_content_prevents_an_incomplete_download(string $page, string $model, string $type): void
    {
        $document = $this->createDocument($model, '2026-09-15 12:00:00');
        Storage::disk('dfe')->put($document->xml_path, '');

        $this->download($page)
            ->assertNotified('Exportação não concluída')
            ->assertNoFileDownloaded();
    }

    #[DataProvider('documentPages')]
    public function test_export_requires_both_dates_and_rejects_an_inverted_period(string $page, string $model, string $type): void
    {
        Livewire::test($page)
            ->callAction('downloadXmls', data: ['start_date' => null, 'end_date' => null])
            ->assertHasActionErrors(['start_date' => 'required', 'end_date' => 'required'])
            ->assertNoFileDownloaded();

        Livewire::test($page)
            ->callAction('downloadXmls', data: ['start_date' => '2026-09-30', 'end_date' => '2026-09-01'])
            ->assertHasActionErrors(['end_date' => 'after_or_equal'])
            ->assertNoFileDownloaded();
    }

    #[DataProvider('documentPages')]
    public function test_export_accepts_a_single_day(string $page, string $model, string $type): void
    {
        $this->createDocument($model, '2026-09-15 03:00:00');
        $this->createDocument($model, '2026-09-16 02:59:59');
        $this->createDocument($model, '2026-09-16 03:00:00');

        $component = Livewire::test($page)
            ->callAction('downloadXmls', data: ['start_date' => '2026-09-15', 'end_date' => '2026-09-15'])
            ->assertHasNoActionErrors()
            ->assertFileDownloaded("{$type}-xmls-2026-09-15-a-2026-09-15.zip");

        $this->assertCount(2, $this->zipContents($component));
    }

    #[DataProvider('documentPages')]
    public function test_export_checks_authorization_again_when_submitted(string $page, string $model, string $type): void
    {
        $this->createDocument($model, '2026-09-15 12:00:00');
        $component = Livewire::test($page)->assertActionVisible('downloadXmls')->mountAction('downloadXmls');
        Gate::before(fn (User $user, string $ability, array $arguments): ?bool => $ability === 'viewAny' && $arguments[0] === $model ? false : null);

        $component->callMountedAction()
            ->assertNoFileDownloaded();
    }

    /** @return array<string, array{class-string, class-string, string}> */
    public static function documentPages(): array
    {
        return [
            'CT-e' => [ListCteEmissions::class, CteDocument::class, 'cte'],
            'MDF-e' => [ManageMdfeDocuments::class, MdfeDocument::class, 'mdfe'],
        ];
    }

    private function download(string $page): Testable
    {
        return Livewire::test($page)
            ->callAction('downloadXmls', data: ['start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    }

    /**
     * @param  class-string<CteDocument|MdfeDocument>  $model
     * @param  array<string, mixed>  $attributes
     */
    private function createDocument(string $model, string $issuedAt, array $attributes = []): Model
    {
        $accessKey = fake()->unique()->numerify(str_repeat('#', 44));
        $path = "xmls/{$accessKey}.xml";
        Storage::disk('dfe')->put($path, "<proc key=\"{$accessKey}\"/>");

        return $model::factory()->create(array_merge([
            'cte_emission_batch_id' => null,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'access_key' => $accessKey,
            'issued_at' => $issuedAt,
            'xml_path' => $path,
        ], $attributes));
    }

    /** @return array<string, string> */
    private function zipContents(Testable $component): array
    {
        $temporaryFile = tmpfile();
        $this->assertIsResource($temporaryFile);
        fwrite($temporaryFile, base64_decode($component->effects['download']['content']));
        $zip = new ZipArchive;

        try {
            $this->assertTrue($zip->open(stream_get_meta_data($temporaryFile)['uri']));
            $contents = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $contents[$zip->getNameIndex($index)] = $zip->getFromIndex($index);
            }

            $zip->close();

            return $contents;
        } finally {
            fclose($temporaryFile);
        }
    }
}
