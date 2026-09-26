<?php

use App\Services\Billing\MonthlyGrantSchedule;
use Carbon\CarbonImmutable;

const BILLING_TZ = 'Europe/Bratislava';

it('clamps a 31 January anchor to the end of February and returns to the 31st in March', function (int $year, string $februaryEnd) {
    $anchor = CarbonImmutable::parse("{$year}-01-31 10:00", BILLING_TZ);

    $intervals = MonthlyGrantSchedule::intervals($anchor, BILLING_TZ, 4);
    $starts = array_map(fn ($i) => $i['start']->setTimezone(BILLING_TZ)->format('Y-m-d H:i'), $intervals);

    expect($starts)->toBe(["{$year}-01-31 10:00", "{$year}-{$februaryEnd} 10:00", "{$year}-03-31 10:00", "{$year}-04-30 10:00"])
        ->and($intervals[1]['end']->setTimezone(BILLING_TZ)->format('Y-m-d H:i'))->toBe("{$year}-03-31 10:00");
})->with([
    'common year' => [2027, '02-28'],
    'leap year' => [2028, '02-29'],
]);

it('keeps the local wall-clock time across a daylight-saving change', function () {
    $anchor = CarbonImmutable::parse('2027-03-15 01:30', BILLING_TZ); // UTC+1

    $april = MonthlyGrantSchedule::intervals($anchor, BILLING_TZ, 2)[1]['start']; // UTC+2

    expect($april->setTimezone(BILLING_TZ)->format('Y-m-d H:i'))->toBe('2027-04-15 01:30')
        ->and($april->utc()->format('H:i'))->toBe('23:30')
        ->and($anchor->utc()->format('H:i'))->toBe('00:30');
});

it('treats the interval end as exclusive and finds no interval outside the paid year', function () {
    $anchor = CarbonImmutable::parse('2027-05-10 12:00:00', BILLING_TZ);
    $end = CarbonImmutable::parse('2027-06-10 12:00:00', BILLING_TZ);

    expect(MonthlyGrantSchedule::intervalAt($anchor, BILLING_TZ, $end->subSecond())['index'])->toBe(0)
        ->and(MonthlyGrantSchedule::intervalAt($anchor, BILLING_TZ, $end)['index'])->toBe(1)
        ->and(MonthlyGrantSchedule::intervalAt($anchor, BILLING_TZ, $anchor->subSecond()))->toBeNull()
        ->and(MonthlyGrantSchedule::intervalAt($anchor, BILLING_TZ, $anchor->addYear()))->toBeNull()
        ->and(count(MonthlyGrantSchedule::intervals($anchor, BILLING_TZ)))->toBe(12)
        ->and(MonthlyGrantSchedule::intervals($anchor, BILLING_TZ)[11]['end']->setTimezone(BILLING_TZ)->format('Y-m-d H:i'))->toBe('2028-05-10 12:00');
});
