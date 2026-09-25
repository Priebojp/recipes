<?php

use App\Services\Selection\CandidateScorer;
use App\Services\Selection\RecipeCandidate;
use App\Services\Selection\SelectionConfig;
use App\Services\Selection\SelectionFilters;
use App\Services\Selection\SelectionInput;
use Carbon\CarbonImmutable;

function selectionConfig(): SelectionConfig
{
    $config = require __DIR__.'/../../../config/recipes.php';

    return SelectionConfig::fromConfig($config['selection']);
}

function selectionInput(array $personIds = [1, 2], string $day = '2026-09-25', array $names = [1 => 'Peter', 2 => 'Eva', 3 => 'Hosť']): SelectionInput
{
    return new SelectionInput($personIds, $names, null, CarbonImmutable::parse($day), new SelectionFilters);
}

function candidate(array $overrides = []): RecipeCandidate
{
    return new RecipeCandidate(...array_merge([
        'id' => 1, 'title' => 'Test', 'mealTypes' => [], 'totalMinutes' => null, 'archived' => false,
    ], $overrides));
}

it('computes the group score as 0.6 × min + 0.4 × avg', function () {
    $scorer = new CandidateScorer(selectionConfig());

    $scored = $scorer->score(candidate(['preferences' => [1 => 'favorite', 2 => 'eats']]), selectionInput());

    // min 1.0, avg 1.5 -> 0.6 + 0.6 = 1.2
    expect($scored->factors['G'])->toBe(1.2)
        ->and($scored->weight)->toBe(1.2)
        ->and($scored->reasons)->toContain('Obľúbené pre 1 z 2');
});

it('treats unrated as neutral and names the unknown person', function () {
    $scored = (new CandidateScorer(selectionConfig()))->score(candidate(['preferences' => [1 => 'favorite']]), selectionInput());

    expect($scored->factors['G'])->toBe(round(0.6 * 0.9 + 0.4 * 1.45, 4))
        ->and($scored->reasons)->toContain('U Eva zatiaľ nepoznáme hodnotenie');
});

it('applies every recency boundary of the last cooking', function (int $daysAgo, float $expected) {
    $t = CarbonImmutable::parse('2026-09-25');
    $c = candidate(['cookings' => [['cooked_on' => $t->subDays($daysAgo)->toDateString(), 'person_ids' => [1]]]]);

    $scored = (new CandidateScorer(selectionConfig()))->score($c, selectionInput([1]));

    expect($scored->factors['R'])->toBe($expected);
})->with([
    [0, 0.15], [2, 0.15], [3, 0.35], [6, 0.35], [7, 0.65], [13, 0.65], [14, 0.85], [27, 0.85], [28, 1.0], [60, 1.0],
]);

it('reduces the weight by frequency within the last 28 days including T', function () {
    $t = CarbonImmutable::parse('2026-09-25');
    $c = candidate(['cookings' => [
        ['cooked_on' => $t->toDateString(), 'person_ids' => [1]],
        ['cooked_on' => $t->subDays(27)->toDateString(), 'person_ids' => [1]], // inside window (28 days incl. T)
        ['cooked_on' => $t->subDays(28)->toDateString(), 'person_ids' => [1]], // outside
    ]]);

    $scored = (new CandidateScorer(selectionConfig()))->score($c, selectionInput([1]));

    expect($scored->factors['F'])->toBe(round(1 / (1 + 0.2 * 2), 4));
});

it('gives a recently cooked meal a lower but positive weight than one not cooked for 30 days', function () {
    $t = CarbonImmutable::parse('2026-09-25');
    $scorer = new CandidateScorer(selectionConfig());
    $yesterday = $scorer->score(candidate(['id' => 1, 'cookings' => [['cooked_on' => $t->subDay()->toDateString(), 'person_ids' => []]]]), selectionInput());
    $old = $scorer->score(candidate(['id' => 2, 'cookings' => [['cooked_on' => $t->subDays(30)->toDateString(), 'person_ids' => []]]]), selectionInput());

    expect($yesterday->weight)->toBeGreaterThan(0)->toBeLessThan($old->weight);
});

it('penalises meals cooked only for other diners more weakly', function () {
    $t = CarbonImmutable::parse('2026-09-25');
    $c = candidate(['cookings' => [['cooked_on' => $t->subDay()->toDateString(), 'person_ids' => [3]]]]);
    $scorer = new CandidateScorer(selectionConfig());

    $forFamily = $scorer->score($c, selectionInput([1, 2]));
    $forGuest = $scorer->score($c, selectionInput([3]));

    // family: R=1, F=1, H_other = 0.7 + 0.3 * (0.15 * 1/1.2)
    expect($forFamily->factors['H'])->toBe(round(0.7 + 0.3 * (0.15 / 1.2), 4))
        ->and($forGuest->factors['H'])->toBe(round(0.15 / 1.2, 4))
        ->and($forGuest->weight)->toBeLessThan($forFamily->weight);
});

it('ignores cookings after the reference day', function () {
    $c = candidate(['cookings' => [['cooked_on' => '2026-09-26', 'person_ids' => [1]]]]);

    $scored = (new CandidateScorer(selectionConfig()))->score($c, selectionInput([1]));

    expect($scored->factors['R'])->toBe(1.0)->and($scored->factors['days_since_cooked'])->toBeNull();
});

it('applies the planned factor within T ± 3 days, for week plans and never for someday', function () {
    $scorer = new CandidateScorer(selectionConfig());
    $in = selectionInput([1]);

    $near = $scorer->score(candidate(['plans' => [['mode' => 'date', 'scheduled_date' => '2026-09-28', 'week_start_date' => null, 'person_ids' => [1]]]]), $in);
    $far = $scorer->score(candidate(['plans' => [['mode' => 'date', 'scheduled_date' => '2026-09-29', 'week_start_date' => null, 'person_ids' => [1]]]]), $in);
    $otherPeople = $scorer->score(candidate(['plans' => [['mode' => 'date', 'scheduled_date' => '2026-09-25', 'week_start_date' => null, 'person_ids' => [2]]]]), $in);
    $week = $scorer->score(candidate(['plans' => [['mode' => 'week', 'scheduled_date' => null, 'week_start_date' => '2026-09-28', 'person_ids' => []]]]), $in);
    $someday = $scorer->score(candidate(['plans' => [['mode' => 'someday', 'scheduled_date' => null, 'week_start_date' => null, 'person_ids' => [1]]]]), $in);
    $twoCollisions = $scorer->score(candidate(['plans' => [
        ['mode' => 'date', 'scheduled_date' => '2026-09-25', 'week_start_date' => null, 'person_ids' => [1]],
        ['mode' => 'date', 'scheduled_date' => '2026-09-26', 'week_start_date' => null, 'person_ids' => [1]],
    ]]), $in);

    expect($near->factors['P'])->toBe(0.4)
        ->and($far->factors['P'])->toBe(1.0)
        ->and($otherPeople->factors['P'])->toBe(1.0)
        ->and($week->factors['P'])->toBe(0.4)
        ->and($someday->factors['P'])->toBe(1.0)
        ->and($twoCollisions->factors['P'])->toBe(0.4);
});

it('never drops below the minimum weight', function () {
    $t = CarbonImmutable::parse('2026-09-25');
    $c = candidate([
        'preferences' => [1 => 'dislikes', 2 => 'dislikes'],
        'cookings' => array_map(fn ($d) => ['cooked_on' => $t->subDays($d)->toDateString(), 'person_ids' => [1, 2]], range(0, 20)),
        'plans' => [['mode' => 'date', 'scheduled_date' => '2026-09-25', 'week_start_date' => null, 'person_ids' => [1]]],
    ]);

    $scored = (new CandidateScorer(selectionConfig()))->score($c, selectionInput());

    expect($scored->weight)->toBe(0.02);
});
