<?php

namespace Tests\Feature\Console;

use Tests\TestCase;

class PaymentBatchScheduleTest extends TestCase
{
    public function test_payment_batch_generation_runs_at_seven_am_sao_paulo_time(): void
    {
        $event = $this->scheduleEvent('payments:generate-pending-batches');

        $this->assertNotNull($event, 'O agendamento payments:generate-pending-batches não foi registrado.');

        $this->assertSame('0 7 * * *', $event->expression);
        $this->assertSame('America/Sao_Paulo', $event->timezone);
    }

    public function test_old_registers_cleanup_runs_at_nine_am_sao_paulo_time_and_logs_output(): void
    {
        $event = $this->scheduleEvent('app:cleanup-old-registers');

        $this->assertNotNull($event, 'O agendamento app:cleanup-old-registers não foi registrado.');

        $this->assertSame('0 9 * * *', $event->expression);
        $this->assertSame('America/Sao_Paulo', $event->timezone);
        $this->assertSame(storage_path('logs/schedule-cleanup-old-registers.log'), $event->output);
    }

    private function scheduleEvent(string $command): ?object
    {
        return collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, $command));
    }
}
