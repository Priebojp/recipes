<?php

namespace App\Services\Selection;

use Carbon\CarbonImmutable;

/**
 * Pure helpers over the cooking history of one candidate.
 */
class HistoryAnalyzer
{
    /**
     * Cookings on or before T whose participants overlap the selected diners (or were recorded for the whole household).
     *
     * @param  list<int>  $personIds
     * @return list<CarbonImmutable>
     */
    public function relevantCookings(RecipeCandidate $c, array $personIds, CarbonImmutable $t): array
    {
        return $this->cookingsWhere($c, $t, fn (array $eventPeople) => $eventPeople === [] || array_intersect($eventPeople, $personIds) !== []);
    }

    /**
     * Cookings for other diners of the same household only (no overlap, explicit participants).
     *
     * @param  list<int>  $personIds
     * @return list<CarbonImmutable>
     */
    public function otherCookings(RecipeCandidate $c, array $personIds, CarbonImmutable $t): array
    {
        return $this->cookingsWhere($c, $t, fn (array $eventPeople) => $eventPeople !== [] && array_intersect($eventPeople, $personIds) === []);
    }

    /** @param  list<int>  $personIds */
    public function lastRelevantCooking(RecipeCandidate $c, array $personIds, CarbonImmutable $t): ?CarbonImmutable
    {
        return self::latest($this->relevantCookings($c, $personIds, $t));
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    public static function latest(array $dates): ?CarbonImmutable
    {
        $latest = null;
        foreach ($dates as $date) {
            if ($latest === null || $date->greaterThan($latest)) {
                $latest = $date;
            }
        }

        return $latest;
    }

    /**
     * @param  list<CarbonImmutable>  $dates
     */
    public static function countWithinWindow(array $dates, CarbonImmutable $t, int $windowDays): int
    {
        $from = $t->startOfDay()->subDays($windowDays - 1);
        $count = 0;
        foreach ($dates as $date) {
            if ($date->greaterThanOrEqualTo($from) && $date->lessThanOrEqualTo($t->startOfDay())) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  callable(list<int>): bool  $matcher
     * @return list<CarbonImmutable>
     */
    private function cookingsWhere(RecipeCandidate $c, CarbonImmutable $t, callable $matcher): array
    {
        $result = [];
        foreach ($c->cookings as $cooking) {
            $date = CarbonImmutable::parse($cooking['cooked_on'], $t->getTimezone())->startOfDay();
            if ($date->greaterThan($t->startOfDay())) {
                continue;
            }
            if ($matcher($cooking['person_ids'])) {
                $result[] = $date;
            }
        }

        return $result;
    }
}
