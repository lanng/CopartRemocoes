<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class PaymentBatchWindow
{
    private const TIMEZONE = 'America/Sao_Paulo';

    private const GENERATION_TIME = '07:00';

    public function __construct(public CarbonImmutable $start, public CarbonImmutable $end) {}

    public static function forGenerationFriday(CarbonImmutable $generationFriday): self
    {
        $friday = $generationFriday->setTimezone(self::TIMEZONE);

        return new self(
            $friday->subDays(17)->startOfDay(),
            $friday->subDays(11)->endOfDay(),
        );
    }

    public static function isDue(CarbonImmutable $date): bool
    {
        $date = $date->setTimezone(self::TIMEZONE);

        return $date->isFriday() && $date->format('H:i') >= self::GENERATION_TIME;
    }

    /** @return list<self> */
    public static function dueWindowsThrough(CarbonImmutable $through, CarbonImmutable $startDate): array
    {
        $through = $through->setTimezone(self::TIMEZONE);
        $startDate = $startDate->setTimezone(self::TIMEZONE);
        $cursor = $startDate->next(CarbonImmutable::FRIDAY)->setTime(7, 0);
        $windows = [];

        while ($cursor->lessThanOrEqualTo($through)) {
            if (self::isDue($cursor)) {
                $window = self::forGenerationFriday($cursor);

                if ($window->start->greaterThanOrEqualTo($startDate->startOfDay())) {
                    $windows[] = $window;
                }
            }

            $cursor = $cursor->addWeek();
        }

        return $windows;
    }

    public static function fromConfig(): CarbonImmutable
    {
        $startDate = config('payment_batches.start_date');

        if (! is_string($startDate) || $startDate === '') {
            throw new InvalidArgumentException('PAYMENT_BATCH_START_DATE não está configurado.');
        }

        return CarbonImmutable::parse($startDate, config('payment_batches.timezone'));
    }
}
