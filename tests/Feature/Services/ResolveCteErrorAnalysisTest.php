<?php

namespace Tests\Feature\Services;

use App\Models\IntegrationInboxItem;
use App\Models\Register;
use App\Models\User;
use App\Services\MicrosoftGraph\ResolveCteErrorAnalysis;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResolveCteErrorAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_recognizing_the_error_keeps_analysis_open(): void
    {
        $item = $this->analysis();
        $user = User::factory()->create();

        $result = app(ResolveCteErrorAnalysis::class)->handle($item, $user, 'acknowledge');

        $this->assertSame('analysis', $result->register->status->value);
        $this->assertSame($user->id, $result->acknowledged_by);
        $this->assertNotNull($result->acknowledged_at);
        $this->assertNull($result->resolved_at);
        $this->assertFalse($result->requiresUserAction());
    }

    public function test_a_false_positive_restores_the_previous_status(): void
    {
        $item = $this->analysis();
        $user = User::factory()->create();

        $result = app(ResolveCteErrorAnalysis::class)->handle($item, $user, 'false_positive');

        $this->assertSame('invoiced', $result->register->status->value);
        $this->assertSame($user->id, $result->resolved_by);
        $this->assertNotNull($result->resolved_at);
        $this->assertSame('cte_error_false_positive', $result->failure_reason);
    }

    public function test_a_false_positive_reuses_delivery_already_received(): void
    {
        $item = $this->analysis();
        $item->register->update(['delivery_confirmed_at' => '2026-10-10 12:00:00']);

        $result = app(ResolveCteErrorAnalysis::class)->handle($item, User::factory()->create(), 'false_positive');

        $this->assertSame('delivered', $result->register->status->value);
        $this->assertSame('2026-10-10 12:00:00', $result->register->delivery_confirmed_at->toDateTimeString());
    }

    public function test_a_recognized_error_can_later_be_discarded(): void
    {
        $item = $this->analysis();
        $user = User::factory()->create();
        $reviewer = User::factory()->create();
        $service = app(ResolveCteErrorAnalysis::class);
        $service->handle($item, $user, 'acknowledge');

        $result = $service->handle($item, $reviewer, 'false_positive');

        $this->assertSame('invoiced', $result->register->status->value);
        $this->assertSame($user->id, $result->acknowledged_by);
        $this->assertSame($reviewer->id, $result->resolved_by);
    }

    #[DataProvider('deliveryConfirmations')]
    public function test_discarding_one_notice_keeps_analysis_for_remaining_errors(?string $delivery): void
    {
        $item = $this->analysis();
        $user = User::factory()->create();
        $item->register->update(['delivery_confirmed_at' => $delivery]);
        $other = IntegrationInboxItem::factory()->create([
            'register_id' => $item->register_id, 'message_type' => 'cte_error', 'status' => 'alert',
            'previous_register_status' => 'invoiced', 'acknowledged_by' => $user->id, 'acknowledged_at' => now(),
            'extracted_data' => ['cte_number' => '3014', 'error_message' => 'Outro erro verdadeiro.'],
        ]);

        $result = app(ResolveCteErrorAnalysis::class)->handle($item, $user, 'false_positive');

        $this->assertSame('analysis', $result->register->status->value);
        $this->assertNotNull($result->resolved_at);
        $this->assertNull($other->refresh()->resolved_at);
        $this->assertSame('alert', $other->status);
    }

    /** @return array<string, array{?string}> */
    public static function deliveryConfirmations(): array
    {
        return ['without delivery' => [null], 'with delivery' => ['2026-10-10 12:00:00']];
    }

    public function test_resolved_occurrences_cannot_override_a_manual_decision(): void
    {
        $item = $this->analysis();
        $user = User::factory()->create();
        app(ResolveCteErrorAnalysis::class)->handle($item, $user, 'false_positive');
        $item->register->update(['status' => 'collected']);

        $this->expectException(DomainException::class);
        app(ResolveCteErrorAnalysis::class)->handle($item, $user, 'false_positive');
    }

    public function test_other_message_types_cannot_be_reviewed_as_cte_errors(): void
    {
        $item = IntegrationInboxItem::factory()->create();

        $this->expectException(DomainException::class);
        app(ResolveCteErrorAnalysis::class)->handle($item, User::factory()->create(), 'false_positive');
    }

    public function test_an_invalid_decision_does_not_modify_analysis(): void
    {
        $item = $this->analysis();

        $this->expectException(DomainException::class);
        app(ResolveCteErrorAnalysis::class)->handle($item, User::factory()->create(), 'invalid');
    }

    public function test_an_unassociated_notice_can_be_closed_after_manual_investigation(): void
    {
        $user = User::factory()->create();
        $register = Register::factory()->create(['status' => 'invoiced']);
        $item = IntegrationInboxItem::factory()->create([
            'message_type' => 'cte_error', 'status' => 'pending', 'failure_reason' => 'cte_not_found_or_ambiguous',
        ]);

        $result = app(ResolveCteErrorAnalysis::class)->handle($item, $user, 'manual_review');

        $this->assertSame('processed', $result->status);
        $this->assertNull($result->register_id);
        $this->assertSame($user->id, $result->resolved_by);
        $this->assertNotNull($result->resolved_at);
        $this->assertSame('invoiced', $register->refresh()->status->value);
        $this->assertFalse($result->requiresUserAction());
    }

    public function test_manual_investigation_cannot_bypass_an_associated_analysis(): void
    {
        $item = $this->analysis();

        $this->expectException(DomainException::class);
        app(ResolveCteErrorAnalysis::class)->handle($item, User::factory()->create(), 'manual_review');
    }

    private function analysis(): IntegrationInboxItem
    {
        $register = Register::factory()->create(['status' => 'analysis']);

        return IntegrationInboxItem::factory()->create([
            'register_id' => $register->id,
            'message_type' => 'cte_error',
            'status' => 'alert',
            'previous_register_status' => 'invoiced',
            'extracted_data' => ['cte_number' => '3014', 'error_message' => 'Comitente incorreto.'],
        ]);
    }
}
