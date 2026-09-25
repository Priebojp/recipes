<?php

namespace App\Services\Selection;

/**
 * Pure orchestration: hard filters first, then weights. No database, no AI.
 */
class SelectionEngine
{
    public function __construct(
        private SelectionConfig $config,
        private ?CandidateFilter $filter = null,
        private ?CandidateScorer $scorer = null,
    ) {
        $this->filter ??= new CandidateFilter;
        $this->scorer ??= new CandidateScorer($config);
    }

    /**
     * @param  iterable<RecipeCandidate>  $candidates
     */
    public function run(iterable $candidates, SelectionInput $input): SelectionResult
    {
        $scored = [];
        $excluded = [];

        foreach ($candidates as $candidate) {
            $exclusion = $this->filter->check($candidate, $input);
            if ($exclusion !== null) {
                if ($exclusion->rule !== 'archived') {
                    $excluded[] = $exclusion;
                }

                continue;
            }

            $scored[] = $this->scorer->score($candidate, $input);
        }

        return new SelectionResult($scored, $excluded, $this->config->version);
    }
}
