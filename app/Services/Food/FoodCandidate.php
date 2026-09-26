<?php

namespace App\Services\Food;

use App\Enums\FoodGramsOrigin;
use App\Enums\FoodPreparationState;
use App\Models\FoodSourceRecord;
use App\Models\FoodUnitConversion;

/**
 * One food the matcher offers for an ingredient line, with the grams the line resolves to for that food – or the
 * reason it does not.
 */
final readonly class FoodCandidate
{
    public function __construct(
        public FoodSourceRecord $record,
        public FoodPreparationState $preparationState,
        public ?float $grams,
        public ?FoodGramsOrigin $gramsOrigin,
        public ?FoodUnitConversion $conversion,
        public ?string $unresolvedReason,
        public ?string $matchedAlias,
        public int $score,
    ) {}

    public function isResolved(): bool
    {
        return $this->grams !== null;
    }
}
