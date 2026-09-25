<?php

use App\Enums\MealType;
use App\Services\Selection\CandidateFilter;
use App\Services\Selection\RecipeCandidate;
use App\Services\Selection\SelectionFilters;
use App\Services\Selection\SelectionInput;
use Carbon\CarbonImmutable;

function filterInput(array $personIds = [1, 2], ?MealType $mealType = null, array $filters = []): SelectionInput
{
    return new SelectionInput($personIds, [1 => 'Peter', 2 => 'Eva'], $mealType, CarbonImmutable::parse('2026-09-25'), SelectionFilters::fromArray($filters));
}

function filterCandidate(array $overrides = []): RecipeCandidate
{
    return new RecipeCandidate(...array_merge(['id' => 1, 'title' => 'T', 'mealTypes' => [], 'totalMinutes' => null, 'archived' => false], $overrides));
}

it('excludes archived recipes and hard exclusions regardless of relaxations', function () {
    $filter = new CandidateFilter;

    expect($filter->check(filterCandidate(['archived' => true]), filterInput())?->rule)->toBe('archived');

    $excluded = $filter->check(filterCandidate(['excludedFor' => [2]]), filterInput([1, 2], null, ['allow_disliked' => true, 'only_favorites_of_all' => false]));
    expect($excluded?->rule)->toBe('exclusion')->and($excluded?->hard)->toBeTrue()->and($excluded?->reason)->toBe('Neponúkať: Eva');
});

it('matches meal types and keeps untyped recipes by default', function () {
    $filter = new CandidateFilter;

    expect($filter->check(filterCandidate(['mealTypes' => ['dinner']]), filterInput([1], MealType::Lunch))?->rule)->toBe('meal_type')
        ->and($filter->check(filterCandidate(['mealTypes' => []]), filterInput([1], MealType::Lunch)))->toBeNull()
        ->and($filter->check(filterCandidate(['mealTypes' => []]), filterInput([1], MealType::Lunch, ['include_untyped' => false]))?->rule)->toBe('untyped')
        ->and($filter->check(filterCandidate(['mealTypes' => ['dinner']]), filterInput([1], null)))->toBeNull();
});

it('drops disliked meals unless explicitly allowed and supports favorites-of-all', function () {
    $filter = new CandidateFilter;
    $c = filterCandidate(['preferences' => [1 => 'favorite', 2 => 'dislikes']]);

    expect($filter->check($c, filterInput())?->rule)->toBe('dislikes')
        ->and($filter->check($c, filterInput([1, 2], null, ['allow_disliked' => true])))->toBeNull()
        ->and($filter->check(filterCandidate(['preferences' => [1 => 'favorite']]), filterInput([1, 2], null, ['only_favorites_of_all' => true]))?->rule)->toBe('not_favorite')
        ->and($filter->check(filterCandidate(['preferences' => [1 => 'favorite', 2 => 'favorite']]), filterInput([1, 2], null, ['only_favorites_of_all' => true])))->toBeNull();
});

it('handles the time limit with explicit unknown-time behaviour', function () {
    $filter = new CandidateFilter;

    expect($filter->check(filterCandidate(['totalMinutes' => null]), filterInput([1], null, ['max_minutes' => 30]))?->rule)->toBe('unknown_time')
        ->and($filter->check(filterCandidate(['totalMinutes' => null]), filterInput([1], null, ['max_minutes' => 30, 'include_unknown_time' => true])))->toBeNull()
        ->and($filter->check(filterCandidate(['totalMinutes' => 45]), filterInput([1], null, ['max_minutes' => 30]))?->rule)->toBe('too_long')
        ->and($filter->check(filterCandidate(['totalMinutes' => 30]), filterInput([1], null, ['max_minutes' => 30])))->toBeNull()
        ->and($filter->check(filterCandidate(['totalMinutes' => null]), filterInput([1])))->toBeNull();
});

it('applies the strict no-repeat filter only to relevant diners', function () {
    $filter = new CandidateFilter;
    $c = filterCandidate(['cookings' => [['cooked_on' => '2026-09-20', 'person_ids' => [1]]]]);

    expect($filter->check($c, filterInput([1], null, ['no_repeat_days' => 7]))?->rule)->toBe('no_repeat')
        ->and($filter->check($c, filterInput([1], null, ['no_repeat_days' => 5])))->toBeNull()
        ->and($filter->check($c, filterInput([2], null, ['no_repeat_days' => 7])))->toBeNull();
});
