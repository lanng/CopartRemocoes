<?php

namespace Tests\Feature\Console;

use App\Enums\CiotStatusEnum;
use App\Enums\CteDocumentStatusEnum;
use App\Enums\FiscalDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\CteEmissionBatch;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Services\Dfe\AccessKey;
use App\Services\Dfe\BackfillFiscalDocumentLinks;
use App\Services\Dfe\DfeDocumentStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackfillFiscalDocumentLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dry_runs_then_applies_verified_cte_and_ciot_links_idempotently(): void
    {
        [$batch, $cte, $ciot, $mdfe] = $this->makeVerifiedManifest();

        $service = app(BackfillFiscalDocumentLinks::class);
        $dryRun = $service->handle(batchIds: [$batch->id], apply: false);

        $this->assertSame(1, $dryRun['resolved']);
        $this->assertSame(0, $dryRun['pending']);
        $this->assertSame(0, $dryRun['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertDatabaseCount('dfe_document_reservations', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);

        $service->handle(batchIds: [$batch->id], apply: true);
        $service->handle(batchIds: [$batch->id], apply: true);

        $this->assertDatabaseHas('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
            'position' => 0,
        ]);
        $this->assertSame($ciot->id, $mdfe->fresh()->ciot_id);
        $this->assertDatabaseCount('cte_document_mdfe_document', 1);
        $this->assertDatabaseCount('dfe_document_reservations', 2);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $cte->fresh()->status);
        $this->assertSame(FiscalDocumentStatusEnum::AUTHORIZED, $cte->fresh()->fiscal_status);
        $this->assertSame(CteDocumentStatusEnum::AUTHORIZED, $mdfe->fresh()->status);
        $this->assertSame(FiscalDocumentStatusEnum::AUTHORIZED, $mdfe->fresh()->fiscal_status);
    }

    public function test_it_only_reserves_resources_verified_for_a_resolved_manifest(): void
    {
        [$batch, $cte, $ciot, $mdfe] = $this->makeVerifiedManifest();
        $unrelatedCte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'access_key' => '35260912563112000130570010000028171998887776',
            'protocol' => '135260000000003',
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '100',
            'emitter' => 'direct',
            'execution_mode' => 'live',
        ]);
        $unrelatedCiot = Ciot::factory()->issued()->create([
            'cte_emission_batch_id' => $batch->id,
            'ciot_number' => '999999999999',
        ]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['resolved']);
        $this->assertDatabaseCount('dfe_document_reservations', 2);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $unrelatedCte->id,
        ]);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $unrelatedCiot->id,
        ]);
    }

    public function test_it_keeps_an_authorized_superseded_manifest_reserved_until_fiscal_terminal_status(): void
    {
        [$batch, $cte, $ciot, $mdfe] = $this->makeVerifiedManifest();
        $mdfe->update(['status' => CteDocumentStatusEnum::SUPERSEDED]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['resolved']);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
    }

    public function test_it_does_not_link_disagreeing_cte_evidence_and_reserves_the_batch(): void
    {
        [$batch, $cte, $ciot, $mdfe, $cteKey, $mdfePath, $mdfeKey, $ciotNumber] = $this->makeVerifiedManifest();
        $secondCteKey = '35260912563112000130570010000028171998887776';
        $secondCte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'access_key' => $secondCteKey,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '100',
            'emitter' => 'direct',
            'execution_mode' => 'live',
        ]);
        Storage::disk('dfe')->put($mdfePath, $this->mdfeProcXml($mdfeKey, [$secondCteKey], $ciotNumber));

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(0, $report['resolved']);
        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertSame($ciot->id, $mdfe->fresh()->ciot_id);
        $this->assertDatabaseCount('dfe_document_reservations', 3);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $secondCte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
    }

    public function test_artisan_command_defaults_to_dry_run_and_applies_only_the_requested_batch(): void
    {
        [$batch, $cte, $ciot, $mdfe] = $this->makeVerifiedManifest();
        $otherBatch = CteEmissionBatch::factory()->create();
        MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $otherBatch->id,
        ]);

        $this->artisan('app:backfill-fiscal-document-links', [
            '--batch' => [(string) $batch->id],
        ])
            ->expectsOutputToContain('Mode: dry-run')
            ->expectsOutputToContain('Scanned: 1')
            ->expectsOutputToContain('Resolved: 1')
            ->assertExitCode(0);

        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertDatabaseCount('dfe_document_reservations', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);

        $this->artisan('app:backfill-fiscal-document-links', [
            '--batch' => [(string) $batch->id],
            '--apply' => true,
        ])
            ->expectsOutputToContain('Mode: apply')
            ->expectsOutputToContain('Scanned: 1')
            ->assertExitCode(0);

        $this->assertDatabaseHas('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
            'position' => 0,
        ]);
        $this->assertSame($ciot->id, $mdfe->fresh()->ciot_id);
        $this->assertDatabaseCount('dfe_document_reservations', 2);

        $this->artisan('app:backfill-fiscal-document-links', [
            '--from-batch' => (string) $batch->id,
            '--to-batch' => (string) $batch->id,
        ])
            ->expectsOutputToContain('Mode: dry-run')
            ->expectsOutputToContain('Scanned: 1')
            ->assertExitCode(0);
    }

    public function test_artisan_command_rejects_an_incomplete_batch_range(): void
    {
        $this->artisan('app:backfill-fiscal-document-links', [
            '--from-batch' => '1',
        ])->assertExitCode(1);
    }

    public function test_service_rejects_invalid_batch_ids_instead_of_turning_them_into_an_unscoped_scan(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [-1]);
    }

    public function test_it_does_not_guess_a_ciot_from_batch_history_when_proc_evidence_is_missing(): void
    {
        Storage::fake('dfe');

        $batch = CteEmissionBatch::factory()->create();
        $snapshotCiot = Ciot::factory()->issued()->create([
            'cte_emission_batch_id' => $batch->id,
            'ciot_number' => '123456789012',
        ]);
        $latestCiot = Ciot::factory()->issued()->create([
            'cte_emission_batch_id' => $batch->id,
            'ciot_number' => '987654321098',
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'snapshot' => [
                'ciot' => $snapshotCiot->ciot_number,
                'cte_access_keys' => [],
            ],
        ]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['pending']);
        $this->assertNull($mdfe->fresh()->ciot_id);
        $this->assertDatabaseCount('dfe_document_reservations', 2);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $snapshotCiot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $latestCiot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
    }

    public function test_it_does_not_link_documents_when_the_proc_path_key_disagrees(): void
    {
        [$batch, $cte, $ciot, $mdfe] = $this->makeVerifiedManifest();
        $wrongPath = 'mdfe/35260912563112000130580010000001231123456700/mdfe-proc.xml';
        Storage::disk('dfe')->put(
            $wrongPath,
            $this->mdfeProcXml((string) $mdfe->access_key, [(string) $cte->access_key], (string) $ciot->ciot_number, (string) $mdfe->protocol),
        );
        $mdfe->update(['xml_path' => $wrongPath]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
        $this->assertDatabaseCount('dfe_document_reservations', 2);
    }

    public function test_it_does_not_link_a_canceled_ciots_record_even_when_its_number_matches(): void
    {
        [$batch, $cte, $ciot, $mdfe] = $this->makeVerifiedManifest();
        $ciot->update(['status' => CiotStatusEnum::CANCELED]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertNull($mdfe->fresh()->ciot_id);
        $this->assertDatabaseHas('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseCount('dfe_document_reservations', 1);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
        ]);
    }

    public function test_it_reports_and_preserves_existing_cte_links_outside_proc_evidence(): void
    {
        [$batch, $cte, , $mdfe] = $this->makeVerifiedManifest();
        $unverifiedCte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'access_key' => '35260912563112000130570010000028171998887776',
            'protocol' => '135260000000003',
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '100',
            'emitter' => 'direct',
            'execution_mode' => 'live',
        ]);
        $mdfe->cteDocuments()->attach($unverifiedCte->id, ['position' => 9]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 2);
        $this->assertDatabaseHas('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
            'position' => 0,
        ]);
        $this->assertDatabaseHas('cte_document_mdfe_document', [
            'cte_document_id' => $unverifiedCte->id,
            'mdfe_document_id' => $mdfe->id,
            'position' => 9,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $unverifiedCte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
    }

    public function test_it_rejects_proc_documents_whose_xml_model_disagrees_with_the_access_key(): void
    {
        [$batch, $cte, $ciot, $mdfe, , $mdfePath] = $this->makeVerifiedManifest();
        $mdfeXml = $this->mdfeProcXml(
            (string) $mdfe->access_key,
            [(string) $cte->access_key],
            (string) $ciot->ciot_number,
            (string) $mdfe->protocol,
        );
        Storage::disk('dfe')->put($mdfePath, str_replace('<mod>58</mod>', '<mod>57</mod>', $mdfeXml));

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    public function test_it_rejects_an_emitter_mismatch_against_a_persisted_snapshot(): void
    {
        [$batch, , , $mdfe] = $this->makeVerifiedManifest();
        $snapshot = (array) $mdfe->snapshot;
        $snapshot['ciot_responsible_cnpj'] = '99999999999999';
        $mdfe->update(['snapshot' => $snapshot]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    public function test_it_revalidates_cte_internal_evidence_before_applying_links(): void
    {
        [$batch, $cte] = $this->makeVerifiedManifest();
        $store = new class extends DfeDocumentStore
        {
            private bool $changed = false;

            public function read(string $path): string
            {
                $contents = parent::read($path);

                if (! $this->changed && str_starts_with($path, 'cte/')) {
                    $this->changed = true;
                    CteDocument::query()->where('xml_path', $path)->update(['protocol' => '135260000000099']);
                }

                return $contents;
            }
        };

        $report = (new BackfillFiscalDocumentLinks($store))->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
        ]);
    }

    public function test_it_rolls_back_all_links_when_a_candidate_is_reserved_by_another_manifest(): void
    {
        [$batch, $cte, $ciot, $mdfe] = $this->makeVerifiedManifest();
        $otherManifest = MdfeDocument::factory()->create();

        DfeDocumentReservation::query()->create([
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $otherManifest->id,
        ]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertNull($mdfe->fresh()->ciot_id);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertDatabaseCount('dfe_document_reservations', 1);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $otherManifest->id,
        ]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function changedCteEvidenceProvider(): array
    {
        return [
            'document status' => ['status', CteDocumentStatusEnum::CANCELLED->value],
            'fiscal status' => ['fiscal_status', FiscalDocumentStatusEnum::CANCELLED->value],
            'fiscal status code' => ['fiscal_status_code', '999'],
            'series' => ['series', '2'],
            'document number' => ['cte_number', '2818'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedCteEvidenceProvider')]
    public function test_it_does_not_link_a_cte_whose_evidence_changes_before_apply(string $field, string $value): void
    {
        [$batch, $cte] = $this->makeVerifiedManifest();
        $store = new class($field, $value) extends DfeDocumentStore
        {
            public function __construct(private readonly string $field, private readonly string $value) {}

            private bool $changed = false;

            public function read(string $path): string
            {
                $contents = parent::read($path);

                if (! $this->changed && str_starts_with($path, 'cte/')) {
                    $this->changed = true;
                    CteDocument::query()->where('xml_path', $path)->update([$this->field => $this->value]);
                }

                return $contents;
            }
        };

        $report = (new BackfillFiscalDocumentLinks($store))->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseMissing('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
        ]);
    }

    public function test_it_does_not_apply_links_when_mdfe_identity_changes_after_analysis(): void
    {
        [$batch, , , $mdfe] = $this->makeVerifiedManifest();
        $store = new class($mdfe->id) extends DfeDocumentStore
        {
            private bool $changed = false;

            public function __construct(private readonly int $mdfeId) {}

            public function read(string $path): string
            {
                $contents = parent::read($path);

                if (! $this->changed && str_starts_with($path, 'cte/')) {
                    $this->changed = true;
                    MdfeDocument::query()->whereKey($this->mdfeId)->update(['mdfe_number' => '124']);
                }

                return $contents;
            }
        };

        $report = (new BackfillFiscalDocumentLinks($store))->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    public function test_apply_classifies_new_evidence_changes_as_divergent_even_when_analysis_was_pending(): void
    {
        [$batch, $cte, $ciot, $mdfe, $cteKey, $mdfePath, $mdfeKey, $ciotNumber] = $this->makeVerifiedManifest();
        $secondCteKey = app(AccessKey::class)->build(
            cnpj: '12563112000130',
            aamm: '2609',
            serie: 1,
            number: 2818,
            cct: 83997538,
        );
        CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'access_key' => $secondCteKey,
            'protocol' => '135260000000003',
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '100',
            'emitter' => 'direct',
            'execution_mode' => 'live',
        ]);
        $snapshot = (array) $mdfe->snapshot;
        $snapshot['cte_access_keys'][] = $secondCteKey;
        $mdfe->update(['snapshot' => $snapshot]);
        Storage::disk('dfe')->put(
            $mdfePath,
            $this->mdfeProcXml($mdfeKey, [$cteKey, $secondCteKey], $ciotNumber, (string) $mdfe->protocol),
        );
        $store = new class extends DfeDocumentStore
        {
            private bool $changed = false;

            public function read(string $path): string
            {
                $contents = parent::read($path);

                if (! $this->changed && str_starts_with($path, 'cte/')) {
                    $this->changed = true;
                    CteDocument::query()->where('xml_path', $path)->update(['protocol' => '135260000000099']);
                }

                return $contents;
            }
        };

        $report = (new BackfillFiscalDocumentLinks($store))->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(0, $report['pending']);
        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseMissing('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
        ]);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    public function test_it_does_not_apply_links_when_pivots_change_after_analysis(): void
    {
        [$batch, $cte, , $mdfe] = $this->makeVerifiedManifest();
        $otherBatch = CteEmissionBatch::factory()->create();
        $unexpectedCte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $otherBatch->id,
            'access_key' => app(AccessKey::class)->build(
                cnpj: '12563112000130',
                aamm: '2609',
                serie: 1,
                number: 2820,
                cct: 83997540,
            ),
            'protocol' => '135260000000004',
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '100',
            'emitter' => 'direct',
            'execution_mode' => 'live',
        ]);
        $store = new class($mdfe->id, $unexpectedCte->id) extends DfeDocumentStore
        {
            private bool $changed = false;

            public function __construct(private readonly int $mdfeId, private readonly int $cteId) {}

            public function read(string $path): string
            {
                $contents = parent::read($path);

                if (! $this->changed && str_starts_with($path, 'cte/')) {
                    $this->changed = true;
                    MdfeDocument::query()->findOrFail($this->mdfeId)
                        ->cteDocuments()->attach($this->cteId, ['position' => 8]);
                }

                return $contents;
            }
        };

        $report = (new BackfillFiscalDocumentLinks($store))->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertNull($mdfe->fresh()->ciot_id);
        $this->assertDatabaseCount('cte_document_mdfe_document', 1);
        $this->assertDatabaseMissing('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('cte_document_mdfe_document', [
            'cte_document_id' => $unexpectedCte->id,
            'mdfe_document_id' => $mdfe->id,
            'position' => 8,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function changedProcArtifactProvider(): array
    {
        return [
            'MDF-e proc' => ['mdfe'],
            'CT-e proc' => ['cte'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedProcArtifactProvider')]
    public function test_it_does_not_apply_links_when_a_proc_artifact_changes_after_analysis(string $artifact): void
    {
        [$batch, , , $mdfe, , $mdfePath] = $this->makeVerifiedManifest();
        $store = new class($artifact, $mdfePath) extends DfeDocumentStore
        {
            private bool $changed = false;

            public function __construct(private readonly string $artifact, private readonly string $mdfePath) {}

            public function read(string $path): string
            {
                $contents = parent::read($path);

                if (! $this->changed && str_starts_with($path, 'cte/')) {
                    $this->changed = true;
                    $pathToChange = $this->artifact === 'mdfe' ? $this->mdfePath : $path;
                    Storage::disk('dfe')->put($pathToChange, Storage::disk('dfe')->get($pathToChange).'<!-- changed -->');
                }

                return $contents;
            }
        };

        $report = (new BackfillFiscalDocumentLinks($store))->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedXmlEvidenceProvider(): array
    {
        return [
            'wrong child namespace' => ['wrong_namespace'],
            'duplicate protocol element' => ['duplicate_protocol'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedXmlEvidenceProvider')]
    public function test_it_rejects_proc_elements_with_wrong_namespaces_or_duplicate_identity_nodes(string $case): void
    {
        [$batch, $cte, $ciot, $mdfe, , $mdfePath, $mdfeKey] = $this->makeVerifiedManifest();
        $xml = $this->mdfeProcXml(
            $mdfeKey,
            [(string) $cte->access_key],
            (string) $ciot->ciot_number,
            (string) $mdfe->protocol,
        );

        $xml = match ($case) {
            'wrong_namespace' => str_replace('<mod>58</mod>', '<foreign:mod xmlns:foreign="urn:foreign">58</foreign:mod>', $xml),
            'duplicate_protocol' => str_replace(
                '</mdfeProc>',
                '<protMDFe><infProt><tpAmb>2</tpAmb><chMDFe>'.$mdfeKey.'</chMDFe><cStat>100</cStat><nProt>'.$mdfe->protocol.'</nProt></infProt></protMDFe></mdfeProc>',
                $xml,
            ),
        };
        Storage::disk('dfe')->put($mdfePath, $xml);
        $mdfe->update(['sha256' => hash('sha256', $xml)]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    public function test_it_rejects_cte_evidence_from_a_different_fiscal_issuer(): void
    {
        [$batch, $cte, $ciot, $mdfe, , , $mdfeKey, $ciotNumber] = $this->makeVerifiedManifest();
        $foreignCteKey = app(AccessKey::class)->build(
            cnpj: '14517191000178',
            aamm: '2609',
            serie: 1,
            number: 2817,
            cct: 83997537,
        );
        $foreignCtePath = "cte/{$foreignCteKey}/cte-proc.xml";
        $cteXml = $this->cteProcXml($foreignCteKey, (string) $cte->protocol);
        $cte->update([
            'access_key' => $foreignCteKey,
            'series' => '1',
            'cte_number' => '2817',
            'xml_path' => $foreignCtePath,
            'sha256' => hash('sha256', $cteXml),
        ]);
        $snapshot = (array) $mdfe->snapshot;
        $snapshot['cte_access_keys'] = [$foreignCteKey];
        $mdfe->update(['snapshot' => $snapshot]);
        Storage::disk('dfe')->put($foreignCtePath, $cteXml);
        Storage::disk('dfe')->put(
            (string) $mdfe->xml_path,
            $this->mdfeProcXml($mdfeKey, [$foreignCteKey], $ciotNumber, (string) $mdfe->protocol),
        );

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseMissing('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
    }

    public function test_it_rejects_a_proc_with_an_invalid_access_key_check_digit(): void
    {
        [$batch, $cte, $ciot, $mdfe, , , $mdfeKey] = $this->makeVerifiedManifest();
        $invalidKey = substr($mdfeKey, 0, 43).(((int) substr($mdfeKey, -1) + 1) % 10);
        $invalidPath = "mdfe/{$invalidKey}/mdfe-proc.xml";
        $xml = $this->mdfeProcXml($invalidKey, [(string) $cte->access_key], (string) $ciot->ciot_number, (string) $mdfe->protocol);
        $mdfe->update([
            'access_key' => $invalidKey,
            'xml_path' => $invalidPath,
            'sha256' => hash('sha256', $xml),
        ]);
        Storage::disk('dfe')->put($invalidPath, $xml);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    public function test_it_rejects_a_proc_whose_xml_identity_fields_do_not_match_its_access_key(): void
    {
        [$batch, $cte, $ciot, $mdfe, , $mdfePath, $mdfeKey] = $this->makeVerifiedManifest();
        $xml = str_replace(
            '<nMDF>123</nMDF>',
            '<nMDF>124</nMDF>',
            $this->mdfeProcXml($mdfeKey, [(string) $cte->access_key], (string) $ciot->ciot_number, (string) $mdfe->protocol),
        );
        Storage::disk('dfe')->put($mdfePath, $xml);
        $mdfe->update(['sha256' => hash('sha256', $xml)]);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseCount('cte_document_mdfe_document', 0);
        $this->assertNull($mdfe->fresh()->ciot_id);
    }

    public function test_it_rejects_an_internal_fiscal_status_code_that_is_not_authorized(): void
    {
        [$batch, $cte, , $mdfe] = $this->makeVerifiedManifest();
        $cte->update(['fiscal_status_code' => '999']);

        $report = app(BackfillFiscalDocumentLinks::class)->handle(batchIds: [$batch->id], apply: true);

        $this->assertSame(1, $report['divergent']);
        $this->assertDatabaseMissing('cte_document_mdfe_document', [
            'cte_document_id' => $cte->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
    }

    /**
     * @return array{CteEmissionBatch, CteDocument, Ciot, MdfeDocument, string, string, string, string}
     */
    private function makeVerifiedManifest(): array
    {
        Storage::fake('dfe');

        $batch = CteEmissionBatch::factory()->create();
        $cteKey = '35260912563112000130570010000028171839975374';
        $mdfeKey = '35260912563112000130580010000001231123456780';
        $ciotNumber = '123456789012';
        $ctePath = "cte/{$cteKey}/cte-proc.xml";
        $mdfePath = "mdfe/{$mdfeKey}/mdfe-proc.xml";
        $cte = CteDocument::factory()->authorized()->create([
            'cte_emission_batch_id' => $batch->id,
            'access_key' => $cteKey,
            'protocol' => '135260000000001',
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'fiscal_status_code' => '100',
            'emitter' => 'direct',
            'execution_mode' => 'live',
            'series' => '1',
            'cte_number' => '2817',
            'xml_path' => $ctePath,
        ]);
        $ciot = Ciot::factory()->issued()->create([
            'cte_emission_batch_id' => $batch->id,
            'ciot_number' => $ciotNumber,
        ]);
        $mdfe = MdfeDocument::factory()->create([
            'cte_emission_batch_id' => $batch->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => FiscalDocumentStatusEnum::AUTHORIZED,
            'emitter' => 'direct',
            'execution_mode' => 'live',
            'access_key' => $mdfeKey,
            'protocol' => '135260000000002',
            'fiscal_status_code' => '100',
            'series' => '1',
            'mdfe_number' => '123',
            'xml_path' => $mdfePath,
            'snapshot' => [
                'schema_version' => 1,
                'ciot' => $ciotNumber,
                'ciot_responsible_cnpj' => '12563112000130',
                'cte_access_keys' => [$cteKey],
            ],
        ]);

        $cteXml = $this->cteProcXml($cteKey, $cte->protocol);
        $mdfeXml = $this->mdfeProcXml($mdfeKey, [$cteKey], $ciotNumber, $mdfe->protocol);
        Storage::disk('dfe')->put($ctePath, $cteXml);
        Storage::disk('dfe')->put($mdfePath, $mdfeXml);
        $cte->update(['sha256' => hash('sha256', $cteXml)]);
        $mdfe->update(['sha256' => hash('sha256', $mdfeXml)]);

        return [$batch, $cte, $ciot, $mdfe, $cteKey, $mdfePath, $mdfeKey, $ciotNumber];
    }

    private function cteProcXml(string $accessKey, string $protocol): string
    {
        return '<cteProc xmlns="http://www.portalfiscal.inf.br/cte">'
            .'<CTe><infCte Id="CTe'.$accessKey.'">'.$this->identityXml($accessKey, 'nCT', 'cCT')
            .'<emit><CNPJ>'.substr($accessKey, 6, 14).'</CNPJ></emit></infCte></CTe>'
            .'<protCTe><infProt><tpAmb>2</tpAmb><chCTe>'.$accessKey.'</chCTe>'
            .'<cStat>100</cStat><nProt>'.$protocol.'</nProt></infProt></protCTe></cteProc>';
    }

    /** @param array<int, string> $cteKeys */
    private function mdfeProcXml(string $accessKey, array $cteKeys, string $ciotNumber, string $protocol = '135260000000002'): string
    {
        $cteXml = implode('', array_map(
            fn (string $cteKey): string => '<infCTe><chCTe>'.$cteKey.'</chCTe></infCTe>',
            $cteKeys,
        ));

        return '<mdfeProc xmlns="http://www.portalfiscal.inf.br/mdfe">'
            .'<MDFe><infMDFe Id="MDFe'.$accessKey.'">'.$this->identityXml($accessKey, 'nMDF', 'cMDF')
            .'<emit><CNPJ>'.substr($accessKey, 6, 14).'</CNPJ></emit>'
            .'<infDoc><infMunDescarga>'.$cteXml.'</infMunDescarga></infDoc>'
            .'<infModal><rodo><infANTT><infCIOT><CIOT>'.$ciotNumber.'</CIOT></infCIOT></infANTT></rodo></infModal>'
            .'</infMDFe></MDFe><protMDFe><infProt><tpAmb>2</tpAmb><chMDFe>'.$accessKey.'</chMDFe>'
            .'<cStat>100</cStat><nProt>'.$protocol.'</nProt></infProt></protMDFe></mdfeProc>';
    }

    private function identityXml(string $accessKey, string $numberTag, string $codeTag): string
    {
        $issueDate = '20'.substr($accessKey, 2, 2).'-'.substr($accessKey, 4, 2).'-01T00:00:00-03:00';

        return '<ide><cUF>'.substr($accessKey, 0, 2).'</cUF>'
            .'<'.$codeTag.'>'.substr($accessKey, 35, 8).'</'.$codeTag.'>'
            .'<mod>'.substr($accessKey, 20, 2).'</mod>'
            .'<serie>'.(int) substr($accessKey, 22, 3).'</serie>'
            .'<'.$numberTag.'>'.(int) substr($accessKey, 25, 9).'</'.$numberTag.'>'
            .'<dhEmi>'.$issueDate.'</dhEmi>'
            .'<tpEmis>'.substr($accessKey, 34, 1).'</tpEmis>'
            .'<cDV>'.substr($accessKey, 43, 1).'</cDV><tpAmb>2</tpAmb></ide>';
    }
}
