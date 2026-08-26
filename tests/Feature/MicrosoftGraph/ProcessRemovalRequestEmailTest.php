<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Jobs\ProcessRemovalRequestEmail;
use App\Models\IntegrationInboxItem;
use App\Models\MicrosoftGraphConnection;
use App\Services\MicrosoftGraph\MicrosoftGraphClient;
use App\Services\MicrosoftGraph\RemovalRequests\PreparedRemovalPdf;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestImporter;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfPreparer;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfStorage;
use App\Services\PdfExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ProcessRemovalRequestEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_job_has_unique_retry_and_timeout_options(): void
    {
        $job = new ProcessRemovalRequestEmail(42);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
        $this->assertSame('42', $job->uniqueId());
        $this->assertSame(3, $job->tries);
        $this->assertSame(120, $job->timeout);
        $this->assertSame(600, $job->uniqueFor);
        $this->assertSame([30, 120, 300], $job->backoff());
        $this->assertInstanceOf(WithoutOverlapping::class, $job->middleware()[0]);
    }

    public function test_it_prepares_imports_and_removes_the_temporary_file(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'queued',
            'external_id' => 'message-id',
            'extracted_vehicle_plate' => 'ABC1D23',
        ]);
        $pdf = new PreparedRemovalPdf(
            temporaryPath: $this->temporaryPdf('%PDF-job'),
            sha256: hash('sha256', '%PDF-job'),
            fileName: 'CartaDeRemoção ABC1D23.pdf',
            extractedData: [],
        );
        $connection = MicrosoftGraphConnection::factory()->create();
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock) use ($connection, $pdf): void {
            $mock->shouldReceive('prepare')->once()->withArgs(function (MicrosoftGraphConnection $actual, string $messageId, string $plate) use ($connection): bool {
                return $actual->is($connection) || $actual->id === $connection->id
                    ? $messageId === 'message-id' && $plate === 'ABC1D23'
                    : false;
            })->andReturn($pdf);
        });
        $this->mock(RemovalRequestImporter::class, function (MockInterface $mock) use ($item, $pdf): void {
            $mock->shouldReceive('handle')->once()->withArgs(function (IntegrationInboxItem $actualItem, PreparedRemovalPdf $actualPdf) use ($item, $pdf): bool {
                return $actualItem->is($item) && $actualPdf === $pdf;
            })->andReturn($item->refresh()->fill(['status' => 'processed']));
        });

        try {
            (new ProcessRemovalRequestEmail($item->id))->handle(
                app(RemovalRequestPdfPreparer::class),
                app(RemovalRequestImporter::class),
            );

            $this->assertFileDoesNotExist($pdf->temporaryPath);
            $this->assertSame('processing', $item->refresh()->status);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_domain_failures_become_safe_pending_and_cleanup_the_pdf(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'queued',
            'extracted_vehicle_plate' => 'ABC1D23',
        ]);
        MicrosoftGraphConnection::factory()->create();
        $pdf = new PreparedRemovalPdf($this->temporaryPdf('%PDF-domain'), 'hash', 'CartaDeRemoção ABC1D23.pdf', []);
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock) use ($pdf): void {
            $mock->shouldReceive('prepare')->andReturn($pdf);
        });
        $this->mock(RemovalRequestImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->andThrow(new \DomainException('unsafe body'));
        });

        (new ProcessRemovalRequestEmail($item->id))->handle(
            app(RemovalRequestPdfPreparer::class),
            app(RemovalRequestImporter::class),
        );

        $this->assertSame('pending', $item->refresh()->status);
        $this->assertSame('domain_error', $item->failure_reason);
        $this->assertFileDoesNotExist($pdf->temporaryPath);
    }

    public function test_transient_failures_are_rethrown_and_cleanup_the_pdf(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'queued',
            'extracted_vehicle_plate' => 'ABC1D23',
        ]);
        MicrosoftGraphConnection::factory()->create();
        $pdf = new PreparedRemovalPdf($this->temporaryPdf('%PDF-transient'), 'hash', 'CartaDeRemoção ABC1D23.pdf', []);
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock) use ($pdf): void {
            $mock->shouldReceive('prepare')->andReturn($pdf);
        });
        $this->mock(RemovalRequestImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->andThrow(new RuntimeException('storage unavailable'));
        });

        try {
            $this->expectException(RuntimeException::class);
            (new ProcessRemovalRequestEmail($item->id))->handle(
                app(RemovalRequestPdfPreparer::class),
                app(RemovalRequestImporter::class),
            );
        } finally {
            $this->assertFileDoesNotExist($pdf->temporaryPath);
        }
    }

    public function test_terminal_items_are_idempotent_and_failed_marks_processing_failed(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'processed',
        ]);
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->never();
        });
        $this->mock(RemovalRequestImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->never();
        });

        (new ProcessRemovalRequestEmail($item->id))->handle(
            app(RemovalRequestPdfPreparer::class),
            app(RemovalRequestImporter::class),
        );
        (new ProcessRemovalRequestEmail($item->id))->failed(new RuntimeException('secret body'));

        $this->assertSame('processed', $item->refresh()->status);
        $this->assertNull($item->failure_reason);
    }

    public function test_missing_graph_connection_becomes_pending_without_preparing_a_pdf(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'queued',
        ]);
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->never();
        });
        $this->mock(RemovalRequestImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('handle')->never();
        });

        (new ProcessRemovalRequestEmail($item->id))->handle(
            app(RemovalRequestPdfPreparer::class),
            app(RemovalRequestImporter::class),
        );

        $this->assertSame('pending', $item->refresh()->status);
        $this->assertSame('graph_connection_missing', $item->failure_reason);
    }

    public function test_failed_marks_a_non_terminal_item_with_a_safe_reason(): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'processing',
        ]);

        (new ProcessRemovalRequestEmail($item->id))->failed(new RuntimeException('secret body and token'));

        $this->assertSame('pending', $item->refresh()->status);
        $this->assertSame('processing_failed', $item->failure_reason);
        $this->assertStringNotContainsString('secret', (string) $item->failure_reason);
    }

    #[DataProvider('functionalStatusProvider')]
    public function test_failed_does_not_overwrite_an_item_resolved_by_another_worker(string $status, ?string $resolvedAt): void
    {
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => $status,
            'resolved_at' => $resolvedAt,
            'failure_reason' => 'existing_reason',
        ]);

        (new ProcessRemovalRequestEmail($item->id))->failed(new RuntimeException('late failure'));

        $this->assertSame($status, $item->refresh()->status);
        $this->assertSame('existing_reason', $item->failure_reason);
        $this->assertSame($resolvedAt, $item->resolved_at?->toDateTimeString());
    }

    public static function functionalStatusProvider(): array
    {
        return [
            'processed' => ['processed', null],
            'no changes' => ['no_changes', now()->toDateTimeString()],
            'alert' => ['alert', null],
            'resolved pending' => ['pending', now()->toDateTimeString()],
        ];
    }

    public function test_it_accepts_metadata_and_bytes_exactly_at_the_configured_limit(): void
    {
        $bytes = '%PDF-1.7';
        config(['services.removal_requests.max_pdf_bytes' => strlen($bytes)]);
        $this->fakeGraph([$this->attachment(['size' => strlen($bytes)])], $bytes);
        Process::fake(fn () => Process::result($this->fixtureText()));

        $connection = MicrosoftGraphConnection::factory()->create();
        $pdf = app(RemovalRequestPdfPreparer::class)->prepare($connection, 'message-id', ' fsg-5551 ');

        try {
            $this->assertSame('CartaDeRemoção FSG5551.pdf', $pdf->fileName);
            $this->assertSame(hash('sha256', $bytes), $pdf->sha256);
            $this->assertFileExists($pdf->temporaryPath);
            $this->assertSame('ABC1D23', $pdf->extractedData['vehicle_plate']);
            $this->assertSame('1156340', $pdf->extractedData['vehicle_id']);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_it_uses_the_ten_megabyte_default_and_documents_the_environment_value(): void
    {
        $envExample = file_get_contents(base_path('.env.example'));

        $this->assertSame(10 * 1024 * 1024, config('services.removal_requests.max_pdf_bytes'));
        $this->assertIsString($envExample);
        $this->assertStringContainsString('REMOVAL_REQUEST_PDF_MAX_BYTES=10485760', $envExample);
    }

    public function test_it_selects_only_a_non_inline_file_attachment_with_the_exact_pdf_name(): void
    {
        $bytes = '%PDF-selected';
        $this->fakeGraph([
            $this->attachment(['id' => 'inline', 'is_inline' => true]),
            $this->attachment(['id' => 'wrong-type', 'type' => 'itemAttachment']),
            $this->attachment(['id' => 'selected', 'type' => 'fileAttachment', 'size' => strlen($bytes)]),
        ], $bytes);
        Process::fake(fn () => Process::result($this->fixtureText()));

        $pdf = app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );

        try {
            Http::assertSent(function ($request): bool {
                return str_ends_with($request->url(), '/attachments/selected/$value');
            });
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    public function test_it_accepts_a_pdf_reported_as_an_octet_stream_by_graph(): void
    {
        $bytes = '%PDF-octet-stream';
        $this->fakeGraph([
            $this->attachment([
                'content_type' => 'application/octet-stream',
                'size' => strlen($bytes),
            ]),
        ], $bytes);
        Process::fake(fn () => Process::result($this->fixtureText()));

        $pdf = app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );

        try {
            $this->assertSame('CartaDeRemoção FSG5551.pdf', $pdf->fileName);
            $this->assertSame(hash('sha256', $bytes), $pdf->sha256);
        } finally {
            @unlink($pdf->temporaryPath);
        }
    }

    #[DataProvider('invalidAttachmentNameProvider')]
    public function test_it_rejects_an_attachment_with_a_name_that_is_not_exactly_the_required_unicode_name(string $name): void
    {
        $this->fakeGraph([$this->attachment(['name' => $name])], '%PDF-valid');

        $this->expectException(\DomainException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );
    }

    public static function invalidAttachmentNameProvider(): array
    {
        return [
            'case mismatch' => ['cartaderemoção.pdf'],
            'accent mismatch' => ['CartaDeRemocao.pdf'],
            'different extension' => ['CartaDeRemoção.PDF'],
        ];
    }

    public function test_it_rejects_missing_matching_attachments(): void
    {
        $this->fakeGraph([], '%PDF-valid');

        $this->expectException(\DomainException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );
    }

    public function test_it_rejects_multiple_matching_attachments(): void
    {
        $attachment = $this->attachment();
        $this->fakeGraph([$attachment, $attachment], '%PDF-valid');

        $this->expectException(\DomainException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );
    }

    #[DataProvider('invalidMetadataProvider')]
    public function test_it_rejects_invalid_attachment_metadata(array $metadata): void
    {
        config(['services.removal_requests.max_pdf_bytes' => 16]);
        $this->fakeGraph([$this->attachment($metadata)], '%PDF-valid');

        $this->expectException(\DomainException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );
    }

    public static function invalidMetadataProvider(): array
    {
        return [
            'wrong mime' => [['content_type' => 'application/zip']],
            'zero size' => [['size' => 0]],
            'over configured limit' => [['size' => 17]],
        ];
    }

    public function test_it_rejects_downloaded_bytes_over_the_configured_limit(): void
    {
        config(['services.removal_requests.max_pdf_bytes' => 16]);
        $bytes = '%PDF-'.str_repeat('x', 20);
        $this->fakeGraph([$this->attachment(['size' => 16])], $bytes);

        $this->expectException(\DomainException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );
    }

    #[DataProvider('invalidPdfBytesProvider')]
    public function test_it_rejects_empty_or_non_pdf_downloads(string $bytes): void
    {
        $this->fakeGraph([$this->attachment(['size' => max(1, strlen($bytes))])], $bytes);

        $this->expectException(\DomainException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );
    }

    public static function invalidPdfBytesProvider(): array
    {
        return [
            'empty' => [''],
            'wrong signature' => ['not a PDF'],
        ];
    }

    public function test_it_propagates_graph_http_errors(): void
    {
        Http::fake([
            'https://graph.microsoft.com/*' => Http::response(['error' => ['message' => 'unavailable']], 503),
        ]);

        $this->expectException(RequestException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            'FSG5551',
        );
    }

    #[DataProvider('invalidPlateProvider')]
    public function test_it_rejects_a_blank_or_unsafe_plate(string $plate): void
    {
        $this->fakeGraph([$this->attachment()], '%PDF-valid');

        $this->expectException(\DomainException::class);

        app(RemovalRequestPdfPreparer::class)->prepare(
            MicrosoftGraphConnection::factory()->create(),
            'message-id',
            $plate,
        );
    }

    public static function invalidPlateProvider(): array
    {
        return [
            'blank' => ['  '],
            'path traversal' => ['../FSG5551'],
        ];
    }

    public function test_it_removes_the_temporary_pdf_when_extraction_fails(): void
    {
        $bytes = '%PDF-valid';
        $this->fakeGraph([$this->attachment(['size' => strlen($bytes)])], $bytes);
        $this->mock(PdfExtractorService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('extractData')->once()->andThrow(new RuntimeException('extract failed'));
        });
        $before = glob(sys_get_temp_dir().'/removal_request_pdf_*') ?: [];

        $this->expectException(RuntimeException::class);
        try {
            app(RemovalRequestPdfPreparer::class)->prepare(
                MicrosoftGraphConnection::factory()->create(),
                'message-id',
                'FSG5551',
            );
        } finally {
            $after = glob(sys_get_temp_dir().'/removal_request_pdf_*') ?: [];
            $this->assertSame($before, $after);
        }
    }

    public function test_it_removes_the_temporary_pdf_when_streaming_fails(): void
    {
        $this->mock(MicrosoftGraphClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('listMessageAttachments')->once()->andReturn([$this->attachment()]);
            $mock->shouldReceive('downloadMessageAttachmentToPath')->once()->andThrow(new RuntimeException('stream failed'));
        });
        $before = glob(sys_get_temp_dir().'/removal_request_pdf_*') ?: [];

        $this->expectException(RuntimeException::class);
        try {
            app(RemovalRequestPdfPreparer::class)->prepare(
                MicrosoftGraphConnection::factory()->create(),
                'message-id',
                'FSG5551',
            );
        } finally {
            $after = glob(sys_get_temp_dir().'/removal_request_pdf_*') ?: [];
            $this->assertSame($before, $after);
        }
    }

    public function test_it_stores_the_pdf_under_a_safe_uuid_path_with_public_visibility(): void
    {
        Storage::fake('s3');
        $bytes = '%PDF-storage';
        $temporaryPath = $this->temporaryPdf($bytes);
        $pdf = new PreparedRemovalPdf(
            temporaryPath: $temporaryPath,
            sha256: hash('sha256', $bytes),
            fileName: 'CartaDeRemoção FSG5551.pdf',
            extractedData: [],
        );

        try {
            $path = app(RemovalRequestPdfStorage::class)->store($pdf, '1156340');

            $this->assertMatchesRegularExpression(
                '#^registros/copart/1156340/[0-9a-f-]{36}/CartaDeRemoção FSG5551\.pdf$#u',
                $path,
            );
            $this->assertSame($bytes, Storage::disk('s3')->get($path));
            $this->assertSame('public', Storage::disk('s3')->getVisibility($path));
            $this->assertFileExists($temporaryPath);
        } finally {
            @unlink($temporaryPath);
        }
    }

    #[DataProvider('invalidFileNameProvider')]
    public function test_it_rejects_file_names_that_do_not_match_the_normalized_removal_contract(string $fileName): void
    {
        Storage::fake('s3');
        $temporaryPath = $this->temporaryPdf('%PDF-storage');
        $pdf = new PreparedRemovalPdf($temporaryPath, 'hash', $fileName, []);

        try {
            $this->expectException(\DomainException::class);
            app(RemovalRequestPdfStorage::class)->store($pdf, '1156340');
        } finally {
            @unlink($temporaryPath);
        }
    }

    public static function invalidFileNameProvider(): array
    {
        return [
            'arbitrary name' => ['document.pdf'],
            'lowercase plate' => ['CartaDeRemoção fsg5551.pdf'],
            'eight character plate' => ['CartaDeRemoção FSG55511.pdf'],
            'plate separator' => ['CartaDeRemoção FSG-5551.pdf'],
            'path traversal' => ['../CartaDeRemoção FSG5551.pdf'],
            'embedded slash' => ['CartaDeRemoção FSG5551.pdf/other'],
        ];
    }

    public function test_it_rejects_malicious_or_blank_vehicle_ids(): void
    {
        Storage::fake('s3');
        $temporaryPath = $this->temporaryPdf('%PDF-storage');
        $pdf = new PreparedRemovalPdf($temporaryPath, 'hash', 'CartaDeRemoção FSG5551.pdf', []);

        try {
            foreach (['', ' ../1156340', '1156340/../../other', 'abc'] as $vehicleId) {
                try {
                    app(RemovalRequestPdfStorage::class)->store($pdf, $vehicleId);
                    $this->fail('The vehicle ID should be rejected: '.$vehicleId);
                } catch (\DomainException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function test_it_throws_when_the_temporary_file_is_missing(): void
    {
        $pdf = new PreparedRemovalPdf(
            temporaryPath: sys_get_temp_dir().'/missing-removal-request-pdf',
            sha256: 'hash',
            fileName: 'CartaDeRemoção FSG5551.pdf',
            extractedData: [],
        );

        $this->expectException(RuntimeException::class);

        app(RemovalRequestPdfStorage::class)->store($pdf, '1156340');
    }

    public function test_it_throws_when_s3_rejects_the_upload(): void
    {
        $temporaryPath = $this->temporaryPdf('%PDF-storage');
        $disk = $this->mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);
        $pdf = new PreparedRemovalPdf($temporaryPath, 'hash', 'CartaDeRemoção FSG5551.pdf', []);

        try {
            $this->expectException(RuntimeException::class);
            app(RemovalRequestPdfStorage::class)->store($pdf, '1156340');
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function test_it_closes_the_upload_stream_when_s3_throws(): void
    {
        $temporaryPath = $this->temporaryPdf('%PDF-storage');
        $capturedStream = null;
        $disk = $this->mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('put')
            ->once()
            ->withArgs(function (string $path, $stream, array $options) use (&$capturedStream): bool {
                $capturedStream = $stream;

                return $options === ['visibility' => 'public'];
            })
            ->andThrow(new RuntimeException('s3 failed'));
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);
        $pdf = new PreparedRemovalPdf($temporaryPath, 'hash', 'CartaDeRemoção FSG5551.pdf', []);

        try {
            $this->expectException(RuntimeException::class);
            app(RemovalRequestPdfStorage::class)->store($pdf, '1156340');
        } finally {
            $this->assertFalse(is_resource($capturedStream));
            $this->assertTrue(unlink($temporaryPath));
        }
    }

    public function test_it_deletes_a_stored_pdf_and_accepts_repeated_or_blank_deletes(): void
    {
        Storage::fake('s3');
        $bytes = '%PDF-storage';
        $temporaryPath = $this->temporaryPdf($bytes);
        $pdf = new PreparedRemovalPdf($temporaryPath, 'hash', 'CartaDeRemoção FSG5551.pdf', []);

        try {
            $storage = app(RemovalRequestPdfStorage::class);
            $path = $storage->store($pdf, '1156340');
            $storage->delete(null);
            $storage->delete('');
            $storage->delete($path);
            $storage->delete($path);

            $this->assertFalse(Storage::disk('s3')->exists($path));
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function test_delete_throws_when_s3_reports_failure(): void
    {
        $disk = $this->mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('delete')->once()->with('pdf/path.pdf')->andReturnFalse();
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

        $this->expectException(RuntimeException::class);
        app(RemovalRequestPdfStorage::class)->delete('pdf/path.pdf');
    }

    public function test_delete_propagates_s3_exceptions(): void
    {
        $disk = $this->mock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->shouldReceive('delete')->once()->with('pdf/path.pdf')->andThrow(new RuntimeException('delete failed'));
        Storage::shouldReceive('disk')->once()->with('s3')->andReturn($disk);

        $this->expectExceptionMessage('delete failed');
        app(RemovalRequestPdfStorage::class)->delete('pdf/path.pdf');
    }

    private function fakeGraph(array $attachments, string $bytes): void
    {
        $graphAttachments = array_map(fn (array $attachment): array => [
            '@odata.type' => $attachment['type'],
            'id' => $attachment['id'],
            'name' => $attachment['name'],
            'contentType' => $attachment['content_type'],
            'size' => $attachment['size'],
            'isInline' => $attachment['is_inline'],
        ], $attachments);

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/messages/*/attachments' => Http::response(['value' => $graphAttachments]),
            'https://graph.microsoft.com/v1.0/me/messages/*/attachments/*/$value' => Http::response($bytes),
        ]);
    }

    /** @return array{id: string, name: string, content_type: string, size: int, is_inline: bool, type: string} */
    private function attachment(array $overrides = []): array
    {
        return array_merge([
            'id' => 'attachment-id',
            'name' => 'CartaDeRemoção.pdf',
            'content_type' => 'application/pdf',
            'size' => 12,
            'is_inline' => false,
            'type' => '#microsoft.graph.fileAttachment',
        ], $overrides);
    }

    private function temporaryPdf(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'removal_request_pdf_');
        $this->assertIsString($path);
        file_put_contents($path, $bytes);

        return $path;
    }

    private function fixtureText(): string
    {
        $text = file_get_contents(__DIR__.'/../../Fixtures/Pdf/carta-de-remocao.txt');
        $this->assertIsString($text);

        return $text;
    }
}
