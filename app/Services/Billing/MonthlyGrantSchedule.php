<?php

namespace App\Services\Billing;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Monthly intervals of a yearly plan (specification chapter 6): n calendar months from the anchor in the billing
 * time zone, clamped to the last day of the month (31 Jan → 28/29 Feb → 31 Mar), stored as UTC [start, end).
 * Never 30-day steps, never a drift to the 28th.
 */
final class MonthlyGrantSchedule
{
    /**
     * @return list<array{index: int, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public static function intervals(CarbonInterface $anchor, string $timezone, int $count = 12): array
    {
        $local = CarbonImmutable::instance($anchor)->setTimezone($timezone);
        $result = [];

        for ($n = 0; $n < $count; $n++) {
            $result[] = [
                'index' => $n,
                'start' => self::addMonthsClamped($local, $n)->utc(),
                'end' => self::addMonthsClamped($local, $n + 1)->utc(),
            ];
        }

        return $result;
    }

    /**
     * The interval containing the moment, or null when it is before the anchor or after the last interval.
     *
     * @return array{index: int, start: CarbonImmutable, end: CarbonImmutable}|null
     */
    public static function intervalAt(CarbonInterface $anchor, string $timezone, CarbonInterface $at, int $count = 12): ?array
    {
        foreach (self::intervals($anchor, $timezone, $count) as $interval) {
            if ($interval['start'] <= $at && $interval['end'] > $at) {
                return $interval;
            }
        }

        return null;
    }

    /**
     * Anchor day-of-month and wall-clock time, n months later, in the anchor's zone; day clamped to the month.
     */
    private static function addMonthsClamped(CarbonImmutable $local, int $months): CarbonImmutable
    {
        $firstOfTarget = $local->startOfMonth()->addMonthsNoOverflow($months);
        $day = min($local->day, $firstOfTarget->daysInMonth);

        return $firstOfTarget->setDay($day)->setTime($local->hour, $local->minute, $local->second);
    }
}
