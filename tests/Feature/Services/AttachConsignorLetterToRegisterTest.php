<?php

namespace Tests\Feature\Services;

use App\Models\IntegrationInboxItem;
use App\Models\MicrosoftGraphConnection;
use App\Models\Register;
use App\Services\MicrosoftGraph\RemovalRequests\AttachConsignorLetterToRegister;
use App\Services\MicrosoftGraph\RemovalRequests\PreparedConsignorLetter;
use App\Services\MicrosoftGraph\RemovalRequests\RemovalRequestPdfPreparer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class AttachConsignorLetterToRegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_attaches_the_first_consignor_letter_to_an_eligible_register(): void
    {
        Storage::fake('s3');
        $register = Register::factory()->create([
            'status' => 'pending',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'FSG5551',
        ]);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'processed',
            'external_id' => 'message-id',
            'extracted_vehicle_plate' => 'FSG5551',
            'register_id' => $register->id,
        ]);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'consignor_letter_pdf_');
        file_put_contents($temporaryPath, '%PDF-consignor');
        $letter = new PreparedConsignorLetter($temporaryPath, hash('sha256', '%PDF-consignor'), 'CartaDoComitente FSG5551.pdf');
        $connection = MicrosoftGraphConnection::factory()->create();
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock) use ($letter, $connection): void {
            $mock->shouldReceive('prepareConsignorLetter')->once()->with($connection, 'message-id', 'FSG5551')->andReturn($letter);
        });

        try {
            app(AttachConsignorLetterToRegister::class)->handle($item, $connection);

            $register = $register->refresh();
            $this->assertNotNull($register->consignor_letter_path);
            $this->assertSame($letter->sha256, $register->consignor_letter_sha256);
            $this->assertTrue(Storage::disk('s3')->exists($register->consignor_letter_path));
            $this->assertSame('processed', $item->refresh()->status);
        } finally {
            @unlink($temporaryPath);
        }
    }

    public function test_it_marks_the_integration_as_an_alert_when_the_optional_letter_fails(): void
    {
        $register = Register::factory()->create([
            'status' => 'pending',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'FSG5551',
        ]);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'processed',
            'register_id' => $register->id,
            'alerts' => ['zero_fipe'],
        ]);
        $connection = MicrosoftGraphConnection::factory()->create();
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareConsignorLetter')->once()->andThrow(new \RuntimeException('download failed'));
        });

        app(AttachConsignorLetterToRegister::class)->handle($item, $connection);

        $item->refresh();
        $this->assertSame('alert', $item->status);
        $this->assertSame(['zero_fipe', 'consignor_letter_failed'], $item->alerts);
        $this->assertNull($item->resolved_at);
    }

    public function test_it_does_not_download_a_later_letter_or_a_letter_for_a_blocked_register(): void
    {
        $connection = MicrosoftGraphConnection::factory()->create();
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareConsignorLetter')->never();
        });

        foreach (['delivered', 'paid', 'cancelled', 'available', 'pending daily rates', 'invoiced'] as $index => $status) {
            $register = Register::factory()->create([
                'status' => $status,
                'vehicle_id' => (string) (1156340 + $index),
                'vehicle_plate' => 'FSG5551',
                'consignor_letter_path' => null,
            ]);
            $item = IntegrationInboxItem::factory()->create([
                'message_type' => 'removal_request',
                'status' => 'processed',
                'external_id' => 'message-'.$status,
                'extracted_vehicle_plate' => 'FSG5551',
                'register_id' => $register->id,
            ]);

            app(AttachConsignorLetterToRegister::class)->handle($item, $connection);
        }
    }

    public function test_it_does_not_attach_when_the_main_import_is_still_pending(): void
    {
        $register = Register::factory()->create([
            'status' => 'pending',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'FSG5551',
        ]);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'pending',
            'register_id' => $register->id,
        ]);
        $connection = MicrosoftGraphConnection::factory()->create();
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareConsignorLetter')->never();
        });

        app(AttachConsignorLetterToRegister::class)->handle($item, $connection);

        $this->assertNull($register->refresh()->consignor_letter_path);
    }

    public function test_it_preserves_the_first_letter_without_downloading_a_later_version(): void
    {
        $register = Register::factory()->create([
            'status' => 'collected',
            'vehicle_id' => '1156340',
            'vehicle_plate' => 'FSG5551',
            'consignor_letter_path' => 'registros/copart/1156340/current/CartaDoComitente FSG5551.pdf',
            'consignor_letter_sha256' => str_repeat('a', 64),
        ]);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'removal_request',
            'status' => 'no_changes',
            'register_id' => $register->id,
        ]);
        $connection = MicrosoftGraphConnection::factory()->create();
        $this->mock(RemovalRequestPdfPreparer::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepareConsignorLetter')->never();
        });

        app(AttachConsignorLetterToRegister::class)->handle($item, $connection);

        $this->assertSame(
            'registros/copart/1156340/current/CartaDoComitente FSG5551.pdf',
            $register->refresh()->consignor_letter_path,
        );
    }
}
