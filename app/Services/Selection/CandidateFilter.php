<?php

namespace App\Services\Selection;

use Carbon\CarbonImmutable;

/**
 * Hard filters (chapter 7.2). Returns null when the recipe passes, otherwise the exclusion.
 */
class CandidateFilter
{
    public function __construct(private HistoryAnalyzer $history = new HistoryAnalyzer) {}

    public function check(RecipeCandidate $c, SelectionInput $in): ?ExcludedCandidate
    {
        if ($c->archived) {
            return $this->excluded($c, 'archived', 'Recept je archivovaný', true);
        }

        foreach ($in->personIds as $personId) {
            if (in_array($personId, $c->excludedFor, true)) {
                return $this->excluded($c, 'exclusion', 'Neponúkať: '.$in->nameOf($personId), true);
            }
        }

        if ($in->mealType !== null) {
            if ($c->mealTypes === []) {
                if (! $in->filters->includeUntyped) {
                    return $this->excluded($c, 'untyped', 'Typ jedla nevyplnený', false);
                }
            } elseif (! in_array($in->mealType->value, $c->mealTypes, true)) {
                return $this->excluded($c, 'meal_type', 'Iný typ jedla', false);
            }
        }

        foreach ($in->personIds as $personId) {
            $pref = $c->preferenceOf($personId);

            if ($pref === 'dislikes' && ! $in->filters->allowDisliked) {
                return $this->excluded($c, 'dislikes', 'Nemá rád: '.$in->nameOf($personId), false);
            }

            if ($in->filters->onlyFavoritesOfAll && $pref !== 'favorite') {
                return $this->excluded($c, 'not_favorite', 'Nie je obľúbené u: '.$in->nameOf($personId), false);
            }
        }

        if ($in->filters->maxMinutes !== null) {
            if ($c->totalMinutes === null) {
                if (! $in->filters->includeUnknownTime) {
                    return $this->excluded($c, 'unknown_time', 'Neznámy čas prípravy', false);
                }
            } elseif ($c->totalMinutes > $in->filters->maxMinutes) {
                return $this->excluded($c, 'too_long', 'Trvá dlhšie ako '.$in->filters->maxMinutes.' min', false);
            }
        }

        if ($in->filters->noRepeatDays !== null) {
            $last = $this->history->lastRelevantCooking($c, $in->personIds, $in->referenceDay);

            if ($last !== null && $this->daysBetween($last, $in->referenceDay) < $in->filters->noRepeatDays) {
                return $this->excluded($c, 'no_repeat', 'Varilo sa za posledných '.$in->filters->noRepeatDays.' dní', false);
            }
        }

        return null;
    }

    private function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) $from->startOfDay()->diffInDays($to->startOfDay(), false);
    }

    private function excluded(RecipeCandidate $c, string $rule, string $reason, bool $hard): ExcludedCandidate
    {
        return new ExcludedCandidate($c->id, $c->title, $rule, $reason, $hard);
    }
}
