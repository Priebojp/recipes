<?php

namespace App\Services\Selection;

/**
 * Plain data about one recipe, gathered from the database once, so filters and weights stay pure.
 */
final class RecipeCandidate
{
    /**
     * @param  list<string>  $mealTypes
     * @param  array<int, string>  $preferences  person id => favorite|eats|dislikes
     * @param  list<int>  $excludedFor  person ids with "do not offer"
     * @param  list<array{cooked_on: string, person_ids: list<int>}>  $cookings  active cooking events
     * @param  list<array{mode: string, scheduled_date: string|null, week_start_date: string|null, person_ids: list<int>}>  $plans  planned meal plans
     */
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly array $mealTypes,
        public readonly ?int $totalMinutes,
        public readonly bool $archived,
        public readonly array $preferences = [],
        public readonly array $excludedFor = [],
        public readonly array $cookings = [],
        public readonly array $plans = [],
    ) {}

    public function preferenceOf(int $personId): ?string
    {
        return $this->preferences[$personId] ?? null;
    }
}
