<?php

namespace Tests\Feature\Services\Dfe;

use App\Enums\CteDocumentStatusEnum;
use App\Models\Ciot;
use App\Models\CteDocument;
use App\Models\DfeDocumentReservation;
use App\Models\MdfeDocument;
use App\Services\Dfe\ReserveMdfeDocuments;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReserveMdfeDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reserves_each_cte_and_the_ciot_once_for_the_same_mdfe(): void
    {
        $first = $this->authorizedCte();
        $second = $this->authorizedCte();
        $ciot = Ciot::factory()->issued()->create();
        $mdfe = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $mdfe->cteDocuments()->attach([$first->id, $second->id]);
        $service = app(ReserveMdfeDocuments::class);

        $service->reserve($mdfe, new Collection([$second, $first]), $ciot);
        $service->reserve($mdfe, new Collection([$first, $second]), $ciot);

        $this->assertDatabaseCount('dfe_document_reservations', 3);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $first->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $second->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);
    }

    public function test_an_overlapping_selection_fails_without_partially_reserving_other_ctes(): void
    {
        $shared = $this->authorizedCte();
        $unreserved = $this->authorizedCte();
        $firstCiot = Ciot::factory()->issued()->create();
        $firstMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $firstCiot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $firstMdfe->cteDocuments()->attach([$shared->id]);
        $service = app(ReserveMdfeDocuments::class);
        $service->reserve($firstMdfe, new Collection([$shared]), $firstCiot);

        $secondCiot = Ciot::factory()->issued()->create();
        $secondMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $secondCiot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $secondMdfe->cteDocuments()->attach([$unreserved->id, $shared->id]);

        try {
            $service->reserve($secondMdfe, new Collection([$unreserved, $shared]), $secondCiot);
            $this->fail('A CT-e reservado não pode pertencer a dois MDF-es ativos.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('reservado', mb_strtolower($exception->getMessage()));
        }

        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $unreserved->id,
        ]);
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $secondCiot->id,
        ]);
    }

    public function test_an_open_ciot_cannot_be_reserved_by_two_active_manifests(): void
    {
        $firstCte = $this->authorizedCte();
        $secondCte = $this->authorizedCte();
        $ciot = Ciot::factory()->issued()->create();
        $firstMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $firstMdfe->cteDocuments()->attach([$firstCte->id]);
        $service = app(ReserveMdfeDocuments::class);
        $service->reserve($firstMdfe, new Collection([$firstCte]), $ciot);

        $secondMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $secondMdfe->cteDocuments()->attach([$secondCte->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('CIOT');

        $service->reserve($secondMdfe, new Collection([$secondCte]), $ciot);
    }

    public function test_old_reservations_do_not_expire_automatically(): void
    {
        $cte = $this->authorizedCte();
        $firstCiot = Ciot::factory()->issued()->create();
        $firstMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $firstCiot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $firstMdfe->cteDocuments()->attach([$cte->id]);
        $service = app(ReserveMdfeDocuments::class);
        $service->reserve($firstMdfe, new Collection([$cte]), $firstCiot);

        DfeDocumentReservation::query()->update([
            'created_at' => now()->subDays(5),
            'updated_at' => now()->subDays(5),
        ]);

        $secondCiot = Ciot::factory()->issued()->create();
        $secondMdfe = MdfeDocument::factory()->create([
            'ciot_id' => $secondCiot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $secondMdfe->cteDocuments()->attach([$cte->id]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('reservado');

        $service->reserve($secondMdfe, new Collection([$cte]), $secondCiot);
    }

    public function test_only_terminal_mdfe_results_release_reservations(): void
    {
        $cte = $this->authorizedCte();
        $ciot = Ciot::factory()->issued()->create();
        $mdfe = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::QUEUED,
        ]);
        $mdfe->cteDocuments()->attach([$cte->id]);
        $service = app(ReserveMdfeDocuments::class);
        $service->reserve($mdfe, new Collection([$cte]), $ciot);

        $this->assertSame(0, $service->releaseIfTerminal($mdfe));

        $mdfe->forceFill([
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => 'authorized',
        ])->save();
        $this->assertSame(0, $service->releaseIfTerminal($mdfe));

        $mdfe->forceFill(['status' => CteDocumentStatusEnum::RECONCILIATION_REQUIRED])->save();
        $this->assertSame(0, $service->releaseIfTerminal($mdfe));
        $this->assertDatabaseCount('dfe_document_reservations', 2);

        $mdfe->forceFill([
            'status' => CteDocumentStatusEnum::REJECTED,
            'fiscal_status' => 'not_authorized',
        ])->save();
        $this->assertSame(2, $service->releaseIfTerminal($mdfe));
        $this->assertDatabaseCount('dfe_document_reservations', 0);
    }

    public function test_confirmed_mdfe_closure_releases_ctes_but_keeps_the_ciot_reserved_until_its_own_close(): void
    {
        $cte = $this->authorizedCte();
        $ciot = Ciot::factory()->issued()->create();
        $mdfe = MdfeDocument::factory()->create([
            'ciot_id' => $ciot->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'fiscal_status' => 'authorized',
        ]);
        $mdfe->cteDocuments()->attach([$cte->id]);
        $service = app(ReserveMdfeDocuments::class);
        $service->reserve($mdfe, new Collection([$cte]), $ciot);

        $mdfe->forceFill(['fiscal_status' => 'closed'])->save();

        $this->assertSame(1, $service->releaseIfTerminal($mdfe));
        $this->assertDatabaseMissing('dfe_document_reservations', [
            'resource_type' => 'cte',
            'resource_id' => $cte->id,
        ]);
        $this->assertDatabaseHas('dfe_document_reservations', [
            'resource_type' => 'ciot',
            'resource_id' => $ciot->id,
            'mdfe_document_id' => $mdfe->id,
        ]);

        $ciot->forceFill(['status' => 'closed'])->save();

        $this->assertSame(1, $service->releaseCiotIfTerminal($ciot));
        $this->assertDatabaseCount('dfe_document_reservations', 0);
    }

    private function authorizedCte(): CteDocument
    {
        return CteDocument::factory()->authorized()->create([
            'workflow_origin' => 'controlled_v1',
        ]);
    }
}
