<?php

namespace Tests\Feature\MicrosoftGraph;

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
            'status' => 'collected',
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
            'status' => 'collected',
        ]);
        $message = $this->message();
        $message['sender'] = ' REMOCAO@COPART.COM.BR ';
        $message['subject'] = '  CHECKLIST DIGITAL - 1146609  ';

        $result = app(ProcessChecklistEmail::class)->handle($message);

        $this->assertSame('processed', $result?->status);
        $this->assertSame('delivered', $register->refresh()->status->value);
    }

    public function test_temporary_personal_sender_is_accepted_for_integration_testing(): void
    {
        $register = Register::factory()->create([
            'vehicle_id' => '1146609',
            'vehicle_plate' => 'ESN4A20',
            'status' => 'invoiced',
        ]);
        $message = $this->message();
        $message['sender'] = 'victorlanguer@hotmail.com';

        $result = app(ProcessChecklistEmail::class)->handle($message);

        $this->assertSame('processed', $result?->status);
        $this->assertSame('delivered', $register->refresh()->status->value);
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
