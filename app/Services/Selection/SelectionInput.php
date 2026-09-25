<?php

namespace App\Services\Selection;

use App\Enums\MealType;
use Carbon\CarbonImmutable;

final class SelectionInput
{
    /**
     * @param  list<int>  $personIds
     * @param  array<int, string>  $personNames
     */
    public function __construct(
        public readonly array $personIds,
        public readonly array $personNames,
        public readonly ?MealType $mealType,
        public readonly CarbonImmutable $referenceDay,
        public readonly SelectionFilters $filters,
        public readonly ?CarbonImmutable $weekStart = null,
    ) {}

    public function nameOf(int $personId): string
    {
        return $this->personNames[$personId] ?? 'stravník';
    }
}
