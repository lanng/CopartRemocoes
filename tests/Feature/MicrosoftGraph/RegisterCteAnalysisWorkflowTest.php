<?php

namespace Tests\Feature\MicrosoftGraph;

use App\Models\CteDocument;
use App\Models\Register;
use App\Models\User;
use App\Services\Cte\MarkRegisterInvoicedForCte;
use App\Services\MicrosoftGraph\ProcessChecklistEmail;
use App\Services\MicrosoftGraph\ProcessCteErrorEmail;
use App\Services\MicrosoftGraph\ResolveCteErrorAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegisterCteAnalysisWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_error_delivery_reemission_and_duplicate_preserve_the_resolved_delivery(): void
    {
        $register = $this->registerWithCte();
        $error = app(ProcessCteErrorEmail::class)->handle($this->errorMessage('error-first'));
        app(ResolveCteErrorAnalysis::class)->handle($error, User::factory()->create(), 'acknowledge');

        app(ProcessChecklistEmail::class)->handle($this->deliveryMessage());
        $this->assertSame('analysis', $register->refresh()->status->value);
        $notes = $register->notes;

        $replacement = CteDocument::factory()->authorized()->create([
            'register_id' => $register->id, 'cte_number' => '3015', 'execution_mode' => 'live',
        ]);
        app(MarkRegisterInvoicedForCte::class)->handle($replacement);

        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertSame('2026-10-10 12:00:00', $register->delivery_confirmed_at->toDateTimeString());
        $this->assertNotNull($error->refresh()->resolved_at);

        $duplicate = app(ProcessCteErrorEmail::class)->handle($this->errorMessage('error-again'));

        $this->assertSame('duplicate', $duplicate->status);
        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertSame($notes, $register->notes);
    }

    public function test_false_positive_before_delivery_restores_the_normal_delivery_flow(): void
    {
        $register = $this->registerWithCte();
        $error = app(ProcessCteErrorEmail::class)->handle($this->errorMessage('error-first'));

        app(ResolveCteErrorAnalysis::class)->handle($error, User::factory()->create(), 'false_positive');
        $this->assertSame('invoiced', $register->refresh()->status->value);

        $delivery = app(ProcessChecklistEmail::class)->handle($this->deliveryMessage());

        $this->assertSame('processed', $delivery->status);
        $this->assertSame('delivered', $register->refresh()->status->value);
        $this->assertSame('2026-10-10 12:00:00', $register->delivery_confirmed_at->toDateTimeString());
    }

    private function registerWithCte(): Register
    {
        $register = Register::factory()->create([
            'status' => 'invoiced', 'vehicle_id' => '1146609', 'vehicle_plate' => 'ESN4A20',
        ]);
        CteDocument::factory()->authorized()->create([
            'register_id' => $register->id, 'cte_number' => '3014', 'execution_mode' => 'live',
        ]);

        return $register;
    }

    /** @return array<string, string> */
    private function errorMessage(string $externalId): array
    {
        return [
            'external_id' => $externalId, 'sender' => 'remocao@copart.com.br',
            'subject' => 'COPART - Erro no Documento Número 3014',
            'body' => 'Identificamos um problema na validação dos dados do documento de transporte número: 3014 - Mensagem de Erro: Comitente incorreto. Solicitamos o cancelamento do documento e a emissão de um novo.',
            'receivedDateTime' => '2026-10-09T12:00:00Z',
        ];
    }

    /** @return array<string, string> */
    private function deliveryMessage(): array
    {
        return [
            'external_id' => 'delivery', 'sender' => 'remocao@copart.com.br',
            'subject' => 'Checklist digital - 1146609',
            'body' => 'Segue anexo a checklist digital do veículo 1146609 - ESN4A20 - ALLIANZ SEGUROS S/A.',
            'receivedDateTime' => '2026-10-10T12:00:00Z',
        ];
    }
}
