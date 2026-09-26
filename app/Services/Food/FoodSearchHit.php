<?php

namespace App\Services\Food;

/**
 * One result of a provider search: enough to show a person and to fetch the full record on confirmation.
 */
final readonly class FoodSearchHit
{
    public function __construct(
        public string $externalId,
        public string $name,
        public ?string $category = null,
        public ?string $dataType = null,
    ) {}
}
