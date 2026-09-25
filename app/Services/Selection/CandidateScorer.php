<?php

namespace App\Services\Selection;

use Carbon\CarbonImmutable;

/**
 * Computes W(r) = max(min, G × H × P) with a human readable explanation (chapter 7.3–7.6).
 */
class CandidateScorer
{
    public function __construct(
        private SelectionConfig $config,
        private HistoryAnalyzer $history = new HistoryAnalyzer,
    ) {}

    public function score(RecipeCandidate $c, SelectionInput $in): ScoredCandidate
    {
        $reasons = [];

        // G – group taste
        $scores = [];
        $favorites = 0;
        $unrated = [];
        foreach ($in->personIds as $personId) {
            $pref = $c->preferenceOf($personId);
            $scores[] = $this->config->scoreFor($pref);
            if ($pref === 'favorite') {
                $favorites++;
            }
            if ($pref === null) {
                $unrated[] = $in->nameOf($personId);
            }
        }

        $g = $scores === []
            ? $this->config->scoreFor(null)
            : $this->config->groupMinWeight * min($scores) + $this->config->groupAvgWeight * (array_sum($scores) / count($scores));

        $n = count($in->personIds);
        if ($favorites > 0) {
            $reasons[] = $n === 1 ? 'Obľúbené' : "Obľúbené pre {$favorites} z {$n}";
        }
        if ($unrated !== [] && $n > 0) {
            $reasons[] = count($unrated) === 1
                ? 'U '.$unrated[0].' zatiaľ nepoznáme hodnotenie'
                : 'U '.count($unrated).' ľudí zatiaľ nepoznáme hodnotenie';
        }

        // H – real cooking history
        $t = $in->referenceDay;
        $relevant = $this->history->relevantCookings($c, $in->personIds, $t);
        $last = HistoryAnalyzer::latest($relevant);
        $days = $last === null ? null : (int) $last->diffInDays($t->startOfDay(), false);
        $r = $this->config->recencyFactor($days);
        $f = 1 / (1 + $this->config->frequencyCoefficient * HistoryAnalyzer::countWithinWindow($relevant, $t, $this->config->frequencyWindowDays));

        $other = $this->history->otherCookings($c, $in->personIds, $t);
        $hOther = 1.0;
        if ($other !== []) {
            $lastOther = HistoryAnalyzer::latest($other);
            $daysOther = $lastOther === null ? null : (int) $lastOther->diffInDays($t->startOfDay(), false);
            $rOther = $this->config->recencyFactor($daysOther);
            $fOther = 1 / (1 + $this->config->frequencyCoefficient * HistoryAnalyzer::countWithinWindow($other, $t, $this->config->frequencyWindowDays));
            $hOther = $this->config->otherGroupBase + $this->config->otherGroupSpan * ($rOther * $fOther);
        }

        $h = $r * $f * $hOther;

        if ($days === null) {
            $reasons[] = $other === [] ? 'Ešte sa nevarilo' : 'Pre týchto ľudí sa ešte nevarilo';
        } elseif ($days === 0) {
            $reasons[] = 'Varilo sa dnes';
        } else {
            $reasons[] = $days === 1 ? '1 deň sa nevarilo' : "{$days} dní sa nevarilo";
        }

        // P – already planned nearby
        $p = $this->plannedFactor($c, $in) ? $this->config->plannedFactor : 1.0;
        if ($p < 1.0) {
            $reasons[] = 'Už je v pláne na blízke dni';
        }

        $w = max($this->config->minWeight, $g * $h * $p);

        return new ScoredCandidate($c->id, $c->title, round($w, 6), $reasons, [
            'G' => round($g, 4),
            'R' => $r,
            'F' => round($f, 4),
            'H_other' => round($hOther, 4),
            'H' => round($h, 4),
            'P' => $p,
            'days_since_cooked' => $days,
        ]);
    }

    /**
     * True when the same recipe is planned for at least one selected diner within T ± window days.
     */
    private function plannedFactor(RecipeCandidate $c, SelectionInput $in): bool
    {
        $t = $in->referenceDay->startOfDay();
        $from = $t->subDays($this->config->plannedWindowDays);
        $to = $t->addDays($this->config->plannedWindowDays);

        foreach ($c->plans as $plan) {
            $people = $plan['person_ids'];
            if ($people !== [] && array_intersect($people, $in->personIds) === []) {
                continue;
            }

            if ($plan['mode'] === 'date' && $plan['scheduled_date'] !== null) {
                $date = CarbonImmutable::parse($plan['scheduled_date'], $t->getTimezone())->startOfDay();
                if ($date->betweenIncluded($from, $to)) {
                    return true;
                }
            } elseif ($plan['mode'] === 'week' && $plan['week_start_date'] !== null) {
                $weekStart = CarbonImmutable::parse($plan['week_start_date'], $t->getTimezone())->startOfDay();
                $weekEnd = $weekStart->addDays(6);
                if ($weekStart->lessThanOrEqualTo($to) && $weekEnd->greaterThanOrEqualTo($from)) {
                    return true;
                }
            }
        }

        return false;
    }
}
