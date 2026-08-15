<?php

namespace Tests\Feature\Services;

use App\Enums\CteDocumentStatusEnum;
use App\Enums\RegisterStatusEnum;
use App\Models\CteDocument;
use App\Models\PaymentBatch;
use App\Models\PaymentBatchRun;
use App\Models\Register;
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

    private function window(): PaymentBatchWindow
    {
        return PaymentBatchWindow::forGenerationFriday(CarbonImmutable::parse('2026-08-21 07:00:00', 'America/Sao_Paulo'));
    }
}
