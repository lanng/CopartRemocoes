<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Models\MicrosoftGraphConnection;
use App\Services\MicrosoftGraph\RemovalRequests\PreparedRemovalPdf;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfPreparer;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfStorage;
use App\Services\PdfExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
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

    public function test_it_prepares_a_pdf_with_extracted_data_hash_and_normalized_filename(): void
    {
        config(['services.removal_requests.max_pdf_bytes' => 16]);
        $bytes = '%PDF-1.7';
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
            'wrong mime' => [['content_type' => 'application/octet-stream']],
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
        } finally {
            @unlink($temporaryPath);
        }
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
