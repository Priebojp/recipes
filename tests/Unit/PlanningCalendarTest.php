<?php

use App\Services\PlanningCalendar;
use Carbon\CarbonImmutable;

it('computes next week as the next calendar week on Sunday and on Monday', function (string $now, string $expectedNextWeek) {
    CarbonImmutable::setTestNow(CarbonImmutable::parse($now, 'Europe/Bratislava'));
    $calendar = new PlanningCalendar('Europe/Bratislava');

    expect($calendar->nextWeekStart()->toDateString())->toBe($expectedNextWeek);

    CarbonImmutable::setTestNow();
})->with([
    ['2026-09-27 23:30', '2026-09-28'], // Sunday -> tomorrow's Monday
    ['2026-09-28 00:10', '2026-10-05'], // Monday -> the following Monday
    ['2026-09-30 12:00', '2026-10-05'],
]);

it('uses the household timezone for today and tomorrow', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-25 23:30', 'UTC'));
    $calendar = new PlanningCalendar('Europe/Bratislava');

    expect($calendar->today()->toDateString())->toBe('2026-09-26')
        ->and($calendar->tomorrow()->toDateString())->toBe('2026-09-27');

    CarbonImmutable::setTestNow();
});
