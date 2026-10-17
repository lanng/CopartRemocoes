<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\CteDocument;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchRun;
use App\Models\Register;
use App\Services\Cte\MarkRegisterInvoicedForCte;
use App\Services\Payments\CreatePaymentBatchForWindow;
use App\Services\Payments\PaymentBatchWindow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatePaymentBatchForWindowTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_frozen_batch_for_eligible_deliveries(): void
    {
        $eligible = Register::factory()->create([
            'company' => 'copart',
            'status' => RegisterStatusEnum::DELIVERED,
            'value' => '123.45',
            'vehicle_plate' => 'ABC1D23',
            'delivery_confirmed_at' => CarbonImmutable::parse('2026-08-08 12:00:00', 'America/Sao_Paulo'),
        ]);
        CteDocument::factory()->create([
            'register_id' => $eligible->id,
            'status' => CteDocumentStatusEnum::AUTHORIZED,
            'cte_number' => '9001',
            'authorized_at' => CarbonImmutable::parse('2026-08-09 12:00:00', 'America/Sao_Paulo'),
        ]);
        Register::factory()->create([
            'status' => RegisterStatusEnum::COLLECTED,
            'delivery_confirmed_at' => CarbonImmutable::parse('2026-08-08 12:00:00', 'America/Sao_Paulo'),
        ]);
        Register::factory()->create([
            'company' => 'millan',
            'status' => RegisterStatusEnum::DELIVERED,
            'delivery_confirmed_at' => CarbonImmutable::parse('2026-08-08 12:00:00', 'America/Sao_Paulo'),
        ]);

        $batch = app(CreatePaymentBatchForWindow::class)->handle($this->window());

        $this->assertSame('123.45', $batch?->total_amount);
        $this->assertSame(1, $batch?->items()->count());
        $this->assertSame('9001', $batch?->items()->first()->cte_number);
        $this->assertSame($eligible->vehicle_plate, $batch?->items()->first()->vehicle_plate);
        $this->assertSame('created', PaymentBatchRun::first()->result);
    }

    public function test_an_empty_window_creates_a_checkpoint_without_a_batch(): void
    {
        $batch = app(CreatePaymentBatchForWindow::class)->handle($this->window());

        $this->assertNull($batch);
        $this->assertSame('empty', PaymentBatchRun::first()->result);
        $this->assertSame(0, PaymentBatch::query()->count());
    }

    public function test_analysis_never_enters_payment_batches_even_with_delivery_and_deferral(): void
    {
        Register::factory()->create([
            'status' => RegisterStatusEnum::ANALYSIS,
            'delivery_confirmed_at' => '2026-08-08 12:00:00',
            'payment_deferred_at' => '2026-08-20 12:00:00',
        ]);

        $this->assertNull(app(CreatePaymentBatchForWindow::class)->handle($this->window()));
        $this->assertDatabaseCount('payment_batch_items', 0);
    }

    public function test_late_cte_authorization_keeps_the_original_delivery_window_for_payment(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::ANALYSIS, 'delivery_confirmed_at' => '2026-08-08 12:00:00',
        ]);
        $document = CteDocument::factory()->authorized()->create([
            'register_id' => $register->id, 'cte_number' => '3015', 'authorized_at' => '2026-08-20 12:00:00',
        ]);
        app(MarkRegisterInvoicedForCte::class)->handle($document);

        $batch = app(CreatePaymentBatchForWindow::class)->handle($this->window());

        $this->assertSame(RegisterStatusEnum::DELIVERED, $register->refresh()->status);
        $this->assertSame('2026-08-08 12:00:00', $batch->items()->first()->delivery_confirmed_at->toDateTimeString());
        $this->assertSame('3015', $batch->items()->first()->cte_number);
    }

    public function test_a_window_processed_during_analysis_is_not_automatically_reopened(): void
    {
        $register = Register::factory()->create([
            'status' => RegisterStatusEnum::ANALYSIS, 'delivery_confirmed_at' => '2026-08-08 12:00:00',
        ]);
        $service = app(CreatePaymentBatchForWindow::class);
        $this->assertNull($service->handle($this->window()));
        $register->update(['status' => RegisterStatusEnum::DELIVERED]);

        $this->assertNull($service->handle($this->window()));
        $this->assertDatabaseCount('payment_batch_items', 0);
        $this->assertDatabaseCount('payment_batch_runs', 1);
    }

    public function test_rerunning_a_window_does_not_duplicate_its_batch(): void
    {
        Register::factory()->create([
            'status' => RegisterStatusEnum::DELIVERED,
            'delivery_confirmed_at' => CarbonImmutable::parse('2026-08-08 12:00:00', 'America/Sao_Paulo'),
        ]);

        $service = app(CreatePaymentBatchForWindow::class);
        $first = $service->handle($this->window());
        $second = $service->handle($this->window());

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, PaymentBatch::query()->count());
        $this->assertSame(1, PaymentBatchRun::query()->count());
    }

    public function test_it_includes_an_older_deferred_register_and_clears_the_defer_marker(): void
    {
        $deferred = Register::factory()->create([
            'company' => 'copart',
            'status' => RegisterStatusEnum::DELIVERED,
            'delivery_confirmed_at' => CarbonImmutable::parse('2026-07-01 12:00:00', 'America/Sao_Paulo'),
            'payment_deferred_at' => CarbonImmutable::parse('2026-08-20 12:00:00', 'America/Sao_Paulo'),
        ]);

        $batch = app(CreatePaymentBatchForWindow::class)->handle($this->window());

        $this->assertNotNull($batch);
        $this->assertDatabaseHas('payment_batch_items', [
            'payment_batch_id' => $batch->id,
            'register_id' => $deferred->id,
        ]);
        $this->assertNull($deferred->refresh()->payment_deferred_at);
    }

    private function window(): PaymentBatchWindow
    {
        return PaymentBatchWindow::forGenerationFriday(CarbonImmutable::parse('2026-08-21 07:00:00', 'America/Sao_Paulo'));
    }
}
