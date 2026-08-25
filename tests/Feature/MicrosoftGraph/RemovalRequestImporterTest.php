<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\RemovalRequests\PreparedRemovalPdf;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestImporter;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfStorage;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RemovalRequestImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_complete_register_and_processes_the_inbox_item(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $register = Register::query()->sole();

            $this->assertSame($item->id, $result->id);
            $this->assertSame('processed', $result->status);
            $this->assertSame($register->id, $result->register_id);
            $this->assertNotNull($result->resolved_at);
            $this->assertSame('copart', $register->company->value);
            $this->assertSame('pending', $register->status->value);
            $this->assertSame('FIAT ARGO 1.3', $register->vehicle_model);
            $this->assertSame('ABC1D23', $register->vehicle_plate);
            $this->assertSame('São Paulo', $register->origin_city);
            $this->assertSame('Pirapora', $register->destination_city);
            $this->assertSame('2026-08-26', $register->deadline_withdraw->toDateString());
            $this->assertSame('2026-09-03', $register->deadline_delivery->toDateString());
            $this->assertSame('1156340', $register->vehicle_id);
            $this->assertSame('500.00', $register->value);
            $this->assertSame('43897.00', $register->fipe_value);
            $this->assertSame('ALLIANZ SEGUROS SA', $register->insurance);
            $this->assertSame('T691299', $register->payment_code);
            $this->assertSame('Telefones Origem: 11 99999 1111 / 11 98888 2222', $register->notes);
            $this->assertSame($pdf->sha256, $register->pdf_sha256);
            $this->assertNotNull($register->pdf_path);
            $this->assertTrue(Storage::disk('s3')->exists($register->pdf_path));
            $this->assertSame($pdf->extractedData, $result->extracted_data['pdf']);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_zero_fipe_creates_an_alert_without_resolving_the_item(): void
    {
        Storage::fake('s3');
        $item = $this->item(['body' => ['fipe_value' => '0,00']]);
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('alert', $result->status);
            $this->assertSame(['zero_fipe'], $result->alerts);
            $this->assertNull($result->resolved_at);
            $this->assertDatabaseCount('registers', 1);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    #[DataProvider('invalidImportProvider')]
    public function test_invalid_imports_are_pending_without_register_or_upload(
        array $changes,
        string $failureReason,
    ): void {
        Storage::fake('s3');
        $item = $this->item($changes);
        $pdf = $this->pdf($changes['pdf'] ?? []);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('pending', $result->status);
            $this->assertSame($failureReason, $result->failure_reason);
            $this->assertNull($result->register_id);
            $this->assertNull($result->resolved_at);
            $this->assertDatabaseCount('registers', 0);
            $this->assertSame($pdf->extractedData, $result->extracted_data['pdf']);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public static function invalidImportProvider(): array
    {
        return [
            'missing body vehicle id' => [['body' => ['vehicle_id' => null]], 'missing_body_fields'],
            'missing pdf model' => [['pdf' => ['vehicle_model' => null]], 'missing_pdf_fields'],
            'plate conflict' => [['pdf' => ['vehicle_plate' => 'ZZZ9999']], 'vehicle_plate_mismatch'],
            'body id conflict' => [['body' => ['vehicle_id' => '9999999']], 'vehicle_id_mismatch'],
            'insurance conflict' => [['pdf' => ['insurance' => 'TOKIO MARINE']], 'insurance_mismatch'],
            'destination conflict' => [['pdf' => ['destination_city' => 'Campinas']], 'destination_city_mismatch'],
            'deadline conflict' => [['pdf' => ['deadline_delivery' => '04/09/2026']], 'deadline_delivery_mismatch'],
            'invalid model length' => [['pdf' => ['vehicle_model' => str_repeat('X', 31)]], 'invalid_constraints'],
            'invalid date order' => [[
                'body' => ['deadline_withdraw' => '2026-09-04'],
                'pdf' => ['deadline_withdraw' => '04/09/2026'],
            ], 'invalid_constraints'],
            'invalid money' => [['body' => ['value' => 'not-money']], 'invalid_constraints'],
            'value exceeds database precision' => [['body' => ['value' => '10000.00']], 'invalid_constraints'],
            'fipe exceeds database precision' => [['body' => ['fipe_value' => '1000000.00']], 'invalid_constraints'],
            'invalid body date' => [['body' => ['deadline_delivery' => '31/02/2026']], 'invalid_constraints'],
            'invalid pdf date' => [['pdf' => ['deadline_delivery' => '31/02/2026']], 'invalid_constraints'],
        ];
    }

    #[DataProvider('moneyBoundaryProvider')]
    public function test_money_values_at_database_limits_are_imported(string $value, string $fipeValue): void
    {
        Storage::fake('s3');
        $item = $this->item(['body' => [
            'value' => $value,
            'fipe_value' => $fipeValue,
        ]]);
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('processed', $result->status);
            $this->assertSame($value, Register::query()->sole()->value);
            $this->assertSame($fipeValue, Register::query()->sole()->fipe_value);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public static function moneyBoundaryProvider(): array
    {
        return [
            'value maximum' => ['9999.99', '43897.00'],
            'fipe maximum' => ['500.00', '999999.99'],
        ];
    }

    public function test_it_rejects_partial_or_ambiguous_identity_without_mutation(): void
    {
        Storage::fake('s3');
        $first = Register::factory()->create(['vehicle_id' => '1156340', 'vehicle_plate' => 'OTHER01']);
        Register::factory()->create(['vehicle_id' => '999999', 'vehicle_plate' => 'ABC1D23']);
        $item = $this->item();
        $pdf = $this->pdf();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('identity_conflict', $result->failure_reason);
            $this->assertNull($result->register_id);
            $this->assertSame($first->vehicle_model, $first->refresh()->vehicle_model);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_same_hash_and_no_changes_is_a_no_op_without_upload(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'pdf_sha256' => $pdf->sha256,
            'notes' => 'Telefones Origem: 11 99999 1111 / 11 98888 2222',
        ]);
        $item = $this->item();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('no_changes', $result->status, (string) json_encode($result->proposed_changes));
            $this->assertSame($register->id, $result->register_id);
            $this->assertNotNull($result->resolved_at);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    #[DataProvider('pdfDifferenceProvider')]
    public function test_pdf_difference_requires_task7_update_and_persists_pdf_evidence(?string $currentHash): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora',
            'deadline_withdraw' => '2026-08-26',
            'deadline_delivery' => '2026-09-03',
            'value' => '500.00',
            'insurance' => 'ALLIANZ SEGUROS SA',
            'fipe_value' => '43897.00',
            'payment_code' => 'T691299',
            'notes' => 'Telefones Origem: 11 99999 1111 / 11 98888 2222',
            'pdf_path' => 'registros/copart/1156340/old/CartaDeRemoção ABC1D23.pdf',
            'pdf_sha256' => $currentHash,
        ]);
        $item = $this->item();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('update_required', $result->failure_reason);
            $this->assertSame('pending', $result->status);
            $this->assertSame($register->id, $result->register_id);
            $this->assertSame([
                'current' => [
                    'path' => $register->pdf_path,
                    'sha256' => $currentHash,
                ],
                'proposed' => [
                    'file_name' => $pdf->fileName,
                    'sha256' => $pdf->sha256,
                ],
            ], $result->proposed_changes['pdf_path']);
            $this->assertSame([], Storage::disk('s3')->allFiles());
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public static function pdfDifferenceProvider(): array
    {
        return [
            'different hash' => [str_repeat('a', 64)],
            'missing hash' => [null],
        ];
    }

    public function test_it_preserves_manual_notes_and_does_not_duplicate_phone_line(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();

        try {
            $pdf = $this->pdf(['origin_phones' => ['11 99999 1111']]);
            app(RemovalRequestImporter::class)->handle($item, $pdf);

            $register = Register::query()->sole();
            $this->assertSame('Telefones Origem: 11 99999 1111', $register->notes);
            $register->update(['notes' => "Manual note\nTelefones Origem: 11 99999 1111"]);
            $this->assertSame("Manual note\nTelefones Origem: 11 99999 1111", $register->refresh()->notes);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_existing_manual_notes_are_preserved_in_task7_proposed_changes(): void
    {
        Storage::fake('s3');
        $pdf = $this->pdf();
        $register = Register::factory()->create([
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'ABC1D23',
            'notes' => 'Manual note',
            'pdf_sha256' => str_repeat('a', 64),
        ]);
        $item = $this->item();

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('update_required', $result->failure_reason);
            $this->assertSame(
                "Manual note\nTelefones Origem: 11 99999 1111 / 11 98888 2222",
                $result->proposed_changes['notes']['proposed'],
            );
            $this->assertSame('Manual note', $register->refresh()->notes);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_a_database_failure_after_upload_deletes_the_new_pdf(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();
        $uploadedPath = 'registros/copart/1156340/new/CartaDeRemoção ABC1D23.pdf';
        $this->mock(RemovalRequestPdfStorage::class, function (MockInterface $mock) use ($uploadedPath): void {
            $mock->shouldReceive('store')->once()->andReturn($uploadedPath);
            $mock->shouldReceive('delete')->once()->with($uploadedPath);
        });
        Event::listen('eloquent.creating: '.Register::class, function (): void {
            throw new \RuntimeException('database failure');
        });

        try {
            $this->expectException(\RuntimeException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertDatabaseCount('registers', 0);
            $this->assertDatabaseHas('integration_inbox_items', [
                'id' => $item->id,
                'status' => 'queued',
            ]);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_cleanup_failure_reports_cleanup_and_preserves_the_database_error(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();
        $uploadedPath = 'registros/copart/1156340/new/CartaDeRemoção ABC1D23.pdf';
        $this->mock(RemovalRequestPdfStorage::class, function (MockInterface $mock) use ($uploadedPath): void {
            $mock->shouldReceive('store')->once()->andReturn($uploadedPath);
            $mock->shouldReceive('delete')->once()->with($uploadedPath)->andThrow(new RuntimeException('cleanup failed'));
        });
        Event::listen('eloquent.creating: '.Register::class, function (): void {
            throw new RuntimeException('database failure');
        });

        try {
            app(RemovalRequestImporter::class)->handle($item, $pdf);
            $this->fail('The database failure should be propagated.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cleanup failed', $exception->getMessage());
            $this->assertSame('database failure', $exception->getPrevious()?->getMessage());
        } finally {
            $this->assertDatabaseCount('registers', 0);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_it_uses_the_normalized_plate_in_the_import_lock_key(): void
    {
        Storage::fake('s3');
        $item = $this->item(['subject' => ['vehicle_plate' => 'ABC-1D23']]);
        $pdf = $this->pdf(['vehicle_plate' => 'ABC 1D23']);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andReturnUsing(fn (int $timeout, callable $callback): IntegrationInboxItem => $callback());
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:ABC1D23', 600)
            ->andReturn($lock);

        try {
            $result = app(RemovalRequestImporter::class)->handle($item, $pdf);

            $this->assertSame('processed', $result->status);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_an_occupied_plate_lock_fails_without_creating_a_duplicate(): void
    {
        Storage::fake('s3');
        $item = $this->item();
        $pdf = $this->pdf();
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:ABC1D23', 600)
            ->andReturn($lock);

        try {
            $this->expectException(LockTimeoutException::class);
            app(RemovalRequestImporter::class)->handle($item, $pdf);
        } finally {
            $this->assertDatabaseCount('registers', 0);
            $this->assertSame('queued', $item->refresh()->status);
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_different_plates_use_independent_import_locks(): void
    {
        Storage::fake('s3');
        $firstItem = $this->item();
        $firstPdf = $this->pdf();
        $secondItem = $this->item([
            'subject' => ['vehicle_plate' => 'XYZ-9876', 'vehicle_id' => '2222222'],
            'body' => ['vehicle_id' => '2222222'],
        ]);
        $secondPdf = $this->pdf([
            'vehicle_plate' => 'XYZ 9876',
            'vehicle_id' => '2222222',
        ]);
        $firstLock = Mockery::mock(Lock::class);
        $firstLock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andReturnUsing(fn (int $timeout, callable $callback): IntegrationInboxItem => $callback());
        $secondLock = Mockery::mock(Lock::class);
        $secondLock->shouldReceive('block')
            ->once()
            ->with(1, Mockery::type('callable'))
            ->andReturnUsing(fn (int $timeout, callable $callback): IntegrationInboxItem => $callback());
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:ABC1D23', 600)
            ->andReturn($firstLock);
        Cache::shouldReceive('lock')
            ->once()
            ->with('removal-request-import:plate:XYZ9876', 600)
            ->andReturn($secondLock);

        try {
            app(RemovalRequestImporter::class)->handle($firstItem, $firstPdf);
            app(RemovalRequestImporter::class)->handle($secondItem, $secondPdf);

            $this->assertDatabaseCount('registers', 2);
        } finally {
            @unlink($firstPdf->temporaryPath);
            @unlink($secondPdf->temporaryPath);
        }
    }

    private function item(array $overrides = []): IntegrationInboxItem
    {
        $data = [
            'subject' => [
                'vehicle_plate' => 'ABC1D23',
                'vehicle_id' => '1156340',
                'insurance' => 'ALLIANZ SEGUROS SA',
            ],
            'body' => [
                'vehicle_id' => '1156340',
                'insurance' => 'ALLIANZ SEGUROS S/A',
                'destination_city' => 'Pirapora',
                'deadline_withdraw' => '26/08/2026',
                'deadline_delivery' => '03/09/2026',
                'value' => 'R$ 500,00',
                'fipe_value' => 'R$ 43.897,00',
                'payment_code' => 'T691299',
            ],
            'body_missing_fields' => [],
        ];

        foreach (['subject', 'body'] as $source) {
            if (isset($overrides[$source])) {
                $data[$source] = array_replace($data[$source], $overrides[$source]);
            }
        }

        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'queued',
            'extracted_vehicle_id' => '1156340',
            'extracted_vehicle_plate' => 'ABC1D23',
            'extracted_data' => $data,
        ]);

        return $item;
    }

    private function pdf(array $overrides = []): PreparedRemovalPdf
    {
        $path = tempnam(sys_get_temp_dir(), 'removal_import_');
        $this->assertIsString($path);
        file_put_contents($path, '%PDF-import');

        $extractedData = array_replace([
            'vehicle_model' => 'FIAT ARGO 1.3',
            'vehicle_plate' => 'ABC1D23',
            'origin_city' => 'São Paulo',
            'destination_city' => 'Pirapora - SP',
            'deadline_withdraw' => '26/08/2026',
            'deadline_delivery' => '03/09/2026',
            'vehicle_id' => '1156340',
            'insurance' => 'ALLIANZ SEGUROS S/A',
            'origin_phones' => ['11 99999 1111', '11 98888 2222'],
        ], $overrides);

        return new PreparedRemovalPdf(
            temporaryPath: $path,
            sha256: hash('sha256', '%PDF-import'),
            fileName: 'CartaDeRemoção ABC1D23.pdf',
            extractedData: $extractedData,
        );
    }
}
