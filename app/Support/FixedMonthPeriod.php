<?php

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

final class FixedMonthPeriod
{
    public const DAYS_PER_MONTH = 30;

    public static function endDate(Carbon $startDate, int $durationMonths): Carbon
    {
        if ($durationMonths < 1) {
            throw new InvalidArgumentException('Duration months must be at least one.');
        }

        return $startDate->copy()->addDays($durationMonths * self::DAYS_PER_MONTH);
    }
}
