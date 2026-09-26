<?php

namespace App\Services\Food;

/**
 * What the matcher found for one ingredient line: dictionary candidates (best first) and, when asked, provider
 * search hits that are not in the catalogue yet.
 */
final readonly class MatchProposal
{
    /**
     * @param  list<FoodCandidate>  $candidates
     * @param  list<FoodSearchHit>  $sourceHits
     */
    public function __construct(
        public array $candidates,
        public array $sourceHits = [],
        public ?string $sourceError = null,
    ) {}

    public function best(): ?FoodCandidate
    {
        return $this->candidates[0] ?? null;
    }
}
