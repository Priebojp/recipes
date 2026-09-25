<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Household-local calendar rules: "tomorrow" is the next local calendar day and
 * "next week" is the next calendar week Monday–Sunday, not "+7 days".
 */
class PlanningCalendar
{
    public function __construct(private string $timezone) {}

    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone)->startOfDay();
    }

    public function tomorrow(): CarbonImmutable
    {
        return $this->today()->addDay();
    }

    public function weekStartOf(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->setTimezone($this->timezone)->startOfDay()->startOfWeek(CarbonInterface::MONDAY);
    }

    public function thisWeekStart(): CarbonImmutable
    {
        return $this->weekStartOf($this->today());
    }

    public function nextWeekStart(): CarbonImmutable
    {
        return $this->thisWeekStart()->addWeek();
    }

    public function localHour(): int
    {
        return (int) CarbonImmutable::now($this->timezone)->format('G');
    }

    /**
     * Parse a Y-m-d string as a local date.
     */
    public function date(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $value, $this->timezone)->startOfDay();
    }
}
