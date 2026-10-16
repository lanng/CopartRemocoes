<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\CteDocument;
use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Services\MicrosoftGraph\ProcessCteErrorEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProcessCteErrorEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_links_the_exact_emitted_cte_and_appends_the_excerpt_preserving_delivery_date(): void
    {
        $register = Register::factory()->create([
            'status' => 'delivered',
            'notes' => 'Observação existente.',
            'delivery_confirmed_at' => '2026-08-01 12:30:00',
        ]);
        $document = $this->document($register);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('cte_error', $item->message_type);
        $this->assertSame('alert', $item->status);
        $this->assertSame($register->id, $item->register_id);
        $this->assertSame($register->vehicle_id, $item->extracted_vehicle_id);
        $this->assertSame($register->vehicle_plate, $item->extracted_vehicle_plate);
        $this->assertSame($document->id, $item->extracted_data['cte_document_id']);
        $this->assertSame('3014', $item->extracted_data['cte_number']);
        $this->assertSame('Comitente inválido.', $item->extracted_data['error_message']);
        $this->assertSame(hash('sha256', 'comitente invalido.'), $item->extracted_data['error_fingerprint']);
        $this->assertSame('delivered', $item->previous_register_status);
        $this->assertNull($item->resolved_at);
        $this->assertNull($item->failure_reason);
        $this->assertSame(RegisterStatusEnum::ANALYSIS, $register->refresh()->status);
        $this->assertSame('2026-08-01 12:30:00', $register->delivery_confirmed_at->format('Y-m-d H:i:s'));
        $this->assertSame("Observação existente.\n\nIdentificamos um problema na validação dos dados do documento de transporte número:\n\n3014\n- Mensagem de Erro:\n\nComitente inválido.", $register->notes);
    }

    public function test_the_same_external_id_returns_the_existing_occurrence_without_appending_notes(): void
    {
        $register = Register::factory()->create(['status' => 'invoiced']);
        $this->document($register);
        $service = app(ProcessCteErrorEmail::class);
        $first = $service->handle($this->message());
        $notes = $register->refresh()->notes;

        $second = $service->handle($this->message());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($notes, $register->refresh()->notes);
        $this->assertDatabaseCount('integration_inbox_items', 1);
    }

    public function test_it_processes_an_excerpt_only_email_without_a_cancellation_footer(): void
    {
        $register = Register::factory()->create(['status' => 'invoiced']);
        $this->document($register);
        $message = $this->message();
        $message['body'] = 'Documento de transporte número: 3014 - Mensagem de Erro: Frete divergente.';

        $item = app(ProcessCteErrorEmail::class)->handle($message);

        $this->assertSame('alert', $item->status);
        $this->assertSame('Frete divergente.', $item->extracted_data['error_message']);
        $this->assertSame(RegisterStatusEnum::ANALYSIS, $register->refresh()->status);
        $this->assertStringEndsWith('Mensagem de Erro:'."\n\n".'Frete divergente.', $register->notes);
    }

    #[DataProvider('resolvedProvider')]
    public function test_a_repeated_error_is_resolved_as_duplicate_without_mutating_the_register(bool $resolved): void
    {
        $register = Register::factory()->create(['status' => 'invoiced']);
        $document = $this->document($register);
        $service = app(ProcessCteErrorEmail::class);
        $first = $service->handle($this->message());
        if ($resolved) {
            $first->update(['status' => 'processed', 'resolved_at' => now()]);
            $register->update(['status' => 'invoiced']);
            $document->update(['status' => CteDocumentStatusEnum::CANCELLED]);
        }
        $notes = $register->refresh()->notes;
        $status = $register->status;
        $message = $this->message();
        $message['external_id'] = 'new-external-id';
        $message['body'] = 'Documento de transporte numero 3014. Mensagem de Erro: COMITENTE   INVALIDO. Solicitamos o cancelamento do documento.';

        $second = $service->handle($message);

        $this->assertSame('duplicate', $second->status);
        $this->assertSame($first->id, $second->extracted_data['duplicate_of']);
        $this->assertNotNull($second->resolved_at);
        $this->assertNull($second->previous_register_status);
        $this->assertSame($notes, $register->refresh()->notes);
        $this->assertSame($status, $register->status);
    }

    public static function resolvedProvider(): array
    {
        return ['open' => [false], 'resolved' => [true]];
    }

    public function test_a_different_error_preserves_the_original_status_during_analysis(): void
    {
        $register = Register::factory()->create(['status' => 'invoiced']);
        $this->document($register);
        $service = app(ProcessCteErrorEmail::class);
        $service->handle($this->message());
        $message = $this->message();
        $message['external_id'] = 'different-error';
        $message['body'] = str_replace('Comitente inválido.', 'Valor do frete divergente.', $message['body']);

        $second = $service->handle($message);

        $this->assertSame('alert', $second->status);
        $this->assertSame('invoiced', $second->previous_register_status);
        $this->assertStringContainsString('Comitente inválido.', $register->refresh()->notes);
        $this->assertStringContainsString('Valor do frete divergente.', $register->notes);
    }

    public function test_an_existing_unresolved_checklist_preserves_the_first_status_snapshot(): void
    {
        $register = Register::factory()->create(['status' => 'analysis']);
        $this->document($register);
        IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'checklist',
            'status' => 'pending',
            'previous_register_status' => 'collected',
            'delivery_alert' => 'missing_authorized_cte',
            'resolved_at' => null,
        ]);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('collected', $item->previous_register_status);
    }

    #[DataProvider('incompatibleDocumentProvider')]
    public function test_it_keeps_unknown_or_incompatible_documents_pending(array $attributes): void
    {
        $register = Register::factory()->create(['status' => 'invoiced', 'notes' => 'Antes']);
        $this->document($register, $attributes);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('pending', $item->status);
        $this->assertSame('cte_not_found_or_ambiguous', $item->failure_reason);
        $this->assertNull($item->register_id);
        $this->assertSame('invoiced', $register->refresh()->status->value);
        $this->assertSame('Antes', $register->notes);
    }

    public static function incompatibleDocumentProvider(): array
    {
        return [
            'different number' => [['cte_number' => '30140']],
            'dry run' => [['execution_mode' => 'dry_run']],
            'not emitted' => [['status' => 'queued', 'authorized_at' => null]],
        ];
    }

    public function test_a_missing_document_is_pending_without_guessing_by_vehicle_information(): void
    {
        $register = Register::factory()->create(['vehicle_id' => '3014', 'status' => 'invoiced']);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('pending', $item->status);
        $this->assertNull($item->register_id);
        $this->assertSame('invoiced', $register->refresh()->status->value);
    }

    public function test_multiple_emitted_documents_with_the_same_number_are_ambiguous_even_on_one_register(): void
    {
        $register = Register::factory()->create(['status' => 'invoiced']);
        $this->document($register);
        $this->document($register);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('pending', $item->status);
        $this->assertSame('cte_not_found_or_ambiguous', $item->failure_reason);
        $this->assertSame('invoiced', $register->refresh()->status->value);
    }

    public function test_it_does_not_match_documents_from_other_companies(): void
    {
        $register = Register::factory()->create(['company' => 'millan', 'status' => 'invoiced']);
        $this->document($register);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('pending', $item->status);
        $this->assertNull($item->register_id);
    }

    public function test_an_emitted_document_without_an_associated_register_is_pending(): void
    {
        CteDocument::factory()->authorized()->create([
            'register_id' => null,
            'cte_number' => '3014',
            'execution_mode' => 'production',
        ]);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('pending', $item->status);
        $this->assertSame('cte_not_found_or_ambiguous', $item->failure_reason);
        $this->assertNull($item->register_id);
    }

    public function test_a_historical_superseded_document_with_authorization_evidence_still_matches(): void
    {
        $register = Register::factory()->create(['status' => 'invoiced']);
        $document = $this->document($register, ['status' => CteDocumentStatusEnum::SUPERSEDED]);
        $this->document($register, ['cte_number' => '3015']);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('alert', $item->status);
        $this->assertSame($document->id, $item->extracted_data['cte_document_id']);
    }

    public function test_it_keeps_recognizable_messages_with_malformed_subject_numbers_pending(): void
    {
        $message = $this->message();
        $message['subject'] = 'COPART - Erro no Documento Número inválido';

        $item = app(ProcessCteErrorEmail::class)->handle($message);

        $this->assertSame('pending', $item->status);
        $this->assertSame('invalid_subject', $item->failure_reason);
    }

    public function test_resolved_prior_analyses_do_not_supply_the_snapshot_for_a_new_analysis(): void
    {
        $register = Register::factory()->create(['status' => 'invoiced']);
        $this->document($register);
        IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'cte_error',
            'status' => 'processed',
            'previous_register_status' => 'collected',
            'resolved_at' => now(),
        ]);

        $item = app(ProcessCteErrorEmail::class)->handle($this->message());

        $this->assertSame('invoiced', $item->previous_register_status);
    }

    public function test_invalid_body_and_mismatched_numbers_are_kept_for_review(): void
    {
        $service = app(ProcessCteErrorEmail::class);
        foreach (['Corpo inválido.' => 'invalid_body', 'Documento de transporte número 3015. Mensagem de Erro: Falha. Solicitamos o cancelamento.' => 'cte_number_mismatch'] as $body => $reason) {
            $message = $this->message();
            $message['external_id'] = $reason;
            $message['body'] = $body;

            $item = $service->handle($message);

            $this->assertSame('pending', $item->status);
            $this->assertSame($reason, $item->failure_reason);
        }
    }

    public function test_untrusted_senders_and_unrelated_subjects_are_ignored(): void
    {
        $service = app(ProcessCteErrorEmail::class);
        $message = $this->message();
        $message['sender'] = 'other@example.com';
        $this->assertNull($service->handle($message));
        $message = $this->message();
        $message['subject'] = 'Outro assunto';
        $this->assertNull($service->handle($message));
        $this->assertDatabaseCount('integration_inbox_items', 0);
    }

    /** @param array<string, mixed> $attributes */
    private function document(Register $register, array $attributes = []): CteDocument
    {
        return CteDocument::factory()->authorized()->create(array_replace([
            'register_id' => $register->id,
            'cte_number' => '3014',
            'execution_mode' => 'production',
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function message(): array
    {
        return [
            'external_id' => 'cte-error-message',
            'sender' => 'remocao@copart.com.br',
            'subject' => 'COPART - Erro no Documento Número 3014',
            'body' => 'Identificamos um problema na validação dos dados do documento de transporte número: 3014 - Mensagem de Erro: Comitente inválido. Solicitamos o cancelamento do documento e nova emissão.',
            'receivedDateTime' => '2026-08-13T17:52:00-03:00',
        ];
    }
}
