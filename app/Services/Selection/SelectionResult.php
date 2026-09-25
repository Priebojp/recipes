<?php

namespace App\Services\Selection;

final class SelectionResult
{
    /**
     * @param  list<ScoredCandidate>  $scored
     * @param  list<ExcludedCandidate>  $excluded
     */
    public function __construct(
        public readonly array $scored,
        public readonly array $excluded,
        public readonly int $configVersion,
    ) {}

    /** @return array<int, float> */
    public function weights(): array
    {
        $weights = [];
        foreach ($this->scored as $candidate) {
            $weights[$candidate->id] = $candidate->weight;
        }

        return $weights;
    }

    /**
     * Counts of soft exclusions grouped by rule – used by the empty state to offer targeted relaxations.
     *
     * @return array<string, int>
     */
    public function softExclusionCounts(): array
    {
        $counts = [];
        foreach ($this->excluded as $e) {
            if (! $e->hard) {
                $counts[$e->rule] = ($counts[$e->rule] ?? 0) + 1;
            }
        }

        return $counts;
    }
}
