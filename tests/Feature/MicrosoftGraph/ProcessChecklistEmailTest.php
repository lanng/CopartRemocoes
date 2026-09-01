<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Enums\CteDocumentStatusEnum;
use App\Models\CteDocument;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\ProcessChecklistEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProcessChecklistEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_email_confirms_the_first_delivery_and_marks_the_register_delivered(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'invoiced',
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertSame('processed', $result->status);
        $this->assertSame($register->id, $result->register_id);
        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertSame('2026-08-06 17:51:36', $register->delivery_confirmed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotNull($result->resolved_at);
    }

    public function test_a_repeated_message_is_deduplicated_and_does_not_change_the_first_date(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'invoiced',
        ]);

        app(ProcessChecklistEmail::class)->handle($this->message());
        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertSame('processed', $result->status);
        $this->assertSame(1, IntegrationInboxItem::query()->count());
        $this->assertSame('2026-08-06 17:51:36', $register->refresh()->delivery_confirmed_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_valid_format_with_a_plate_mismatch_becomes_a_pending_item(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'AAA1B23',
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertSame('pending', $result->status);
        $this->assertSame('vehicle_plate_mismatch', $result->failure_reason);
        $this->assertNull($result->previous_register_status);
        $this->assertNull($result->delivery_alert);
        $this->assertNull($result->authorized_cte_number_at_delivery);
        $this->assertNull($register->refresh()->delivery_confirmed_at);
    }

    public function test_irrelevant_messages_are_not_persisted(): void
    {
        $message = $this->message();
        $message['sender'] = 'other@example.com';

        $this->assertNull(app(ProcessChecklistEmail::class)->handle($message));
        $this->assertDatabaseCount('integration_inbox_items', 0);

        $message = $this->message();
        $message['external_id'] = 'AAMk-test-message-2';
        $message['subject'] = 'Pagamento recebido';

        $this->assertNull(app(ProcessChecklistEmail::class)->handle($message));
        $this->assertDatabaseCount('integration_inbox_items', 0);
    }

    public function test_filter_normalizes_sender_and_subject_case_and_spaces(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'invoiced',
        ]);
        $message = $this->message();
        $message['sender'] = ' REMOCAO@COPART.COM.BR ';
        $message['subject'] = '  CHECKLIST DIGITAL - 1146609  ';

        $result = app(ProcessChecklistEmail::class)->handle($message);

        $this->assertSame('processed', $result?->status);
        $this->assertSame('delivered', $register->refresh()->status->value);
    }

    public function test_sender_comparison_is_case_insensitive_for_integration_testing(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'invoiced',
        ]);
        $message = $this->message();
        $message['sender'] = ' REMOCAO@COPART.COM.BR ';

        $result = app(ProcessChecklistEmail::class)->handle($message);

        $this->assertSame('processed', $result?->status);
        $this->assertSame('delivered', $register->refresh()->status->value);
    }

    public function test_a_collected_register_with_an_authorized_cte_records_an_unexpected_status_snapshot(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);
        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '2670',
            'authorized_at' => Carbon::parse('2026-08-06 12:00:00'),
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertPendingDeliveryDecision($result, $register, 'unexpected_status');
        $this->assertSame('collected', $result->previous_register_status);
        $this->assertSame('2670', $result->authorized_cte_number_at_delivery);
    }

    public function test_a_collected_register_without_an_authorized_cte_records_a_missing_cte_alert(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertPendingDeliveryDecision($result, $register, 'missing_authorized_cte');
        $this->assertSame('collected', $result->previous_register_status);
        $this->assertNull($result->authorized_cte_number_at_delivery);
    }

    public function test_an_authorized_cte_with_a_blank_number_records_a_missing_cte_alert(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);
        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '',
            'authorized_at' => Carbon::parse('2026-08-06 12:00:00'),
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertPendingDeliveryDecision($result, $register, 'missing_authorized_cte');
        $this->assertSame('collected', $result->previous_register_status);
        $this->assertNull($result->authorized_cte_number_at_delivery);
    }

    public function test_a_queued_cte_with_a_number_does_not_count_as_authorized(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);
        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::QUEUED,
            'cte_number' => '2670',
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertPendingDeliveryDecision($result, $register, 'missing_authorized_cte');
        $this->assertNull($result->authorized_cte_number_at_delivery);
    }

    public function test_an_authorized_cte_without_a_number_does_not_count_as_authorized(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);
        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => null,
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertPendingDeliveryDecision($result, $register, 'missing_authorized_cte');
        $this->assertNull($result->authorized_cte_number_at_delivery);
    }

    public function test_multiple_authorized_ctes_use_the_latest_authorized_date_and_id(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);
        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '1000',
            'authorized_at' => Carbon::parse('2026-08-04 12:00:00'),
        ]);
        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '2000',
            'authorized_at' => Carbon::parse('2026-08-06 12:00:00'),
        ]);
        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '3000',
            'authorized_at' => Carbon::parse('2026-08-06 12:00:00'),
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertPendingDeliveryDecision($result, $register, 'unexpected_status');
        $this->assertSame('3000', $result->authorized_cte_number_at_delivery);
    }

    public function test_an_invoiced_register_without_a_cte_has_no_delivery_alert(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'invoiced',
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertProcessedDelivery($result, $register);
        $this->assertSame('invoiced', $result->previous_register_status);
        $this->assertNull($result->delivery_alert);
        $this->assertNull($result->authorized_cte_number_at_delivery);
    }

    public function test_a_delivered_register_without_confirmation_or_cte_has_no_delivery_alert(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'delivered',
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());

        $this->assertProcessedDelivery($result, $register);
        $this->assertSame('delivered', $result->previous_register_status);
        $this->assertNull($result->delivery_alert);
        $this->assertNull($result->authorized_cte_number_at_delivery);
    }

    public function test_creating_an_authorized_cte_after_a_critical_delivery_does_not_change_its_snapshot(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'collected',
        ]);

        $result = app(ProcessChecklistEmail::class)->handle($this->message());
        $this->assertPendingDeliveryDecision($result, $register, 'missing_authorized_cte');

        CteDocument::factory()->create([
            'register_id' => $register->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '2670',
            'authorized_at' => Carbon::parse('2026-08-07 12:00:00'),
        ]);

        $item = $result->refresh();

        $this->assertSame('collected', $item->previous_register_status);
        $this->assertSame('missing_authorized_cte', $item->delivery_alert);
        $this->assertNull($item->authorized_cte_number_at_delivery);
    }

    public function test_a_duplicate_delivery_sets_its_resolution_timestamp(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'delivery_confirmed_at' => Carbon::parse('2026-08-06 17:51:36'),
        ]);
        $message = $this->message();
        $message['external_id'] = 'AAMk-test-message-duplicate';

        $result = app(ProcessChecklistEmail::class)->handle($message);

        $this->assertSame('duplicate', $result?->status);
        $this->assertNotNull($result?->resolved_at);
        $this->assertNull($result?->previous_register_status);
        $this->assertNull($result?->delivery_alert);
        $this->assertNull($result?->authorized_cte_number_at_delivery);
    }

    private function assertProcessedDelivery(IntegrationInboxItem $item, Register $register): void
    {
        $this->assertSame('processed', $item->status);
        $this->assertSame($register->id, $item->register_id);
        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertSame('2026-08-06 17:51:36', $register->delivery_confirmed_at->utc()->format('Y-m-d H:i:s'));
    }

    private function assertPendingDeliveryDecision(IntegrationInboxItem $item, Register $register, string $alert): void
    {
        $this->assertSame('pending', $item->status);
        $this->assertSame($register->id, $item->register_id);
        $this->assertSame($alert, $item->delivery_alert);
        $this->assertNull($item->resolved_at);
        $this->assertSame($register->status->value, $register->refresh()->status->value);
        $this->assertNull($register->delivery_confirmed_at);
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'external_id' => 'AAMk-test-message-1',
            'sender' => 'remocao@copart.com.br',
            'subject' => 'Checklist digital - 1146609',
            'body' => 'Segue anexo a checklist digital do veículo 1146609 - ESN4A20 - ALLIANZ SEGUROS S/A.',
            'receivedDateTime' => '2026-08-06T14:51:36-03:00',
        ];
    }
}
