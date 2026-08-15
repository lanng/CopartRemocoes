<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\PaymentBatchWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class PaymentBatchWindowTest extends TestCase
{
    public function test_friday_window_covers_the_previous_monday_through_sunday(): void
    {
        $window = PaymentBatchWindow::forGenerationFriday(
            CarbonImmutable::parse('2026-08-21 07:00:00', 'America/Sao_Paulo'),
        );

        $this->assertSame('2026-08-04', $window->start->toDateString());
        $this->assertSame('2026-08-10', $window->end->toDateString());
    }

    public function test_friday_before_generation_time_is_not_due(): void
    {
        $this->assertFalse(PaymentBatchWindow::isDue(
            CarbonImmutable::parse('2026-08-21 06:59:00', 'America/Sao_Paulo'),
        ));
        $this->assertTrue(PaymentBatchWindow::isDue(
            CarbonImmutable::parse('2026-08-21 07:00:00', 'America/Sao_Paulo'),
        ));
    }

    public function test_due_windows_are_enumerated_from_the_activation_date(): void
    {
        $windows = PaymentBatchWindow::dueWindowsThrough(
            CarbonImmutable::parse('2026-08-28 07:00:00', 'America/Sao_Paulo'),
            CarbonImmutable::parse('2026-08-01 00:00:00', 'America/Sao_Paulo'),
        );

        $this->assertSame([
            '2026-08-04/2026-08-10',
            '2026-08-11/2026-08-17',
        ], array_map(fn (PaymentBatchWindow $window): string => "{$window->start->toDateString()}/{$window->end->toDateString()}", $windows));
    }
}
